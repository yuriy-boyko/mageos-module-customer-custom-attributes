<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressText\EntryProvider;
use MageOS\CustomerCustomAttributes\Model\Data\AddressAttributeValueFactory;
use MageOS\CustomerCustomAttributes\Observer\OrderLoadAfter;
use Magento\Sales\Api\Data\OrderAddressExtensionFactory;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderAddressRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Fills the extension attribute "custom_attribute_values" of order addresses from the order's snapshot rows, so REST and
 * GraphQL-style consumers of the order and order address repositories receive the custom values as ordered.
 */
class OrderAddressExtensionAttributes
{
    public function __construct(
        private readonly OrderAddressExtensionFactory $extensionFactory,
        private readonly OrderExtensionFactory $orderExtensionFactory,
        private readonly AddressAttributeValueFactory $valueFactory
    ) {
    }

    /**
     * Used for both OrderRepositoryInterface::get() (an order) and OrderAddressRepositoryInterface::get() (an address).
     *
     * @param OrderRepositoryInterface|OrderAddressRepositoryInterface $subject
     * @param OrderInterface|OrderAddressInterface $result
     * @return OrderInterface|OrderAddressInterface
     */
    public function afterGet($subject, $result)
    {
        if ($result instanceof OrderInterface) {
            $this->populateOrder($result);
        } elseif ($result instanceof OrderAddressInterface) {
            $this->populateAddress($result);
        }
        return $result;
    }

    public function afterGetList(OrderRepositoryInterface $subject, OrderSearchResultInterface $result): OrderSearchResultInterface
    {
        foreach ($result->getItems() as $order) {
            $this->populateOrder($order);
        }
        return $result;
    }

    private function populateOrder(OrderInterface $order): void
    {
        $this->populateCustomerValues($order);
        foreach ([$order->getBillingAddress(), $order->getShippingAddress()] as $address) {
            if ($address instanceof OrderAddressInterface) {
                $this->populateAddress($address);
            }
        }
        $extension = $order->getExtensionAttributes();
        foreach ((array) ($extension ? $extension->getShippingAssignments() : []) as $assignment) {
            $address = $assignment->getShipping()?->getAddress();
            if ($address instanceof OrderAddressInterface) {
                $this->populateAddress($address);
            }
        }
    }

    private function populateCustomerValues(OrderInterface $order): void
    {
        $rows = $order->getData(OrderLoadAfter::DATA_KEY);
        if (!is_array($rows) || $rows === []) {
            return;
        }
        $values = [];
        foreach ($rows as $row) {
            $values[] = $this->valueFactory->create(['data' => $row]);
        }
        $extension = $order->getExtensionAttributes() ?: $this->orderExtensionFactory->create();
        $extension->setCustomerAttributeValues($values);
        $order->setExtensionAttributes($extension);
    }

    private function populateAddress(OrderAddressInterface $address): void
    {
        $rows = $address->getData(EntryProvider::SNAPSHOT_KEY);
        if (!is_array($rows) || $rows === []) {
            return;
        }
        $values = [];
        foreach ($rows as $row) {
            $values[] = $this->valueFactory->create(['data' => $row]);
        }
        $extension = $address->getExtensionAttributes() ?: $this->extensionFactory->create();
        $extension->setCustomAttributeValues($values);
        $address->setExtensionAttributes($extension);
    }
}
