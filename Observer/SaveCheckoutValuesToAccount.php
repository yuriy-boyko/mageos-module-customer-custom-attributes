<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Observer;

use MageOS\CustomerCustomAttributes\Model\Checkout\CustomerCheckoutAttributes;
use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Service\AccountSaveDecider;
use MageOS\CustomerCustomAttributes\Service\AdminOnlyAccess;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Event sales_model_service_quote_submit_success: a logged-in customer placed an order in the storefront checkout. The values typed
 * in the checkout sections of the attributes set to "Also Save to Customer Account" are copied to the account (AccountSaveDecider
 * decides which). Only those attributes are written, one at a time, without the full customer save: that would also validate every
 * other required attribute of the customer, and a customer without one could not be saved. The customer grid row is refreshed.
 * A failure is logged and never stops the order.
 */
class SaveCheckoutValuesToAccount implements ObserverInterface
{
    public function __construct(
        private readonly CustomerAttributeProvider $attributeProvider,
        private readonly CustomerCheckoutAttributes $checkoutAttributes,
        private readonly AccountSaveDecider $decider,
        private readonly ValidateRulesBuilder $rulesBuilder,
        private readonly AdminOnlyAccess $adminOnly,
        private readonly ValueNormalizer $normalizer,
        private readonly CustomerFactory $customerFactory,
        private readonly CustomerResource $customerResource,
        private readonly State $appState,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        $customerId = $quote ? (int) $quote->getCustomerId() : 0;
        if ($customerId <= 0 || !$this->isStorefront()) {
            return; // guests and the admin order create page are not covered
        }
        try {
            $attributes = $this->attributeProvider->getUserDefined();
            $rulesByCode = [];
            foreach ($this->checkoutAttributes->getCodes() as $code) {
                if (isset($attributes[$code])) {
                    $rulesByCode[$code] = (array) $attributes[$code]->getValidateRules();
                }
            }
            if (array_filter($rulesByCode, fn (array $rules): bool => $this->rulesBuilder->savesToAccount($rules)) === []) {
                return;
            }

            $customer = $this->customerFactory->create()->load($customerId);
            if (!$customer->getId()) {
                return;
            }
            $account = [];
            foreach (array_keys($rulesByCode) as $code) {
                $account[$code] = $this->normalizer->normalize($customer->getData($code));
            }
            $write = $this->decider->valuesToSave($rulesByCode, $this->checkoutAttributes->getOrderValues($quote), $account, $this->rulesBuilder, $this->adminOnly);
            if ($write === []) {
                return;
            }
            foreach ($write as $code => $value) {
                try {
                    $customer->setData($code, $value);
                    $this->customerResource->saveAttribute($customer, $code);
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf('The checkout value of "%s" could not be saved to customer %d: %s', $code, $customerId, $e->getMessage()));
                }
            }
            $customer->reindex();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('The checkout values could not be saved to customer %d: %s', $customerId, $e->getMessage()));
        }
    }

    private function isStorefront(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_FRONTEND;
        } catch (LocalizedException) {
            return false;
        }
    }
}
