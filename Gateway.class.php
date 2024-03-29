<?php
/**
 * Stripe payment gateway class.
 *
 * @author      Lee Garner <lee@leegarner.com>
 * @copyright   Copyright (c) 2019-2023 Lee Garner <lee@leegarner.com>
 * @package     shop
 * @version     v1.5.0
 * @since       v0.7.1
 * @license     http://opensource.org/licenses/gpl-2.0.php
 *              GNU Public License v2 or later
 * @filesource
 */
namespace Shop\Gateways\stripe;
use Shop\Config;
use Shop\Cart;
use Shop\Coupon;
use Shop\Currency;
use Shop\Customer;
use Shop\Order;
use Shop\Gateway as BaseGW;
use Shop\Models\OrderStatus;
use Shop\Models\CustomerGateway;
use Shop\Models\Token;
use Shop\Log;


/**
 *  Coupon gateway class, just to provide checkout buttons for coupons
 */
class Gateway extends \Shop\Gateway
{
    /** Gateway ID.
     * @var string */
    protected $gw_name = 'stripe';

    /** Gateway provide. Company name, etc.
     * @var string */
    protected $gw_provider = 'stripe.com';

    /** Gateway service description.
     * @var string */
    protected $gw_desc = 'Stripe Payment Gateway';

    /** Active public API Key.
     * @var string */
    private $pub_key = '';

    /** Active secret key.
     * @var string */
    private $sec_key = '';

    /** Active webhook secret.
     * @var string */
    private $hook_sec = '';

    /** Checkout session.
     * @var object */
    private $session = NULL;

    /** Cart object. Set in gatewayVars and used in getCheckoutButton().
     * @var object */
    private $_cart = NULL;


    /**
     * Constructor.
     * Set gateway-specific items and call the parent constructor.
     *
     * @param   array   $A      Array of fields from the DB
     */
    public function __construct(array $A=array())
    {
        // Set up the config field definitions.
        $this->cfgFields = array(
            'prod' => array(
                'pub_key'  => 'password',
                'sec_key'  => 'password',
                'hook_sec' => 'password',
            ),
            'test' => array(
                'pub_key'  => 'password',
                'sec_key'  => 'password',
                'hook_sec' => 'password',
            ),
            'global' => array(
                'test_mode' => 'checkbox',
            ),
        );

        // Set the only service supported
        $this->services = array(
            'checkout' => 1,
            'terms' => 1,
        );

        $this->ipn_url = $this->getWebhookUrl();
        parent::__construct($A);

        $this->pub_key = $this->getConfig('pub_key');
        $this->sec_key = $this->getConfig('sec_key');
        $this->hook_sec = $this->getConfig('hook_sec');
    }


    /**
     * Get the Square API client object.
     * Initializes the API for use with static functions as well as
     * returning a client object.
     *
     * @return  object      SquareClient object
     */
    public function getApiClient() : object
    {
        static $_client = NULL;
        if ($_client === NULL) {
            $this->loadSDK();
            \Stripe\Stripe::setApiKey($this->sec_key);
            $_client = new \Stripe\StripeClient($this->sec_key);
        }
        return $_client;
    }


    /**
     * Get the form vars to add to the "confirm" button.
     *
     * @param   object  $Cart       Order object
     * @return  string      HTML input vars
     */
    public function gatewayVars(Order $Cart) : string
    {
        $vars = array(
            'order_id' => $Cart->getOrderID(),
            'secret' => Token::encrypt($Cart->getSecret()),
        );
        $gw_vars = '';
        foreach ($vars as $name=>$val) {
            $gw_vars .= '<input type="hidden" name="' . $name .
                '" value="' . $val . '" />' . "\n";
        }
        return $gw_vars;
    }


