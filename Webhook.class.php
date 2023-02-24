<?php
/**
 * This file contains the Stripe Webhook class.
 *
 * @author      Lee Garner <lee@leegarner.com>
 * @copyright   Copyright (c) 2019-2023 Lee Garner
 * @package     shop
 * @version     v1.5.0
 * @since       v0.7.1
 * @license     http://opensource.org/licenses/gpl-2.0.php
 *              GNU Public License v2 or later
 * @filesource
 */
namespace Shop\Gateways\stripe;
use Shop\Order;
use Shop\Gateway;
use Shop\Currency;
use Shop\Payment;
use Shop\Address;
use Shop\Models\OrderStatus;
use Shop\Models\CustomInfo;
use Shop\Log;
use Shop\Config;


// this file can't be used on its own
if (!defined ('GVERSION')) {
    die ('This file can not be used on its own.');
}

/**
 * Class to provide webhook for the Stripe payment processor.
 * @package shop
 */
class Webhook extends \Shop\Webhook
{
    /** Payment Intent object obtained from the ID in the Event object.
     * @var object */
    private $_payment;

    /**
     * Constructor.
     *
     * @param   array   $A  Payload provided by Stripe
     */
    function __construct($A=array())
    {
        global $_USER, $_CONF;

        $this->setSource('stripe');
        // Instantiate the gateway to load the needed API key.
        $this->GW = Gateway::getInstance('stripe');
    }


    /**
     * Verify the transaction.
     * This just checks that a valid cart_id was received along with other
     * variables.
     *
     * @return  boolean         true if successfully validated, false otherwise
     */
    public function Verify() : bool
    {
        $event = NULL;
        if (isset($_POST['vars'])) {
            $payload = base64_decode($_POST['vars']);
            $sig_header = $_POST['HTTP_STRIPE_SIGNATURE'];
        } elseif (isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
            $payload = @file_get_contents('php://input');
            $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        } else {
            $payload = '';
            $sig_header = 'invalid';
        }

        Log::info('Received Stripe Webhook');
        Log::debug('Received Stripe Webhook: ' . var_export($payload, true));
        Log::debug('Sig Key: ' . var_export($sig_header, true));
        $this->blob = $payload;

        if ($sig_header == 'invalid') {
            return false;
        }

        // If testing, just pretend it's valid
        if (Config::get('sys_test_ipn') && isset($_GET['testhook'])) {
            $event = json_decode($payload);
            $this->setData($event);
            $this->setEvent($this->getData()->type);
            $this->setVerified(true);
            $this->setID($this->getData()->id);
            return true;
        }

        if ($event === NULL) {  // to skip test data from $_POST
            require_once __DIR__ . '/vendor/autoload.php';
            try {
                \Stripe\Stripe::setApiKey($this->GW->getSecretKey());
                $event = \Stripe\Webhook::constructEvent(
                    $payload, $sig_header, $this->GW->getWebhookSecret()
                );
            } catch(\UnexpectedValueException $e) {
                // Invalid payload
                Log::error("Unexpected Value received from Stripe");
                return false;
                //http_response_code(400); // PHP 5.4 or greater
                //exit;
            } catch(\Stripe\Error\SignatureVerification $e) {
                // Invalid signature
                Log::error("Invalid Stripe signature received");
                return false;
                //http_response_code(400); // PHP 5.4 or greater
                //exit;
            } catch (\Exception $e) {
                Log::error(__METHOD__ . ': ' . $e->getMessage());
                return false;
            }
        }
        Log::debug(__METHOD__ . ':' . var_export($event,true));
        if (empty($event)) {
            Log::error(__METHOD__ . ': Unable to create Stripe webhook event');
            return false;
        }
        $this->setData($event);
        $this->setEvent($this->getData()->type);
        $this->setID($this->getData()->id);
        $this->setVerified(true);
        Log::info("Stripe webhook verified OK");
        return true;
    }


