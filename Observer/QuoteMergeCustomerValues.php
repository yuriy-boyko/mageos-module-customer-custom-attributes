<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Observer;

use MageOS\CustomerCustomAttributes\Model\Checkout\CustomerCheckoutAttributes;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/**
 * Event sales_quote_merge_after: a guest who logs in while the customer already has an active cart gets the items merged into
 * that cart (core). The customer attribute values the guest typed in the checkout sections go along for the attributes that cart
 * has no value for; the cart's own values stay. The customer's group restrictions and the dependency rules apply as on every
 * save of these values.
 */
class QuoteMergeCustomerValues implements ObserverInterface
{
    public function __construct(
        private readonly CustomerCheckoutAttributes $checkoutAttributes,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $target = $observer->getEvent()->getQuote();
        $source = $observer->getEvent()->getSource();
        if (!$target instanceof Quote || !$source instanceof Quote || !(int) $target->getId() || !(int) $source->getId()
            || (int) $target->getId() === (int) $source->getId()) {
            return;
        }
        try {
            $typed = $this->checkoutAttributes->getValues($source);
            if ($typed === []) {
                return;
            }
            $own = $this->checkoutAttributes->getValues($target);
            $missing = [];
            foreach ($typed as $code => $value) {
                if ($value !== '' && ($own[$code] ?? '') === '') {
                    $missing[$code] = $value;
                }
            }
            if ($missing !== []) {
                $this->checkoutAttributes->saveValues($target, $missing);
            }
        } catch (\Throwable $e) {
            // never stop a login because of these values
            $this->logger->warning('[MageOS_CustomerCustomAttributes] could not carry the guest checkout values over: ' . $e->getMessage());
        }
    }
}
