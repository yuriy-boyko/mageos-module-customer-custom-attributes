<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Observer;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\QuoteAddressValueStorage;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote\Address;

/**
 * Events sales_quote_address_load_after and sales_quote_address_collection_load_after: puts the stored values back on
 * the quote address as custom attributes. A collection is loaded with one query, not one per address.
 */
class QuoteAddressLoadAfter implements ObserverInterface
{
    public function __construct(
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly QuoteAddressValueStorage $storage
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $collection = $event->getQuoteAddressCollection();
        $addresses = $collection !== null ? $collection->getItems() : [$event->getQuoteAddress()];

        $byId = [];
        foreach ($addresses as $address) {
            if ($address instanceof Address && $address->getId()) {
                $byId[(int) $address->getId()] = $address;
            }
        }
        if ($byId === []) {
            return;
        }

        $codesById = array_flip($this->attributeProvider->getIdsByCode());
        if ($codesById === []) {
            return;
        }
        $stored = $this->storage->load(array_keys($byId));
        foreach ($stored as $addressId => $values) {
            foreach ($values as $attributeId => $value) {
                if (isset($codesById[$attributeId])) {
                    $byId[$addressId]->setCustomAttribute($codesById[$attributeId], $value);
                }
            }
        }
    }
}
