<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressText\EntryProvider;
use Magento\Framework\Registry;
use Magento\Sales\Block\Adminhtml\Order\Address\Form;

/**
 * Fills the admin "Edit Order Address" form with the values of the order's snapshot (the order address table has no
 * columns for them, so core cannot).
 */
class AdminOrderAddressFormValues
{
    public function __construct(private readonly Registry $registry)
    {
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterGetFormValues(Form $subject, array $result): array
    {
        $address = $this->registry->registry('order_address');
        foreach ((array) $address?->getData(EntryProvider::SNAPSHOT_KEY) as $row) {
            $result[$row['attribute_code']] = $row['input_type'] === 'multiselect'
                ? explode(',', (string) $row['value'])
                : $row['value'];
        }
        return $result;
    }
}
