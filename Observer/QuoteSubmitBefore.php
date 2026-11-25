<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Observer;

use MageOS\CustomerCustomAttributes\Model\Checkout\CustomerCheckoutAttributes;
use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\Dependency\VisibilityService;
use MageOS\CustomerCustomAttributes\Model\OptionLabels;
use MageOS\CustomerCustomAttributes\Service\SnapshotRowBuilder;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;

/**
 * Event sales_model_service_quote_submit_before: reads the customer's attribute values at the moment the order is placed
 * and puts them on the new order as snapshot rows (saved by OrderSaveAfter). Values typed into the admin order create
 * form win over the stored customer values. Attributes shown in the checkout (ticked for "Hyvä Checkout") take their value
 * from what the customer typed there (kept on the quote, guests included) and never from the customer account; in the
 * storefront their required flag and rules are enforced here, as long as the companion module that renders their sections is
 * enabled (without it the customer could not fill them in). Other attributes have no value for guests. The quote's customer_<code>
 * data is read in the admin area only. An attribute whose
 * "Save on Orders" setting is off leaves no row.
 */
class QuoteSubmitBefore implements ObserverInterface
{
    public const DATA_KEY = 'mageos_cca_customer_values';

    /** the companion module that renders the customer attribute sections of the checkout */
    private const SECTIONS_MODULE = 'MageOS_CustomerCustomAttributesHyvaCheckout';

    public function __construct(
        private readonly CustomerAttributeProvider $attributeProvider,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SnapshotRowBuilder $rowBuilder,
        private readonly ValueNormalizer $normalizer,
        private readonly OptionLabels $optionLabels,
        private readonly VisibilityService $visibility,
        private readonly CustomerCheckoutAttributes $checkoutAttributes,
        private readonly State $appState,
        private readonly ModuleManager $moduleManager,
        private readonly ValidateRulesBuilder $rulesBuilder
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        $quote = $observer->getEvent()->getQuote();
        $customerId = (int) $quote->getCustomerId();
        $attributes = $this->attributeProvider->getUserDefined();
        $checkoutCodes = $this->checkoutAttributes->getCodes();
        if ($attributes === [] || ($customerId <= 0 && $checkoutCodes === [])) {
            return;
        }
        if ($checkoutCodes !== [] && $this->isStorefront() && $this->moduleManager->isEnabled(self::SECTIONS_MODULE)) {
            $messages = $this->checkoutAttributes->validate($quote);
            if ($messages !== []) {
                throw new LocalizedException(__(implode(' ', array_unique($messages))));
            }
        }
        $customer = null;
        if ($customerId > 0) {
            try {
                $customer = $this->customerRepository->getById($customerId);
            } catch (NoSuchEntityException) {
                $customer = null;
            }
        }
        $checkoutValues = $checkoutCodes === [] ? [] : $this->checkoutAttributes->getOrderValues($quote);
        $isAdmin = $this->isAdmin();
        // Only the storefront checkout of the companion module puts values on the quote: there the typed value wins (an empty one stays
        // empty). An admin-created order, an API order or a shop without the companion has none, so the account value is used.
        $typedInCheckout = $this->isStorefront() && $this->moduleManager->isEnabled(self::SECTIONS_MODULE);

        $values = [];
        foreach ($attributes as $code => $attribute) {
            // An attribute switched off for orders leaves no row (its required flag was checked above).
            if (!$this->rulesBuilder->savesOnOrder((array) $attribute->getValidateRules())) {
                continue;
            }
            // The admin order create form puts the submitted account values on the quote as customer_<code>. Only there: in any other
            // area the key can be a field of the quote the customer sets himself (customer_note through the cart API).
            $quoteKey = 'customer_' . $code;
            if ($isAdmin && $quote->hasData($quoteKey)) {
                $raw = $quote->getData($quoteKey);
            } elseif ($typedInCheckout && in_array($code, $checkoutCodes, true)) {
                $raw = $checkoutValues[$code] ?? null;
            } elseif ($customer !== null) {
                $raw = $customer->getCustomAttribute($code)?->getValue();
            } else {
                continue;
            }
            $values[$code] = $this->normalizer->normalizeForInput((string) $attribute->getFrontendInput(), $raw);
        }
        // Attributes hidden by a dependency rule or a customer group restriction are not part of the order.
        $values = $this->visibility->dropHidden('customer', $values, (int) $quote->getCustomerGroupId());

        $rows = [];
        foreach ($attributes as $code => $attribute) {
            $value = $values[$code] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $rows[] = $this->rowBuilder->build(
                $code,
                $attribute->getStoreLabel($order->getStoreId()),
                (string) $attribute->getFrontendInput(),
                $value,
                $this->optionLabels->forAttribute($attribute, $order->getStoreId())
            );
        }
        $order->setData(self::DATA_KEY, $rows);
    }

    private function isAdmin(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (LocalizedException) {
            return false;
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
