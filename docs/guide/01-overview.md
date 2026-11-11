# 1. Overview

`MageOS_CustomerCustomAttributes` lets a store admin add **custom fields to customers and to customer addresses** in Magento
Open Source and Mage-OS, and carries their values through the whole purchase: registration, account, checkout, quote, order,
address book, admin and API. It is built for **Hyvä Theme and Hyvä Checkout**; Luma is not supported.

## What you can do

| Goal | Where it is described |
|---|---|
| Create, edit, delete and mass-manage attributes in the admin | [Managing attributes](03-managing-attributes.md) |
| Ask the customer for extra data at registration, in the account, or in the checkout | [Customer attributes](04-customer-attributes.md) |
| Add fields to shipping and billing addresses, the address book and address displays | [Address attributes](05-address-attributes.md) |
| Show or hide fields depending on another field or the customer group | [Dependencies and groups](06-dependencies.md) |
| Keep, view and read the values on orders | [Orders and stored data](07-orders-and-data.md) |
| Read or write the values through the REST API | [API](08-api.md) |
| Fix problems | [Troubleshooting](09-troubleshooting.md) |

## Two kinds of attributes

- **Customer attributes** belong to the customer account (for example "Customer type" or "Birthday"). They can be shown in the
  registration form, the account edit form, the admin order form and, in their own sections, in the Hyvä Checkout.
- **Customer address attributes** belong to an address (for example "Delivery notes" or "Gate code"). They are shown in the
  shipping and billing address forms of the Hyvä Checkout, in the account address book form, and in every place an address is
  displayed.

Both kinds are normal Magento EAV attributes, so core Magento, REST and other modules see them.

## Input types

Text field, text area, date, yes/no, dropdown and multiple select. A dropdown or yes/no can be displayed as **radio buttons**
and a multiple select as **checkboxes**; the stored data stays that of a standard dropdown or multiple select.

## Main features

- **Admin screens** under Stores → Attributes with grids, filters, mass actions and one permission (ACL resource) per area.
- **Validation:** required, text length, numbers, letters, letters and numbers, email, URL, date range, allowed options. Rules run
  in the browser and again on the server when an order is placed.
- **Per-website settings:** enabled, required and default value can differ per website.
- **Customer groups:** show an attribute only to chosen groups.
- **Relations (dependencies):** show an attribute only when another attribute has a chosen option, instantly in the browser and
  enforced on the server.
- **Order snapshots:** an order keeps the label and value as they were when it was placed, even if the attribute is renamed or
  deleted later.
- **Address text:** the values appear after the standard address lines in checkout cards, the account, order pages, emails, PDFs
  and admin views.
- **Checkout sections for customer attributes** with position, sort order and prefill from the customer.
- **Admin orders:** values can be entered when creating an order and edited with "Edit Order Address".
- **REST:** values on cart addresses and customers, and on orders as extension attributes.
- **No runtime schema changes:** all tables are declared in `db_schema.xml`.

## Requirements

- Magento Open Source 2.4.9 or Mage-OS, PHP 8.3, 8.4 or 8.5.
- Hyvä Theme. For the Hyvä Checkout features also Hyvä Checkout and the companion package
  `mage-os/module-customer-custom-attributes-hyva-checkout`.

## Packages

| Package | Purpose |
|---|---|
| `mage-os/module-customer-custom-attributes` | the module: admin, data, validation, account forms, display, API |
| `mage-os/module-customer-custom-attributes-hyva-checkout` | Hyvä Checkout integration: live hiding in the address forms and the checkout sections for customer attributes. It is separate so the main module also compiles on stores without Hyvä Checkout |

## Not included

Multiline fields, file and image attributes, GraphQL, import/export of attribute definitions, grid columns or filters for the
values, and an "Update my account" button in the checkout.
