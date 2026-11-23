# 9. Troubleshooting and FAQ

## A field does not show

| Where | Check |
|---|---|
| Any storefront form | the attribute is **Enabled**, and enabled for the website (Websites tab); the form is ticked in **Show in Forms**; the customer's group is allowed; a relation does not hide it |
| Hyvä Checkout address form | "Hyvä Checkout Address Forms" is ticked for the attribute (address attributes follow this option, not the registration form); the cache is flushed |
| Hyvä Checkout section (customer attribute) | "Hyvä Checkout" is ticked, the companion module `MageOS_CustomerCustomAttributesHyvaCheckout` is enabled and `setup:upgrade` was run, the cache is flushed |
| A dependent field | its parent is in the same form, see the relation warning on the relation page |
| Account form, new attribute created by a script | assign the attribute to an attribute set and flush the EAV cache; attributes created in the admin are assigned automatically |

## Fields look unstyled

Run `bin/magento hyva:config:generate` and rebuild the Tailwind CSS of the theme (`npm ci && npm run build` in the theme's
`web/tailwind` folder). Check that `app/etc/hyva-themes.json` lists the module.

## A custom field does nothing in the checkout (nothing happens on change)

The Hyvä Checkout enforces a strict Content Security Policy. Inline scripts of customisations must be registered with
`$hyvaCsp->registerInlineScript()`. Look at the browser console for blocked scripts.

## The order is rejected

- *"X is a required value"*: a visible required attribute is empty. If the attribute is not in the checkout form at all, it is
  required but cannot be filled: tick "Hyvä Checkout Address Forms" for it or make it optional (the attribute's edit page warns about this,
  see [Address attributes](05-address-attributes.md)).
- *Your saved shipping address needs the field "X"*: a saved address has no value for a required attribute; follow the link to edit
  the address, or choose another address. When the field is not in the address book form there is no link: choose "New Address" and
  type the address again, or add the attribute to "Address Book (Customer Account)".
- A message naming an attribute and a rule (length, email, date range, unknown option): the value breaks the attribute's rules.
  Hidden attributes are never required.

## The value is missing on the order

- Address attributes need "Hyvä Checkout Address Forms" ticked to appear in the checkout.
- A value of an attribute hidden by a rule or group is dropped on purpose.
- Customer attributes not ticked for the checkout have no value for guests. For a logged-in customer the value is read from the
  account when the order is placed.

## The customer's account was not updated after the checkout

By design. Values typed in the checkout stay on the order. See [Customer attributes](04-customer-attributes.md).

## A prefilled field is filled again after clearing it

Prefill runs at every page load and fills empty fields. Switch "Prefill from Customer" off for the attribute if this is not wanted.

## Data is gone after disabling the module

`setup:upgrade` drops the tables of a disabled module, so order snapshots, relations and quote values are lost. Back up the
`mageos_cca_*` tables before disabling, see [Installation](02-installation.md#disable).

## Changing code or input type

Both are fixed after creation. Create a new attribute and delete the old one (orders keep their snapshot).

## Removing everything

See [Installation](02-installation.md#uninstall).

## Where to look for errors

`var/log/exception.log`, `var/log/system.log` and the browser console. Magewire errors of the checkout sections are logged by
Magewire.

## Frequently asked questions

**Do old orders change when I rename an attribute?** No, orders keep their own snapshot.

**Can one attribute be both a customer and an address attribute?** No. Create one of each.

**Can I show customer attributes in the address forms?** No. They belong to the account; use the checkout sections.

**Is Luma supported?** No, the storefront integration is for Hyvä.

**Can I export the values?** There are no grid columns or filters; read them with the order API or SQL
([Orders and stored data](07-orders-and-data.md)).
