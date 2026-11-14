# Architecture

For contributors and AI coding tools. Read this before changing code; [TESTING.md](TESTING.md) says how to verify a change and
[STATUS.md](STATUS.md) what exists. The user-facing description is in [../README.md](../README.md).

## Principles

1. **Definitions and customer/address values are core EAV.** Attributes are normal `customer` / `customer_address` EAV
   attributes (`is_user_defined = 1`), so core, REST and other modules see them. Which form shows an attribute is the core
   `customer_form_attribute` table. This module adds two pseudo form codes (see `Service/EntityDefinitions`):
   `mageos_hyva_checkout` and `mageos_address_text`.
2. **Quote and order values live in fixed-schema tables**, one row per parent and attribute, declared in `etc/db_schema.xml`.
   Nothing alters a table at runtime. After a schema change regenerate the whitelist:
   `bin/magento setup:db-declaration:generate-whitelist --module-name=MageOS_CustomerCustomAttributes`.
3. **Orders are snapshots.** Order tables store code, label, input type, value and value label and have **no foreign key to
   `eav_attribute`**: an order must render from its own rows even after the attribute was renamed, changed or deleted.
4. **Pure logic in `Service/`** (no Magento imports, unit tested in `Test/Unit`); Magento adapters (plugins, observers,
   blocks) stay thin and delegate.
5. **Hyvä first, no Luma.** Storefront layouts use the `hyva_` prefix, templates use Alpine.js and Tailwind, no Knockout or
   RequireJS. Hyvä Checkout code that implements Hyvä interfaces lives in the companion module, never here (see below).
6. **No runtime dependency on commercial sources.** Never copy code, templates or JS from commercial modules; never commit
   Hyvä Checkout sources.

## Packages