    /**
     * Creates the order object via Stripe API.
     *
     * @param   object  $cart   Shopping cart
     * @return  object      Stripe checkout session object
     */
    public function _createOrder(Order $cart) : ?object
    {
        global $LANG_SHOP;

        if (!$this->Supports('checkout')) {
            return '';
        }

        $apiClient = $this->getApiClient();
        $cartID = $cart->getOrderID();
        $shipping = 0;
        $Cur = \Shop\Currency::getInstance();
        $line_items = array();
        $taxRates = array();    // save tax rate objects for reuse

        // If the cart has a gift card applied, set one line item for the
        // entire cart. Stripe does not support discounts or gift cards.
        $by_gc = $cart->getGC();
        if ($by_gc > 0) {
            $total_amount = $cart->getTotal() - $by_gc;
            $line_items[] = array(
                'name'      => $LANG_SHOP['all_items'],
                'description' => $LANG_SHOP['all_items'],
                'amount'    => $Cur->toInt($total_amount),
                'currency'  => strtolower($Cur),
                'quantity'  => 1,
            );
        } else {
            foreach ($cart->getItems() as $Item) {
                $P = $Item->getProduct();
                $Item->Price = $P->getPrice($Item->getOptions());
                $opts = $P->getOptionDesc($Item->getOptions());
                $dscp = $Item->getDscp();
                if (!empty($opts)) {
                    $dscp .= ' : ' . $opts;
                }
                $line_items[] = array(
                    'quantity'  => $Item->getQuantity(),
                    'price_data' => array(
                        'unit_amount' => $Cur->toInt($Item->getPrice()),
                        'currency'  => strtolower($Cur),
                        'product_data' => array(
                            'name'      => $Item->getDscp(),
                            'description' => $dscp,
                        ),
                    ),
                );
            }

            // Add line items to represent tax and shipping.
            // These are included in "all items" above when using a coupon.
            if ($cart->getTax() > 0) {
                $line_items[] = array(
                    'quantity'  => 1,
                    'price_data' => array(
                        'unit_amount'    => $Cur->toInt($cart->getTax()),
                        'currency'  => strtolower($Cur),
                        'product_data' => array(
                            'name'      => '__tax',
                            'description' => $LANG_SHOP['tax'],
                        ),
                    ),
                );
            }
            if ($cart->getShipping() > 0) {
                $line_items[] = array(
                    'quantity'  => 1,
                    'price_data' => array(
                        'unit_amount' => $Cur->toInt($cart->getShipping()),
                        'currency'  => strtolower($Cur),
                        'product_data' => array(
                            'name'      => '__shipping',
                            'description' => $LANG_SHOP['shipping'],
                        ),
                    ),
                );
            }
        }

        // Create the checkout session
        $session_params = array(
            'mode' => 'payment',
            'line_items' => $line_items,
            'success_url' => Config::get('url') . '/index.php?thanks=stripe',
            'cancel_url' => $cart->cancelUrl(),
            'client_reference_id' => $cartID,
            'metadata' => array(
                'order_id' => $cart->getOrderID(),
            ),
        );

        // Retrieve or create a Square customer record as needed
        $gwCustomer = $this->getCustomer($cart);
        if ($gwCustomer) {
            $session_params['customer'] = $gwCustomer->id;
        }

        // Create the checkout session and load the Stripe javascript
        try {
            $this->session = $apiClient->checkout->sessions->create($session_params);
        } catch (\Exception $e) {
            Log::error(__METHOD__ . ': ' . $e->getMessage());
            $this->session = NULL;
        }
        return $this->session;
    }


    /**
     * No javascript needed since we redirect through confirm.php.
     *
     * @param   object  $cart   Shopping cart object
     * @return  string  Javascript commands.
     */
    public function getCheckoutJS(Order $cart) : string
    {
        return '';
    }


    /**
     * Get the webhook secret in use.
     * Required by the IPN handler to validate IPN signatures.
     *
     * @return  string      Configured webhook secret value
     */
    public function getWebhookSecret() : string
    {
        return $this->hook_sec;
    }


    /**
     * Get the public API key.
     * Required by the IPN handler.
     *
     * @return  string      Public API key in use.
     */
    public function getAPIKey() : string
    {
        return $this->pub_key;
    }


    /**
     * Get the secret API key.
     * Required by the IPN handler.
     *
     * @return  string      Secret API key
     */
    public function getSecretKey() : string
    {
        return $this->sec_key;
    }


    /**
     * Retrieve a payment intent to get payment details.
     *
     * @param   string  $pmt_id     Payment Intent ID
     * @return  object  Strip Payment Intent object
     */
    public function getPayment(string $pmt_id) : ?object
    {
        if (empty($pmt_id)) {
            return NULL;
        }
        $this->getApiClient();
        $pmt = $this->getApiClient()->paymentIntents->retrieve($pmt_id);
        return \Stripe\PaymentIntent::retrieve($pmt_id);
    }


