# Stripe Payment Gateway
Note: Stripe requires that your site is served via https.

If you use Bad Behavior, be sure to whitelist your webhook url (`your_site/shop/hook/webhook.php`) or webhook messages may be dropped.

## Stripe Account Setup
Set up an account at https://www.stripe.com. In addition, there are several items in your Stripe account that must be set up correctly in order to process purchases.

  - Create API keys in both Test and Live environments. You'll enter these keys in the gateway configuration.
  - Create a webhook pointing to the webhook handler, e.g. `https://example.com/shop/hooks/webhook.php?_gw=stripe`
  - Get the webhook signatures (Test and Live) from Stripe and add them to the gateway configuration.

## Webhook Setup
  - Log into https://dashboard.stripe.com/test/webhooks (remove “/test” to update production).
  - Click “Add Endpont”
  - Supply your webhook endpoint: `your_site/shop/hooks/webhook.php?_gw=stripe`
  - Select these events. Not all are currently used but may be added later.
    - `payment_intent.requires_action`
    - `payment_intent.processing`
    - `payment_intent.payment_failed`
    - `payment_intent.created`
    - `payment_method.updated`
    - `payment_intent.canceled`
    - `payment_intent.succeeded`
    - `checkout.session.completed`
    - To enable invoicing (see terms), also select these items:
      - `invoice.payment_failed`
      - `invoice.finalized`
      - `invoice.voided`
      - `invoice.payment_succeeded`
      - `invoice.paid`
      - `invoice.created`


## Webhook Configuration
Note the webhook signing key to be added to the gateway configuration. This may not be shown when the webhook is created; if not, click the webhook name and the details will pop up at the right side. Click “Show” for the webhook and enter the value in the gateway configuration.

## Shop Plugin Configuration
### Global
Test (Sandbox) Mode
Check this box to use Stripe's sandbox for testing. When you're ready for production, un-check this box.

### Enabled
Check this box to enable the gateway, or un-check to disable. This only affects whether the gateway is shown to buyers as a payment option, webhook messages will still be processed after the gateway is disabled.

### Services
Select the services which can be provided by this gateway. Stripe supports cart checkout and net terms invoicing (see the “terms” gateway“).

### Authorized Group
Select the glFusion group that is allowed to use this gateway. Normally this will be “All Users” or “Logged-In Users”, but you may restrict access if needed.

### Order
This is a number representing the order in which the payment option appears in the list at checkout, lower numbers appear higher.

## Production/Sandbox
The production and sandbox environments each have their own settings to be set.

### Public Key
Enter the Public API key obtained from Stripe for each environment. This should begin with `pk_live` or `pk_test`.

### Secret Key
Enter the Secret key obtained from Stripe for each environment. This should begin with `sk_live` or `sk_test`.

### Webhook Secret
Enter the Webhook Secret obtained from Stripe for each environment. This should begin with “whsec” for both environments.

Note that the Live and Test webhook secrets start with the same text, so be sure to put the correct values in the correct fields.

