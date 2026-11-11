<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use Magento\Sales\Block\Adminhtml\Order\Create\Form\Address;

/**
 * Admin order create: the billing and shipping address forms are filled from the quote address' data, where the custom
 * attributes sit as custom attribute objects rather than plain fields. This adds them as plain fields so the form shows them.
 */
class AdminOrderCreateAddressFormValues
{
    public function __construct(private readonly AddressAttributeProvider $attributeProvider)
    {
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterGetFormValues(Address $subject, array $result): array
    {
        $address = $subject->getAddress();
        if (!is_object($address) || !method_exists($address, 'getCustomAttribute')) {
            return $result;
        }
        foreach ($this->attributeProvider->getUserDefined() as $code => $attribute) {
            if (array_key_exists($code, $result)) {
                continue; // a value posted in this request is newer than the stored custom attribute
            }
            $value = $address->getCustomAttribute($code)?->getValue();
            if ($value === null || $value === '') {
                continue;
            }
            $result[$code] = $attribute->getFrontendInput() === 'multiselect' && is_string($value)
                ? explode(',', $value)
                : $value;
        }
        return $result;
    }
}