    /**
     * Get instructions for this gateway to display on the configuration page.
     *
     * @return  string      Instruction text
     */
    protected function getInstructions() : string
    {
        global $LANG_SHOP_HELP;
        return '<ul><li>' . $this->adminWarnBB() . '</li><li>' .
            $LANG_SHOP_HELP['gw_wh_instr'] . '</li></ul>';
    }


    /**
     * Get the gateway's customer record by user ID.
     * Creates a customer if not already present.
     *
     * @param   object  $Cart   Shopping cart object
     * @return  object      Stripe customer record
     */
    public function getCustomer(Order $Cart) : ?object
    {
        $cust_info = NULL;
        $email = $Cart->getBuyerEmail();

        if ($Cart->getUid() > 1) {
            $Customer = Customer::getInstance($Cart->getUid());
        } else {
            $Customer = new Customer;
            $Customer->setEmail($email);
        }
        $cust_id = $this->getCustomerId($Customer);
        $client = $this->getApiClient();
        if (!$cust_id) {
            // Don't have a customer ID saved, try searching Strip for one
            // matching the email address.
            try {
                $apiResponse = $client->customers->search(array('query' => "email:'$email'"));
                if (
                    is_object($apiResponse) &&
                    isset($apiResponse->data) &&
                    is_array($apiResponse->data) &&
                    !empty($apiResponse->data)
                ) {
                    // Found one or more matching customer records at Stripe.
                    $cust_info = $apiReaponse->data[0];
                }
            } catch (\Exception $e) {
                // Likely customer not found thrown. Leave $cust_info as NULL.
            }
        } else {
            // Get the customer information from the known customer ID.
            $apiResponse = $client->customers->retrieve($cust_id);
            if (
                is_object($apiResponse) &&
                isset($apiResponse->created) &&
                !empty($apiResponse->created)
            ) {
                // found a customer record
                $cust_info = $apiResponse;
            }
        }

        // If a customer isn't found at Stripe, create a new one.
        if (!is_object($cust_info) || !isset($cust_info->id)) {
            $cust_info = $this->createCustomer($Customer, $Cart->getBillto());
        }
        return $cust_info;
    }


    /**
     * Create a new customer record with Stripe and saves the ID locally.
     * Called if getCustomer() returns an empty set.
     *
     * @param   object  $Order      Order object, to get customer info
     * @return  object|false    Customer object, or false if an error occurs
     */
    private function createCustomer($Customer, ?object $Address=NULL) : ?object
    {
        // Get the default billing address to user in the Stripe record.
        // If there is no name entered for the default address, use the
        // glFusion full name.
        if ($Customer->getUid() > 1) {
            $Address = $Customer->getDefaultAddress('billto');
        }
        $name = $Address->getName();
        if (empty($name)) {
            $name = $Customer->getFullname();
        }

        $params = [
            'name' => $name,
            'email' => $Customer->getEmail(),
            'address' => [
                'line1' => $Address->getAddress1(),
                'line2' => $Address->getAddress2(),
                'city' => $Address->getCity(),
                'state' => $Address->getState(),
                'postal_code' => $Address->getPostal(),
                'country' => $Address->getCountry(),
            ],
        ];

        $client = $this->getApiClient();
        try {
            $apiResponse = $client->customers->create($params);
            /*if (isset($apiResponse->id)) {
                $Customer->setGatewayId($this->gw_name, $apiResponse->id);
            }*/
        } catch (\Exception $e) {
            Log::error(__METHOD__ . ': ' . $e->getMessage());
            $apiResponse = NULL;
        }
        if ($apiResponse) {
            $params = new CustomerGateway(array(
                'email' => $apiResponse->email,
                'cust_id' => $apiResponse->id,
                'gw_id' => $this->gw_name,
                'uid' => $Customer->getUid(),
            ) );
            $this->saveCustomerInfo($params);
        }
        return $apiResponse;
    }


