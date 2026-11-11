# 5. Address attributes

Address attributes are created under **Stores → Attributes → Customer Address Attributes** and describe one address.

## Where they appear

Pick the forms in **Show in Forms** ([Managing attributes](03-managing-attributes.md)):

- **Hyvä Checkout Address Forms:** the shipping and billing address forms. Values are saved on the quote address, copied to the
  order address and, when the customer saves the address to the address book, to the customer address. A saved address selected in
  the checkout loads its values into the quote. A saved address that has no value for an attribute clears values left from a
  previously selected address.
- **Address Book (Customer Account):** the Add/Edit Address form in the account, with the saved values and native browser
  validation derived from the attribute's rules. A server-side validation message keeps everything the customer typed.
- **Registration Address Form:** the address part of the registration form.
- **Address Text:** see below.

Unticking "Hyvä Checkout Address Forms" removes the field from the checkout address forms.

![Address attributes in the checkout address form: text, required text, text area, multiple select and date](images/checkout-address-fields.png)

## Address text

With **Address Text** ticked, the value is added after the standard address lines as "Label: value". Dates are shown without time
and select, multiselect and yes/no values as readable labels. This appears wherever an address is displayed through Magento's
address renderer:

- the checkout saved-address cards
- the account dashboard and address book cards
- the customer's order pages
- order emails and PDFs (html, text, pdf and one-line formats)
- the admin order, invoice and shipment views

Orders use their own snapshot, so a renamed or deleted attribute never changes an old order. An attribute with Address Text
switched off is hidden in all of these places.

## Dependencies and groups

Address attributes can be hidden by rules and customer groups, instantly in the Hyvä Checkout address forms. See
[Dependencies and groups](06-dependencies.md).

## Saved addresses that lack a required value

When an attribute becomes required after customers saved addresses, those addresses have no value. The module helps in two places:

- **Checkout notice:** next to the address list the Hyvä Checkout shows *"Your saved address needs the field X. Click here to edit
  it"* with a link to the account address form. When several fields are missing the text is generic ("Your saved address has some
  required fields missing"). Billing and shipping are checked independently.
- **Order message:** if the customer places the order anyway, the message names the address and, for one missing field, the field:
  *Your saved shipping address needs the field "X". Please edit the address in My Account > Address Book, or choose another address.*

Attributes that are hidden by a rule or a customer group are neither reported nor required. There is no automatic back-fill of
existing addresses.

## Per-website behaviour

An address attribute disabled for a website is absent from that website's checkout and forms; one required only for a website is
required only there ([Managing attributes](03-managing-attributes.md)).
