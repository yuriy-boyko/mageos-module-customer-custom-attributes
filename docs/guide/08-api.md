# 8. API

The module uses standard Magento REST endpoints; it adds no new routes. GraphQL is not supported.

## Writing address values on a cart

Send the values as `custom_attributes` on the shipping and billing address of the cart endpoints, for guests
(`/V1/guest-carts/:cartId/shipping-information`, `/V1/guest-carts/:cartId/payment-information`) and customers
(`/V1/carts/mine/...`):

```json
{
  "addressInformation": {
    "shipping_address": {
      "firstname": "Ada", "lastname": "Lovelace", "street": ["1 Main St"],
      "city": "Austin", "region_id": 57, "postcode": "78701", "country_id": "US", "telephone": "5125550100",
      "custom_attributes": [
        {"attribute_code": "delivery_notes", "value": "Leave at the gate"},
        {"attribute_code": "tags", "value": "12,14"}
      ]
    },
    "shipping_method_code": "flatrate", "shipping_carrier_code": "flatrate"
  }
}
```

Formats: see [value formats](07-orders-and-data.md#value-formats). The values are stored on the quote address and copied to the order.
Invalid values (too short, unknown option, missing required value, wrong type) make placing the order fail with a 400 error.
Core wraps some validation messages into a generic text. Values of attributes hidden by a rule or customer group are dropped, as
in the browser.

Customer attributes have no cart endpoint: for a logged-in customer they are read from the customer account when the order is
placed (attributes shown in the Hyvä Checkout come from the checkout form instead). A required checkout attribute does not block
REST orders.

## Customers

`GET` and `PUT /V1/customers/me` read and update customer and address attributes as `custom_attributes`, like any attribute. The
metadata endpoints list them with their input types, so `custom_attributes` follow the standard format.

## Reading orders

Orders carry two extension attributes (order get, list, and the order address repository):

- `extension_attributes.custom_attribute_values` on the **billing and shipping address** (also inside
  `shipping_assignments[].shipping.address`)
- `extension_attributes.customer_attribute_values` on the **order** (customer attributes)

Each entry:

```json
{
  "attribute_code": "delivery_notes",
  "attribute_label": "Delivery notes",
  "input_type": "text",
  "value": "Leave at the gate",
  "value_label": "Leave at the gate"
}
```

`value` is the stored value (option ids for selects), `value_label` the readable text. They come from the order snapshot, so they
are stable when the attribute changes later.

## PHP interface

`MageOS\CustomerCustomAttributes\Api\Data\AddressAttributeValueInterface` exposes `getAttributeCode()`, `getAttributeLabel()`,
`getInputType()`, `getValue()` and `getValueLabel()`; it is used for both extension attributes.
