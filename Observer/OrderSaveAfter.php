<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Observer;

use MageOS\CustomerCustomAttributes\Model\ResourceModel\OrderValueStorage;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Event sales_order_save_after: writes the snapshot rows collected by QuoteSubmitBefore (only on the order's creation).
 */
class OrderSaveAfter implements ObserverInterface
{
    public function __construct(private readonly OrderValueStorage $storage)
    {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        $rows = $order->getData(QuoteSubmitBefore::DATA_KEY);
        if (!is_array($rows) || $rows === [] || !$order->getId()) {
            return;
        }
        $this->storage->save((int) $order->getId(), $rows);
        $order->unsetData(QuoteSubmitBefore::DATA_KEY);
        // the order now carries its rows like a loaded one: the new order email is rendered from this same object
        $order->setData(OrderLoadAfter::DATA_KEY, $rows);
    }
}
