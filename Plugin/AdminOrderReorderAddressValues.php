<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Observer\OrderAddressLoadAfter;
use MageOS\CustomerCustomAttributes\Service\ReorderValues;
use Magento\Sales\Model\AdminOrder\Create;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;

/**
 * Reorder in the admin: core copies the billing and shipping address of the order to the new quote with a fieldset that lists only the
 * standard fields, so the custom values would be lost. The values come from the order's snapshot rows and are saved on the quote
 * addresses, where the order create form reads them.
 */
class AdminOrderReorderAddressValues
{
    public function __construct(
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly ReorderValues $reorderValues
    ) {
    }

    public function afterInitFromOrder(Create $subject, Create $result, Order $order): Create
    {
        $quote = $subject->getQuote();
        $pairs = [[$order->getBillingAddress(), $quote->getBillingAddress()]];
        if (!$quote->isVirtual()) {
            $pairs[] = [$order->getShippingAddress(), $quote->getShippingAddress()];
        }

        $existing = array_keys($this->attributeProvider->getUserDefined());
        foreach ($pairs as [$from, $to]) {
            if (!$from instanceof OrderAddress || $to === null) {
                continue;
            }
            $values = $this->reorderValues->forQuoteAddress((array) $from->getData(OrderAddressLoadAfter::DATA_KEY), $existing);
            if ($values === []) {
                continue;
            }
            foreach ($values as $code => $value) {
                $to->setCustomAttribute($code, $value);
            }
            $to->save();
        }
        return $result;
    }
}
