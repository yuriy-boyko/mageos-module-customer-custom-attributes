<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Observer;

use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\OrderValueStorage;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

/**
 * Events sales_order_load_after and sales_order_collection_load_after: exposes the order's customer attribute snapshot as
 * the order data "mageos_cca_customer_attributes" (sorted by the attributes' current sort order, deleted ones last).
 */
class OrderLoadAfter implements ObserverInterface
{
    public const DATA_KEY = 'mageos_cca_customer_attributes';

    public function __construct(
        private readonly OrderValueStorage $storage,
        private readonly CustomerAttributeProvider $attributeProvider
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $collection = $event->getOrderCollection();
        $orders = $collection !== null ? $collection->getItems() : [$event->getOrder()];

        $byId = [];
        foreach ($orders as $order) {
            if ($order instanceof Order && $order->getId()) {
                $byId[(int) $order->getId()] = $order;
            }
        }
        if ($byId === []) {
            return;
        }

        $sortOrder = [];
        foreach ($this->attributeProvider->getUserDefined() as $code => $attribute) {
            $sortOrder[$code] = (int) $attribute->getSortOrder();
        }

        $stored = $this->storage->load(array_keys($byId));
        foreach ($byId as $orderId => $order) {
            $rows = $stored[$orderId] ?? [];
            usort($rows, static fn (array $a, array $b): int =>
                ($sortOrder[$a['attribute_code']] ?? PHP_INT_MAX) <=> ($sortOrder[$b['attribute_code']] ?? PHP_INT_MAX));
            $order->setData(self::DATA_KEY, $rows);
        }
    }
}
