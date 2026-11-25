# 4. Customer attributes

Customer attributes describe the customer, not one address. They are created under **Stores → Attributes → Customer Attributes**.

## Registration and account

Tick **Customer Registration** and/or **Customer Account Edit** in Show in Forms. The attribute then appears in the Hyvä
registration form and in the Account Information form with native browser validation derived from its rules (required, length,
pattern, email, URL, date range). The values are saved by core Magento on the customer. Dropdown, yes/no and multiple select
attributes can be shown as radio buttons or checkboxes ([Managing attributes](03-managing-attributes.md)).

![The registration form with customer attributes: the text field appears when Gold is chosen](images/registration-form.png)

## Admin

Customer attributes are always part of the admin customer edit form. Tick **Admin Order Create (Account Information)** to show
them in the admin order form too (see [Orders and stored data](07-orders-and-data.md)).

## Customer attributes in the Hyvä Checkout

Tick **Hyvä Checkout** in Show in Forms (needs Hyvä Checkout and the companion module). Three more settings apply, listed below the Show in Forms
field (they are always shown, and greyed out while Hyvä Checkout is not ticked):

| Setting | Meaning |
|---|---|
| Position at Checkout Step | Above or Below Shipping Address, Above or Below Shipping Methods, Above or Below Payment Method, Order Summary |
| Checkout Sort Order | order inside a position, lower first |
| Prefill from Customer | fill an empty field with the logged-in customer's saved value |
| Also Save to Customer Account | copy the value typed in the checkout to the account of a logged-in customer (see "Values typed in the checkout and the account" below) |

![The checkout with customer attributes above the shipping address, above the payment methods and below the shipping methods](images/checkout-sections.png)

How it behaves:

- Each position is a small section of the checkout. In the multi-step and mobile checkout it appears on the step that contains the
  position (the payment positions on the payment step, the order summary on the summary step).
- **Guests see the fields too.** The values are kept on the quote.
- **By default the customer account is not changed from the checkout.** What the customer types is saved on the quote and then on the
  order. An empty value in the account stays empty even if the customer filled the field at the checkout, and changes made to a
  prefilled field are not written back. This makes an attribute that is ticked only for the checkout (and the admin order form)
  behave like an *order attribute*: the value exists on the order only. Switch on **Also Save to Customer Account** for an attribute
  to copy the typed value to the account of a logged-in customer when the order is placed.
- **Prefill** runs when the checkout page loads and fills only fields that have no value yet. A guest who logs in during the
  checkout keeps what was typed (when the customer already has an active cart: for the fields that cart has no value for); empty
  fields are prefilled. A field the customer clears is prefilled again at the next page load.
  Without prefill the default value is used, and a required attribute must be typed on every order.
- **Rules apply.** Customer group restrictions, dependency rules and website settings work like in the account forms. Dependent
  fields show and hide instantly, also when the parent field is in another section or on another step; a hidden value is dropped
  and a hidden required field does not block the order. See [Dependencies and groups](06-dependencies.md).
- **Required values** are checked before the order is placed: the order is blocked with a message such as *"CCA Customer Text" is a
  required value.* Orders placed through the REST API are not forced to supply them (the cart endpoints have no field for customer
  attributes), and neither are orders while the companion module `mage-os/module-customer-custom-attributes-hyva-checkout`, which
  renders the sections, is disabled.
- A virtual-only cart has no shipping step: sections placed at a shipping position (above or below the shipping address or the
  shipping methods) are not shown and their attributes are not required there. Use a payment position or the order summary for
  attributes that must also be collected for virtual products.
- If a guest types values and then logs in: when the customer has no active cart, the guest's cart (with the typed values) becomes the
  customer's cart; when the customer already has an active cart, the items are merged into it, that cart's values stay, and the guest's
  typed values fill the attributes that cart has no value for.
- Attributes not ticked for the checkout keep working as before: their value is read from the customer when a logged-in customer
  places an order.

## Values only the admin may change

Set **Customer Cannot Change This Value** to Yes (Storefront Properties) for internal values such as a price level or an ERP id.
Core Magento accepts every user-defined attribute that a customer sends, in the registration and account forms and in the API
(`PUT /V1/customers/me`, GraphQL, `POST /V1/customers`). For such an attribute:

- the field is never printed in the registration and account forms;
- a value sent anyway is ignored: the stored value is kept (a new customer gets none). This covers the storefront registration and
  account edit posts and the API with a customer token or without a token;
- the admin, integrations (admin and integration tokens), imports, the command line and setup scripts can still change it;
- it cannot be required in the registration or account forms (the customer could never satisfy it); the attribute screen refuses that
  combination.

The setting applies to customer attributes only. It cannot be combined with "Also Save to Customer Account" or "Show in Emails".

## What an order gets

When an order is placed, a snapshot of the customer attribute values is stored with the order: code, label, input type, value
and the displayed value. Every customer attribute that has a value gets a row. Switch **Save on Orders** (Storefront Properties) to No
for internal values that do not belong to the order history: no row is saved for the attribute, and it does not appear in the admin
order view. The setting does not change the checks: an attribute that is required in the checkout is still required. Rows of orders
already placed are not removed (delete them with SQL if needed, see [Orders and stored data](07-orders-and-data.md)). A reorder in
the admin does not carry values of an attribute that is off. It is shown in the admin order view under **Account Information** and returned by the order API as the
extension attribute `customer_attribute_values`. See [Orders and stored data](07-orders-and-data.md).

## Values typed in the checkout and the account

By default a value typed in the checkout stays on the order. Set **Also Save to Customer Account** (Storefront Properties, for attributes
shown in the checkout) to Yes to copy it to the account when a logged-in customer places the order. An empty field never erases the stored
value. Guests are not covered. A failed save is logged and never stops the order.

## Show in Emails

Select the emails in **Show in Emails** (Storefront Properties; hold Ctrl or Cmd to select several) to list the customer's value in an
**Additional Information** section of those emails, below the payment and shipping method. The choices are New Order, Invoice, Shipment and
Credit Memo (the comment and order update emails are not changed). Each email lists only the attributes selected for it, so the invoice can
show one attribute and the shipment another. Select none to keep the attribute out of the emails (the default). It needs Save on Orders and
cannot be combined with "Customer Cannot Change This Value".

The setting **Add the Section Automatically** (Stores > Configuration > Sales > Sales Emails > Customer Attributes (Additional Information),
store view scope, default Yes) decides whether the module puts the section into the standard templates. Set it to No to decide for yourself
which templates get the section: the variable `{{var mageos_additional_information_html|raw}}` is filled in all four emails either way
(with the attributes selected for that email), so place it in the templates you want (Marketing > Communications > Email Templates). A template that already has the variable is never
changed, and a custom template without the payment and shipping method cells is left alone when the setting is Yes: place the variable there.
