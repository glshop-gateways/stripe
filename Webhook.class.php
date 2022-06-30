<?php
/**
 * This file contains the Stripe IPN class.
 *
 * @author      Lee Garner <lee@leegarner.com>
 * @copyright   Copyright (c) 2019-2020 Lee Garner
 * @package     shop
 * @version     v1.3.0
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
use Shop\Models\OrderState;
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
    public function Verify()
    {
        $event = NULL;
        if (isset($_POST['vars'])) {
            $payload = base64_decode($_POST['vars']);
            $sig_header = $_POST['HTTP_STRIPE_SIGNATURE'];
            $event = json_decode($payload);
        } elseif (isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
            $payload = @file_get_contents('php://input');
            $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        } else {
            $payload = '';
            $sig_header = 'invalid';
        }
        if (Config::get('sys_test_ipn')) {
            $this->setData($event);
            $this->setEvent($this->getData()->type);
            $this->setVerified(true);
            $this->blob = $payload;
            return true;
        }

        Log::write('shop_system', Log::DEBUG, 'Received Stripe Webhook: ' . var_export($payload, true));
        Log::write('shop_system', Log::DEBUG, 'Sig Key: ' . var_export($sig_header, true));

        if ($sig_header == 'invalid') {
            return false;
        }

        $this->blob = $payload;

        if ($event === NULL) {  // to skip test data from $_POST
            require_once __DIR__ . '/vendor/autoload.php';
            try {
                \Stripe\Stripe::setApiKey($this->GW->getSecretKey());
                $event = \Stripe\Webhook::constructEvent(
                    $payload, $sig_header, $this->GW->getWebhookSecret()
                );
            } catch(\UnexpectedValueException $e) {
                // Invalid payload
                Log::write('shop_system', Log::ERROR, "Unexpected Value received from Stripe");
                return false;
                //http_response_code(400); // PHP 5.4 or greater
                //exit;
            } catch(\Stripe\Error\SignatureVerification $e) {
                // Invalid signature
                Log::write('shop_system', Log::ERROR, "Invalid Stripe signature received");
                return false;
                //http_response_code(400); // PHP 5.4 or greater
                //exit;
            }
        }
        if (empty($event)) {
            Log::write('shop_system', Log::ERROR, "Unable to create Stripe webhook event");
            return false;
        }
        $this->setData($event);
        $this->setEvent($this->getData()->type);
        $this->setVerified(true);
        Log::write('shop_system', Log::DEBUG, "Stripe webhook verified OK");
        return true;
    }


    /**
     * Perform the necessary actions based on the webhook.
     * At this point all required objects should be valid.
     *
     * @return  boolean     True on success, False on error
     */
    public function Dispatch()
    {
        $retval = false;        // be pessimistic

        switch ($this->getEvent()) {
        case 'invoice.created':
            // Invoice was created. As a net-terms customer, the order
            // can be processed.
            if (!isset($this->getData()->data->object->metadata->order_id)) {
                Log::write('shop_system', Log::ERROR, "Order ID not found in invoice metadata");
                return false;
            }
            $this->setOrderID($this->getData()->data->object->metadata->order_id);
            $this->Order = Order::getInstance($this->getOrderID());
            if ($this->Order->isNew()) {
                Log::write('shop_system', Log::ERROR, "Invalid Order ID received in webhook");
                return false;
            }
            $this->Order->setGatewayRef($this->getData()->data->object->id)
                        ->setInfo('terms_gw', $this->GW->getName())
                        ->Save();
            if ($this->Order->statusAtLeast(OrderState::PROCESSING)) {
                Log::write('shop_system', Log::ERROR, "Order " . $this->Order->getOrderId() . " was already invoiced and processed");
            }

            // Invoice created successfully
            $retval = $this->handlePurchase($this->Order);
            break;
        case 'invoice.payment_succeeded': 
            // Invoice payment notification
            if (!isset($this->getData()->data->object->metadata->order_id)) {
                Log::write('shop_system', Log::ERROR, "Order ID not found in invoice metadata");
                return false;
            }

            if (!isset($this->getData()->data->object->payment_intent)) {
                Log::write('shop_system', Log::ERROR, "Payment Intent value not include in webhook");
                return false;
            }
            $Payment = $this->getData()->data->object;
            $this->setID($Payment->payment_intent);
            if (!$this->isUniqueTxnId()) {
                Log::write('shop_system', Log::ERROR, "Duplicate Stripe Webhook received: " . $this->getData()->id);
                return false;
            }

            $this->setOrderID($this->getData()->data->object->metadata->order_id);
            $this->Order = Order::getInstance($this->getOrderID());
            if ($this->Order->isNew()) {
                Log::write('shop_system', Log::ERROR, "Invalid Order ID received in webhook");
                return false;
            }
            $amt_paid = $Payment->amount_paid;
            if ($amt_paid > 0) {
                $this->setID($this->getData()->id);
                $LogID = $this->logIPN();
                $currency = $Payment->currency;
                $this_pmt = Currency::getInstance($currency)->fromInt($amt_paid);
                $Pmt = Payment::getByReference($this->getID());
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
                Log::write('shop_system', Log::ERROR, "Order ID not found in invoice metadata");
                return false;
            }
            if (!isset($this->getData()->data->object->payment_intent)) {
                Log::write('shop_system', Log::ERROR, "Payment Intent value not include in webhook");
                return false;
            }

            $Payment = $this->getData()->data->object;
            $this->setID($Payment->payment_intent);
            if (!$this->isUniqueTxnId()) {
                Log::write('shop_system', Log::ERROR, "Duplicate Stripe Webhook received: " . $this->getData()->id);
                return false;
            }

            $this->setOrderID($this->getData()->data->object->client_reference_id);
            $this->Order = Order::getInstance($this->getOrderID());
            if ($this->Order->isNew()) {
                Log::write('shop_system', Log::ERROR, "Invalid Order ID received in webhook");
                return false;
            }
            $amt_paid = $Payment->amount_total;
            if ($amt_paid > 0) {
                $this->setID($this->getData()->id);
                $currency = $Payment->currency;
                $this->setRefID($Payment->payment_intent);
                $LogID = $this->logIPN();

                $this_pmt = Currency::getInstance($currency)->fromInt($amt_paid);
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
            //var_dump($this->getData());die;
            $object = $this->getData()->data->object;
            if (isset($object->payment_intent)) {
                $pmt_intent = $object->payment_intent;
            } else {
                $pmt_intent = '';
            }
            if (empty($pmt_intent)) {
                return false;
            }

            $refund_amt = $object->amount_refunded / 100;
            $this->setPayment($refund_amt * -1);
            $this->setID($object->id);
            $this->setRefId($pmt_intent);
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
        default:
            Log::write('shop_system', Log::ERROR, "Unhandled Stripe event {$this->getData()->type} received");
            $retval = true;     // OK, just some other event received
            break;
        }
        return $retval;
    }

}