    /**
     * Create and send an invoice for an order.
     *
     * @param   object  $Order  Order object
     * @param   object  $terms_gw   Invoice terms gateway, for config values
     * @return  boolean     True on success, False on error
     */
    public function createInvoice(Order $Order, BaseGW $terms_gw) : bool
    {
        global $LANG_SHOP;

        $gwCustomer = $this->getCustomer($Order->getUid());
        if ($gwCustomer) {
            $cust_id = $gwCustomer->id;
        } else {
            Log::error("Error creating Stripe customer for order {$Order->getOrderId()}");
            return false;
        }

        $Currency = $Order->getCurrency();
        $apiClient = $this->getApiClient();
        $taxRates = array();

        foreach ($Order->getItems() as $Item) {
            $opts = implode(', ', $Item->getOptionsText());
            $dscp = $Item->getDscp();
            if (!empty($opts)) {
                $dscp .= ' : ' . $opts;
            }
            $params = array(
                'customer' => $cust_id,
                'unit_amount' => $Currency->toInt($Item->getNetPrice()),
                'quantity' => $Item->getQuantity(),
                'description' => $dscp,
                'currency' => $Order->getCurrency(),
            );
            if ($Item->getTaxRate() > 0) {
                if (!isset($taxRates[$Item->getTaxRate()])) {
                    $taxRates[$Item->getTaxRate()]  = $apiClient->taxRates->create([
                        'display_name' => $LANG_SHOP['sales_tax'],
                        'percentage' => $Item->getTaxRate() * 100,
                        'inclusive' => false,
                    ]);
                }
                $params['tax_rates'] = array(
                    $taxRates[$Item->getTaxRate()],
                );
            }
            $apiClient->invoiceItems->create($params);
        }

        if ($Order->getShipping() > 0) {
            $apiClient->invoiceItems->create(array(
                'customer' => $cust_id,
                'unit_amount' => $Currency->toInt($Order->getShipping()),
                'quantity' => 1,
                'description' => $LANG_SHOP['shipping'],
                'currency' => $Order->getCurrency(),
            ) );
        }
        $invObj = $apiClient->invoices->create(array(
            'customer' => $cust_id,
            'auto_advance' => true,
            'metadata' => array(
                'order_id' => $Order->getOrderID(),
            ),
            'collection_method' => 'send_invoice',
            'days_until_due' => (int)$terms_gw->getConfig('net_days'),
        ) );
        // Get the invoice number if a valid draft invoice was created.
        if (isset($invObj->status) && $invObj->status == 'draft') {
            $Order->setGatewayRef($invObj->id)
                  ->setInfo('terms_gw', $this->getConfig('gateway'))
                  ->createInvoice()
                  ->Save();
            $Order->updateStatus(OrderStatus::INVOICED);
        }
        $invObj->finalizeInvoice();
        return true;
    }


    /**
     * Check that a valid config has been set for the environment.
     *
     * @return  boolean     True if valid, False if not
     */
    public function hasValidConfig() : bool
    {
        return !empty($this->getConfig('pub_key')) &&
            !empty($this->getConfig('sec_key')) &&
            !empty($this->getConfig('hook_sec'));
    }


    /**
     * Confirm the order and redirect to the payment page
     *
     * @param   object  $Order  Shop Order object
     * @return  string      Redirect URL
     */
    public function confirmOrder(Order $Order) : string
    {
        global $LANG_SHOP;

        $redirect = '';
        if (!$Order->isNew()) {
            $gwOrder = $this->_createOrder($Order);
            Log::debug("order created: " . print_r($gwOrder,true));
            if (is_object($gwOrder)) {
                $Order->setGatewayRef($gwOrder->id)->Save();
                $redirect = $gwOrder->url;
            } else {
                COM_setMsg("There was an error processing your order");
            }
        }
        return $redirect;
    }


    /**
     * Get the form action URL.
     *
     * @return  string      URL to payment processor
     */
    public function getActionUrl() : string
    {
        return Config::get('url') . '/confirm.php';
    }


    /**
     * List all available checkout sessions.
     * Used for debugging only.
     *
     * @return  array   Array with checkout sessions in the `data` property
     */
    public function listCheckoutSessions() : array
    {
        return $this->getApiClient()->checkout->sessions->all();
    }


    /**
     * Expire the checkout session if the customer cancels payment.
     *
     * @param   object  $Cart   Order object
     */
    public function cancelCheckout(Order $Cart) : void
    {
        $sess_id = $Cart->getGatewayRef();
        if (!empty($sess_id)) {
            $this->getApiClient()->checkout->sessions->expire($sess_id);
            $Cart->setGatewayRef('')->Save();
        }
    }

}
