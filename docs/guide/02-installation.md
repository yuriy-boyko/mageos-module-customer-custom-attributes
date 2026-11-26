# 2. Installation and upgrade

## Install

```bash
composer require mage-os/module-customer-custom-attributes
# With Hyvä Checkout also:
composer require mage-os/module-customer-custom-attributes-hyva-checkout

bin/magento module:enable MageOS_CustomerCustomAttributes
bin/magento module:enable MageOS_CustomerCustomAttributesHyvaCheckout   # only with Hyvä Checkout
bin/magento setup:upgrade
bin/magento cache:flush
```

Install both packages on the same version (they are released together). `setup:upgrade` creates the seven module tables. In production mode also run `bin/magento setup:di:compile` and
`bin/magento setup:static-content:deploy`.

## Installing on a store that already has customer attributes

Attributes made by another module or by data patches become visible to the module as soon as they are user-defined. Before going live:

1. Open **Stores > Attributes > Customer Attributes** and read the **Shown in** column. Every attribute assigned to the registration or
   account edit form (bold) is printed to customers and accepted from their posts. "+N other" counts form codes of other modules; they do
   nothing in this module and can be removed with a patch (`customer_form_attribute`).
2. Set **Customer Cannot Change This Value** on every internal value (price level, ERP id, agent code, flags): the field is never printed and
   a value a customer sends is ignored. The admin, integrations and imports can still change it.
3. Read the warnings of the grid: a required attribute that customers cannot fill in blocks registrations or account saves, and an
   attribute with a reserved code can be mixed up with a field of the customer, the cart or the order.
4. Switch **Save on Orders** off for internal values that do not belong to the order history, and choose **Show in Emails** for the values
   the customer should see in the emails.
5. Attributes whose input type the module does not know open with a "(not supported)" note: convert them with a data patch (see
   [Managing attributes](03-managing-attributes.md)).
6. A theme that hardcodes the registration or account edit form must print the module's fields, see
   [Customer attributes](04-customer-attributes.md).

## Hyvä styles

The module registers itself for the Hyvä modules configuration so the Tailwind build includes its templates. After installing:

```bash
bin/magento hyva:config:generate
cd app/design/frontend/<Vendor>/<theme>/web/tailwind && npm ci && npm run build
```

If fields look unstyled, rebuild the Tailwind CSS (see [Troubleshooting](09-troubleshooting.md)).

## Check the installation

- Stores → Attributes shows **Customer Attributes**, **Customer Address Attributes** and **Attribute Relations**.
- A new role resource exists for each of them (System → Permissions → User Roles → Role Resources → Stores → Attributes).

## Upgrade

```bash
composer update mage-os/module-customer-custom-attributes mage-os/module-customer-custom-attributes-hyva-checkout
bin/magento setup:upgrade
bin/magento cache:flush
```

Read [CHANGELOG.md](../../CHANGELOG.md) before upgrading (0.1.0 is the initial version). Rebuild the Hyvä Tailwind CSS when templates changed.

## Disable

```bash
bin/magento module:disable MageOS_CustomerCustomAttributesHyvaCheckout MageOS_CustomerCustomAttributes
bin/magento setup:upgrade
```

The attributes and the values on customers and addresses stay in the database but are no longer shown.

> **Warning:** `setup:upgrade` after disabling the module **drops the tables of the quote values, the relations and the group
> restrictions** (Magento's declarative schema removes the tables of disabled modules that are in the module's schema whitelist).
> Re-enabling the module creates them empty. **The two order snapshot tables are kept**, with all their rows, because they hold the values
> the orders were placed with; re-enabling the module reads them again. Back up the other `mageos_cca_*` tables first if you need
> them. A required address attribute that is still assigned
> to a core address form also blocks the checkout while the module is disabled, because core drops custom address values: make it
> optional or remove it from the forms first.

## Uninstall

Delete the attributes you do not want to keep in the admin first (the module's uninstall leaves attributes in place), then:

```bash
bin/magento module:disable MageOS_CustomerCustomAttributesHyvaCheckout MageOS_CustomerCustomAttributes
bin/magento module:uninstall -r MageOS_CustomerCustomAttributesHyvaCheckout MageOS_CustomerCustomAttributes
```

`-r` (remove data) drops the module's tables and the two form assignments it created (`mageos_hyva_checkout`,
`mageos_address_text`). The attributes themselves are standard EAV attributes and stay, with their values in the core customer
tables, until you delete them. Orders lose their custom snapshot rows with the tables.
