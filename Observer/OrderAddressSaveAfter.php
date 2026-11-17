<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Observer;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\OrderAddressValueStorage;
use MageOS\CustomerCustomAttributes\Plugin\ConvertQuoteAddressToOrderAddress;
use MageOS\CustomerCustomAttributes\Service\SnapshotRowBuilder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Address;

/**
 * Event sales_order_address_save_after: writes the snapshot rows for the values that
 * ConvertQuoteAddressToOrderAddress put on the new order address. The snapshot uses the attribute definition as it is
 * right now and is never recalculated from the definition later.
 */
class OrderAddressSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly SnapshotRowBuilder $rowBuilder,
        private readonly OrderAddressValueStorage $storage,
        private readonly \MageOS\CustomerCustomAttributes\Model\OptionLabels $optionLabels
    ) {
    }

    public function execute(Observer $observer): void
    {
        $address = $observer->getEvent()->getAddress();
        if (!$address instanceof Address || !$address->getId()) {
            return;
        }
        $values = $address->getData(ConvertQuoteAddressToOrderAddress::DATA_KEY);
        if (!is_array($values) || $values === []) {
            return;
        }

        $storeId = $address->getOrder()?->getStoreId();
        $attributes = $this->attributeProvider->getUserDefined();
        $rows = [];
        foreach ($values as $code => $value) {
            if (!isset($attributes[$code])) {
                continue;
            }
            $attribute = $attributes[$code];
            $rows[] = $this->rowBuilder->build(
                (string) $code,
                $attribute->getStoreLabel($storeId),
                (string) $attribute->getFrontendInput(),
                (string) $value,
                $this->optionLabels->forAttribute($attribute)
            );
        }

        $this->storage->save((int) $address->getId(), $rows);
        $address->unsetData(ConvertQuoteAddressToOrderAddress::DATA_KEY);
        // The order e-mail is sent by the request that places the order, with this very address object, which was not loaded from the
        // database: give it the snapshot a load would give it, so that the address text of the e-mail has the custom lines.
        $address->setData(OrderAddressLoadAfter::DATA_KEY, $this->storage->load([(int) $address->getId()])[(int) $address->getId()] ?? []);
    }
}