| Package | Magento module | Contains |
|---|---|---|
| `mage-os/module-customer-custom-attributes` | `MageOS_CustomerCustomAttributes` | everything except the Hyvä Checkout form modifier |
| `mage-os/module-customer-custom-attributes-hyva-checkout` | `MageOS_CustomerCustomAttributesHyvaCheckout` | `Model/AttributeVisibilityModifier` (implements Hyvä Checkout's form modifier interface) and its `etc/frontend/di.xml` registration |

Both packages share one version number and are tagged together (the companion's `composer.json` requires the main package without
a version constraint until the first tags exist; tighten it to the matching minor version, for example `^0.1`, afterwards).

The split exists because `setup:di:compile` loads every class of every enabled module: a class that implements a Hyvä
Checkout interface fatals on stores without Hyvä Checkout. Keep the main module free of references to `Hyva\Checkout\*`
classes (plugins on Hyvä classes in `etc/di.xml` are fine, a plugin class must not type-hint them).

## Data flow (address attributes)

```
Hyvä Checkout form / REST cart endpoint
   → quote address (Model/Quote/Address/CustomAttributeList keeps the custom attributes)
   → mageos_cca_quote_address_value   (Plugin/QuoteAddressSaveValues on the resource save, Observer/QuoteAddressLoadAfter)
   → order address  (Plugin/ConvertQuoteAddressToOrderAddress)
   → mageos_cca_order_address_value   (Observer/OrderAddressSaveAfter, loaded by OrderAddressLoadAfter as `mageos_cca_attributes`)
   → customer address (core EAV) when "save in address book" is ticked or a saved address is used
        (Plugin/QuoteAddressCustomerAddressConversion keeps values in both directions)
```

Customer (non-address) attributes: values stay on the customer (core EAV); when a logged-in customer places an order
`Observer/QuoteSubmitBefore` + `OrderSaveAfter` write the snapshot to `mageos_cca_order_value`, `OrderLoadAfter` loads it.
`mageos_cca_quote_value` is created but not used by storefront code yet (reserved for admin order create).

## Map of the code

| Area | Files |
|---|---|
| Admin attribute screens | `Controller/Adminhtml/Attribute*`, `Block/Adminhtml/Attribute/*` (grid, edit tabs Main/Front/Advanced/Websites), `Model/Attribute/{EntityContext,FormProcessor,ReservedCodeProvider}`, layouts `mageos_cca_attribute_*`. One controller set serves both entities, chosen by the `entity` request parameter (`customer` or `address`), with an ACL resource each (`etc/acl.xml`) |
| Admin relations | `Controller/Adminhtml/Relation*`, `Block/Adminhtml/Relation/Relation`, templates `relation/{list,edit}.phtml`, `Model/ResourceModel/RelationStorage`, `Service/RelationValidator` |
| Value persistence | `Model/ResourceModel/{QuoteAddressValueStorage,OrderAddressValueStorage,OrderValueStorage,AttributeGroupStorage,AttributeWebsiteStorage,RelationStorage}`, `Model/{AddressAttributeProvider,CustomerAttributeProvider}`, `Service/{ValueNormalizer,SnapshotRowBuilder,ValueLabelResolver}` |
| Validation | `Service/ValueValidator` (rules), `Model/AddressValueValidator` (adapter), `Observer/ValidateQuoteAddresses` (before the order is created), `Plugin/OrderAddressSaveSubmittedValues` (admin order address edit) |
| Dependencies and groups | `Service/VisibilityResolver` (shared by server and browser), `Model/Dependency/{RuleProvider,VisibilityService,RelationAttributes}`, `view/frontend/templates/form/visibility.phtml` (account forms), `hyva-checkout/visibility.phtml` (checkout) |
| Per-website scope | `Block/Adminhtml/Attribute/Edit/Tab/Websites`, `Service/WebsiteScopeNormalizer`, `Model/ResourceModel/AttributeWebsiteStorage` writing core's `customer_eav_attribute_website` |
| Display | `Plugin/AddressRendererCustomAttributes` + `Model/AddressText/*` + `Service/AddressText/AddressTextBuilder` |
| Storefront forms | `ViewModel/FormAttributes`, `Service/FieldAttributeBuilder`, templates `address/`, `customer/`, `form/field.phtml` (radio and checkbox groups), layouts `hyva_customer_*` |
| Hyvä Checkout | `Plugin/HyvaCheckoutAddressAttributeFilter`, `ViewModel/HyvaCheckoutVisibility`, `view/frontend/layout/hyva_checkout_*`, templates `hyva-checkout/*`, `Model/SavedAddressRequirements`, `Plugin/CustomerManagementSavedAddressMessage`; the form modifier is in the companion module |
| Customer attributes in the checkout | `Service/CheckoutPositions`, `Model/Checkout/CustomerCheckoutAttributes` (fields per position, values, prefill, rules, validation), `Model/ResourceModel/QuoteValueStorage`, admin fields in `Block/Adminhtml/Attribute/Edit/Tab/Front`, `Observer/QuoteSubmitBefore` (order snapshot + validation); in the companion module `Magewire/CustomerAttributes` (one virtual type per position in `etc/frontend/di.xml`), `view/frontend/layout/hyva_checkout_index_index.xml` (one block per position in the one-page containers) and `checkout/customer-attributes.phtml`; the field markup is the main module's `form/field.phtml` bound with `wire:model.lazy`; instant show/hide in `hyva-checkout/visibility.phtml` |
| Admin order create / edit / reorder | `Plugin/AdminOrder*`, `Plugin/AdminOrderCreateAddressFormValues`, `Plugin/QuoteAddressSaveValues`, `Model/QuoteAddressValueReader`; reorder: `Plugin/AdminOrderReorderAddressValues` (after `Create::initFromOrder`, core copies the addresses with a fieldset of standard fields only) + `Service/ReorderValues` |
| API | `etc/extension_attributes.xml`, `Api/Data/AddressAttributeValueInterface`, `Plugin/OrderAddressExtensionAttributes` (`custom_attribute_values` on order addresses, `customer_attribute_values` on orders) |
| Hyvä Tailwind | `Observer/RegisterModuleForHyvaConfig` on `hyva_config_generate_before` (adds the module to `app/etc/hyva-themes.json`) |
| Install/uninstall | `etc/db_schema.xml`, `Setup/InstallSchema` (empty, only registers the module in `setup_module` so that `module:uninstall` finds `Setup/Uninstall`), `Setup/Uninstall` |

### Tables

| Table | Key | Notes |
|---|---|---|
| `mageos_cca_quote_address_value` | quote address + attribute id | FK to `quote_address` and `eav_attribute`, cascade (checkout connection) |
| `mageos_cca_quote_value` | quote + attribute id | customer attributes, reserved |
| `mageos_cca_order_address_value` | order address + attribute code | snapshot, FK to `sales_order_address` only (sales connection) |
| `mageos_cca_order_value` | order + attribute code | snapshot of customer attributes, FK to `sales_order` only |
| `mageos_cca_relation`, `mageos_cca_relation_detail` | relation; parent attribute + option + dependent attribute | dependency rules, FK cascade to the attributes |
| `mageos_cca_attribute_group` | attribute + customer group | group restriction |

### Input types and rules

Supported: text field, text area, date, yes/no, dropdown, multiple select. Dropdown and yes/no can be displayed as radio
buttons and multiple select as checkboxes through the extra validate rule key `mageos_display` (`Service/ValidateRulesBuilder`),
so the stored data, REST output and metadata stay those of the standard type. Dates are stored as `YYYY-MM-DD HH:MM:SS`.
Validation rules live in the core `customer_eav_attribute.validate_rules` JSON.

## Magento and Hyvä behaviours this code depends on

Learned the hard way; check them before "simplifying":

- Quote address and order address resources are version-controlled or snapshot resources: they skip `save` and its events when
  no column changed, so a change of only custom values does not trigger `*_save_after`. The module hooks `save()` with plugins
  instead (`QuoteAddressSaveValues`, `OrderAddressSaveSubmittedValues`).
- The cart REST endpoints replace the quote address: the old one is flagged deleted and saved. Code that stores data after the
  resource save must skip deleted addresses (foreign key failure otherwise).
- Core converts the quote address to the order address **before** `sales_model_service_quote_submit_before`; values of hidden
  attributes must also be removed from the order address data key `mageos_cca_values`.
- `AbstractExtensibleModel` keeps a posted flat key beside the custom attribute; `Model/QuoteAddressValueReader` prefers the flat
  key (admin order create).
- Core validates saved addresses and saved customers (account information form, registration, admin) with the EAV data models, which
  would reject a required attribute that is hidden by a rule or a group restriction: `Plugin/EavDataRequiredForHiddenAttribute`
  relaxes it, for address and customer attributes. A hidden field is disabled in the browser, so the form does not send it and core
  passes `false` as the value. Core wraps validation messages of the shipping-information endpoint into a generic text.
- Core also validates every required attribute of the address book form (`customer_address_edit`) on the quote address at the
  checkout. A required attribute that is in that form but not in the Hyvä Checkout address forms can never be filled, so no order can
  be placed. The module keeps core's behaviour and warns on the attribute's edit page (`Model/Attribute/RequiredAddressFormWarning`,
  rule in the pure `Service/RequiredAddressFormCheck`).
- Core's mass action reads ids without collection bind parameters: `Block/Adminhtml/Attribute/Grid/Massaction` overrides
  `getGridIdsJson` to use `getAllIds()`.
- Customer and address attributes are loaded per website by `Eav\Config` (`customer_eav_attribute_website`); read
  `getIsRequired()`, `getIsVisible()`, `getDefaultValue()` through the providers with the right website. The admin has no current
  website and sees the default values.
- Hyvä Checkout: strict Content Security Policy (inline scripts need `$hyvaCsp->registerInlineScript()`); form fields are removed
  when `hide()` is called and come back only through a server update, so rule-hidden fields are rendered with a `hidden` wrapper
  class and toggled instantly by the browser script; Hyvä never edits address-book entries; its messenger shows plain text, so the
  saved-address notice is a separate block with a link; it limits text values to the attribute's maximum text length (255 default).
- In the multi-step and mobile checkout the parent of a rule can be on another step: the browser script cannot read it there and keeps
  the state the server rendered for such a field (it only applies rules whose parents are on the page).
- Magewire (v1) reports a change either as `values.<code>` or as the whole `values` array, so `updated($value, $name)` must handle both.
  Prefill and defaults run in `mount()` (first render only), never on later updates, or a cleared field would refill at once.
- Admin pages load prototype.js, which changes `Array.from`; keep admin scripts defensive.

## Decisions

- Order rows are snapshots; no grid columns, filters or search for the values in this version.
- Dependencies: named relations with detail rows (parent attribute + option → dependent attribute) and customer group restrictions
  through one pure resolver used by server and browser; nested and cycle-safe; no per-option control of the child's options.
- Per-website scope uses core's `customer_eav_attribute_website` table (Enabled, Required, Default value); everything else is global.
  The Websites tab shows one block per website.
- Customer attributes shown in the checkout (ticked for "Hyvä Checkout" in Show in Forms) get their own sections, like order
  attributes: position (the seven positions in `Service/CheckoutPositions`), sort order and prefill are stored in the validate rules
  (`mageos_checkout_position`, `mageos_checkout_sort`, `mageos_checkout_prefill`). Values typed there live on the quote
  (`mageos_cca_quote_value`) and the order snapshot only; the customer account is never written from the checkout. Prefill fills
  only attributes without a value on the quote, on the first render of the page (a guest who logs in keeps what was typed); the
  default value is used when nothing is prefilled. Guests see the fields. The order snapshot of such an attribute reads the quote
  values, not the customer. Required values and rules are enforced in the storefront area at order placement
  (`Observer/QuoteSubmitBefore`) and by the section's completion check; REST orders are not forced to supply them.
- A later "Update my account" button is possible: the typed values are already separate from the account.
- Hyvä Checkout hiding is instant in both directions in the browser and the server clears the values of hidden attributes.
- A saved address that lacks a required attribute is reported (checkout notice with an edit link, one field named, several give a
  generic text); there is no automatic back-fill.
- Not built: multiline, file and image types, GraphQL, import/export of attribute definitions.

## Adding things

- **New input type:** `Service/InputTypeMap`, `Service/ValidateRulesBuilder`, `Service/FieldAttributeBuilder`, `ValueValidator`,
  `ValueNormalizer`, admin `Block/.../Tab/Main`, storefront `form/field.phtml` and `hyva-checkout/field/*`, snapshot formatting
  (`Service/ValueLabelResolver`, `Model/AddressText/ValueFormatter`). Add unit tests first.
- **New form code:** `Service/EntityDefinitions` (code ≤ 32 characters because of `customer_form_attribute.form_code`), the Front
  tab, and the reader of that form.
- **New table:** `etc/db_schema.xml`, regenerate the whitelist, add it to `Setup/Uninstall`, document it here.
- **New phrase:** wrap in `__()` and regenerate `i18n/en_US.csv`.
