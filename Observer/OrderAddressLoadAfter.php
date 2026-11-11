<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Observer;

use MageOS\CustomerCustomAttributes\Model\ResourceModel\OrderAddressValueStorage;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Address;

/**
 * Events sales_order_address_load_after and sales_order_address_collection_load_after: puts the snapshot rows on the
 * order address under the data key "mageos_cca_attributes" (list of rows with attribute_code, attribute_label,
 * input_type, value, value_label). Display code reads only this, never the live attribute definition.
 */
class OrderAddressLoadAfter implements ObserverInterface
{
    public const DATA_KEY = 'mageos_cca_attributes';

    public function __construct(private readonly OrderAddressValueStorage $storage)
    {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $collection = $event->getOrderAddressCollection();
        $addresses = $collection !== null ? $collection->getItems() : [$event->getAddress()];

        $byId = [];
        foreach ($addresses as $address) {
            if ($address instanceof Address && $address->getId()) {
                $byId[(int) $address->getId()] = $address;
            }
        }
        if ($byId === []) {
            return;
        }

        $stored = $this->storage->load(array_keys($byId));
        foreach ($byId as $addressId => $address) {
            $address->setData(self::DATA_KEY, $stored[$addressId] ?? []);
        }
    }
}
