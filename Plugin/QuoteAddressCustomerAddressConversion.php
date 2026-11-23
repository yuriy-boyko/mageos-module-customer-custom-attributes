<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use Magento\Customer\Api\Data\AddressInterface as CustomerAddressInterface;
use Magento\Quote\Model\Quote\Address as QuoteAddress;

/**
 * Core converts between a quote address and a customer address through fieldsets, which only list the system fields,
 * so custom attributes are dropped on the way. Carrying them in both directions keeps the values when:
 *  - the shipping address is copied to the billing address ("same as billing"),
 *  - the quote address is validated and saved as a customer address (guest validation, address book),
 *  - a saved customer address is loaded into the quote.
 */
class QuoteAddressCustomerAddressConversion
{
    public function __construct(private readonly AddressAttributeProvider $attributeProvider)
    {
    }

    /**
     * Quote address -> customer address data object.
     */
    public function afterExportCustomerAddress(
        QuoteAddress $subject,
        CustomerAddressInterface $result
    ): CustomerAddressInterface {
        foreach (array_keys($this->attributeProvider->getUserDefined()) as $code) {
            $custom = $subject->getCustomAttribute($code);
            if ($custom !== null && $custom->getValue() !== null && $custom->getValue() !== '') {
                $result->setCustomAttribute($code, $custom->getValue());
            }
        }
        return $result;
    }

    /**
     * Customer address data object -> quote address.
     *
     * Importing replaces the whole address, so an attribute the customer address has no value for is cleared on the
     * quote address. Otherwise the values of the address that was selected before would stay on the new one.
     */
    public function afterImportCustomerAddressData(
        QuoteAddress $subject,
        QuoteAddress $result,
        CustomerAddressInterface $address
    ): QuoteAddress {
        // Hyvä Checkout builds the data object of a saved address from the model's flat data when it picks the first saved address
        // at checkout start; such an object has the values as plain keys, not as custom attributes.
        $flat = method_exists($address, '__toArray') ? (array) $address->__toArray() : [];
        foreach (array_keys($this->attributeProvider->getUserDefined()) as $code) {
            $custom = $address->getCustomAttribute($code);
            $value = $custom !== null ? $custom->getValue() : ($flat[$code] ?? null);
            if (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            }
            $result->setCustomAttribute($code, is_scalar($value) || $value === null ? $value : null);
        }
        return $result;
    }
}
