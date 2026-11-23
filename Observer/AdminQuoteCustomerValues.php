<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Observer;

use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\QuoteValueStorage;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;

/**
 * Event sales_quote_load_after, admin area only (etc/adminhtml/events.xml): the customer attribute values kept with an admin order
 * create quote (a reorder or an edited order brings the values of the original order) are put on the quote as customer_<code>, the
 * keys the "Account Information" form of the admin order create page reads and posts with the order. A value the quote already
 * has (posted by the form) wins.
 */
class AdminQuoteCustomerValues implements ObserverInterface
{
    public function __construct(
        private readonly CustomerAttributeProvider $attributeProvider,
        private readonly QuoteValueStorage $storage
    ) {
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        if (!$quote instanceof Quote || !(int) $quote->getId()) {
            return;
        }
        $stored = $this->storage->load((int) $quote->getId());
        if ($stored === []) {
            return;
        }
        foreach ($this->attributeProvider->getUserDefined() as $code => $attribute) {
            $value = $stored[(int) $attribute->getAttributeId()] ?? null;
            if ($value !== null && !$quote->hasData('customer_' . $code)) {
                $quote->setData('customer_' . $code, $value);
            }
        }
    }
}