    /**
     * Perform the necessary actions based on the webhook.
     * At this point all required objects should be valid.
     *
     * @return  boolean     True on success, False on error
     */
    public function Dispatch() : bool
    {
        $retval = false;        // be pessimistic

        switch ($this->getEvent()) {
        case 'invoice.created':
        case 'invoice.finalized':
            // Invoice was created. As a net-terms customer, the order
            // can be processed.
            if (!isset($this->getData()->data->object->metadata->order_id)) {
                Log::error("Order ID not found in invoice metadata");
                return false;
            }
            $this->setOrderID($this->getData()->data->object->metadata->order_id);
            $this->Order = Order::getInstance($this->getOrderID());
            if ($this->Order->isNew()) {
                Log::error("Invalid Order ID received in webhook");
                return false;
            }
            $this->Order->setGatewayRef($this->getData()->data->object->id)
                        ->setInfo('terms_gw', $this->GW->getName())
                        ->Save();
            if (OrderStatus::checkOrderValid($this->Order->getStatus())) {
                $this->setStatusMsg("Duplicate message for order " . $this->Order->getOrderId());
                Log::error("Order " . $this->Order->getOrderId() . " was already invoiced and processed");
            }
            $this->logIPN();

            // Invoice created successfully
            $retval = $this->handlePurchase($this->Order);
            break;
        case 'invoice.payment_succeeded':
        case 'invoice.paid':
            // Invoice payment notification
            if (!isset($this->getData()->data->object->metadata->order_id)) {
                Log::error("Order ID not found in invoice metadata");
                return false;
            }

            if (!isset($this->getData()->data->object->payment_intent)) {
                Log::error("Payment Intent value not include in webhook");
                return false;
            }
            $Payment = $this->getData()->data->object;
            if (!$this->isUniqueTxnId()) {
                // Duplicate transaction, not an error.
                return true;
            }

            $this->setOrderID($this->getData()->data->object->metadata->order_id);
            $this->Order = Order::getInstance($this->getOrderID());
            if ($this->Order->isNew()) {
                Log::error("Invalid Order ID received in webhook");
                return false;
            }
            $amt_paid = $Payment->amount_paid;
            if ($amt_paid > 0) {
                $this->setRefID($Payment->payment_intent);
                $LogID = $this->logIPN();
                $currency = $Payment->currency;
                $this_pmt = Currency::getInstance($currency)->fromInt($amt_paid);
                $Pmt = Payment::getByReference($this->getRefID());
                if ($Pmt->getPmtID() == 0) {
                    $Pmt->setRefID($Payment->payment_intent)
                        ->setAmount($this_pmt)
                        ->setGateway($this->getSource())
                        ->setMethod($this->GW->getDscp())
                        ->setComment('Webhook ' . $this->getID())
                        ->setComplete(1)
                        ->setStatus($this->getData()->type)
                        ->setOrderID($this->getOrderID());
                    $retval = $Pmt->Save();
                }
            }
            break;
        case 'checkout.session.completed':
            // Immediate checkout notification
            if (!isset($this->getData()->data->object->client_reference_id)) {
                Log::error("Order ID not found in invoice metadata");
                return true;
            }
            if (!isset($this->getData()->data->object->payment_intent)) {
                Log::error("Payment Intent value not include in webhook");
                return true;
            }

            $Payment = $this->getData()->data->object;
            if (!$this->isUniqueTxnId()) {
                $this->setStatusMsg('Duplicate payment message');
                return true;
            }

            $this->setOrderID($this->getData()->data->object->client_reference_id);
            $this->Order = Order::getInstance($this->getOrderID());
            if ($this->Order->isNew()) {
                Log::error("Invalid Order ID received in webhook");
                return true;
            }
            $amt_paid = $Payment->amount_total;

            // Set the billing and shipping address to at least get the name,
            // if not already set.
            if (isset($Payment->customer_details)) {
                $arr = $Payment->customer_details;
                $Address = new Address;
                $Address->fromArray(array(
                    'id' => -1,
                    'name' => $arr->name,
                    'phone' => $arr->phone,
                    'address1' => $arr->address->line1,
                    'address2' => $arr->address->line2,
                    'city' => $arr->address->city,
                    'state' => $arr->address->state,
                    'zip' => $arr->address->postal_code,
                ) );
                if ($this->Order->getBillto()->getID() == 0) {
                    $this->Order->setBillto($Address);
                }
                if ($this->Order->getShipto()->getID() == 0) {
                    $this->Order->setBillto($Address);
                }
            }

            if ($amt_paid > 0) {
                $currency = $Payment->currency;
                $this->setRefID($Payment->payment_intent);
                $LogID = $this->logIPN();

                $this_pmt = Currency::getInstance($currency)->fromInt($amt_paid);
                $this->setIPN('pmt_gross', $this_pmt);
                if (isset($this->getData()->data->object->customer_details)) {
                    $tmp = $this->getData()->data->object->customer_details;
                    $this->setIPN('payer_name', $tmp->name);
                    $this->setIPN('payer_email', $tmp->email);
                }
                $this->Payment = Payment::getByReference($this->getID());
                if ($this->Payment->getPmtID() == 0) {
                    $this->Payment->setRefID($Payment->payment_intent)
                        ->setAmount($this_pmt)
                        ->setGateway($this->getSource())
                        ->setMethod($this->GW->getDscp())
                        ->setComment('Webhook ' . $this->getID())
                        ->setComplete(1)
                        ->setStatus($this->getData()->type)
                        ->setOrderID($this->getOrderID());
                    $this->Payment->Save();
                    $retval = true;
                }
                $retval = $this->handlePurchase($this->Order);
            }
            break;
        case 'charge.refunded':
            $object = $this->getData()->data->object;
            if (isset($object->refunds->data) && is_array($object->refunds->data)) {
                $refund = $object->refunds->data[0];
                $ref_id = $refund->id;
                $pmt_intent = $refund->payment_intent;
            } else {
                $ref_id = '';
            }
            if (empty($ref_id)) {
                Log::debug('No refund ID included in webhook');
                return false;
            }
            $refund_amt = $refund->amount / 100;
            $this->setPayment($refund_amt * -1);
            $this->setRefId($ref_id);
            $this->setPmtMethod('refund');
            $this->setComplete($object->status == 'succeeded');

            $origPmt = Payment::getByReference($pmt_intent);
            if ($origPmt->getPmtId() > 0) {
                $order_id = $origPmt->getOrderId();
                if (!empty($order_id)) {
                    $Order = Order::getInstance($order_id);
                    if ($Order->isNew()) {
                        $Order = NULL;
                    }
                }
            }
            $this->setOrderID($Order->getOrderID());
            if ($Order) {
                $total = $Order->getTotal();
                if ($refund_amt >= $total) {
                    $this->handleFullRefund($Order);
                }
                $this->recordPayment();
                $retval = true;
            } else {
                $this->logIPN();
            }
            break;

        case 'payment_intent.created':
        case 'payment_intent.succeeded':
            // Just logging the notification, not updating the payments table
            if (isset($this->getData()->data->object->id)) {
                $Obj = $this->getData()->data->object;
                $this->setRefID($Obj->id);
                $this->setEvent($this->getEvent());
                $this->logIPN();
                $retval = true;
            }
            break;

        default:
            Log::error("Unhandled Stripe event {$this->getData()->type} received");
            $retval = true;     // OK, just some other event received
            break;
        }
        return $retval;
    }

}
