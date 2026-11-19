<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\Checkout;

use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\Dependency\VisibilityService;
use MageOS\CustomerCustomAttributes\Model\OptionLabels;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\QuoteValueStorage;
use MageOS\CustomerCustomAttributes\Service\CheckoutPositions;
use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use MageOS\CustomerCustomAttributes\Service\ValueValidator;
use MageOS\CustomerCustomAttributes\ViewModel\FormAttributes;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Model\AttributeMetadataDataProvider;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Quote\Model\Quote;

/**
 * Customer attributes shown in their own sections of the checkout (customer attributes ticked for "Hyvä Checkout"): which
 * fields belong to a position, the values typed by the customer (kept on the quote, never written to the customer account),
 * the initial values (prefill from the customer, default value), the dependency and group rules and the validation at
 * order placement. No Hyvä class is used here; the checkout components of the companion module call this service.
 */
class CustomerCheckoutAttributes implements ResetAfterRequestInterface
{
    /** @var string[]|null */
    private ?array $codes = null;

    /**
     * The fields of the checkout form without values, built once per request: every section asks for them on each render.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $formFields = null;

    /**
     * The stored values by quote id, read once per request and dropped when values are saved: each of the seven sections reads
     * them several times per render.
     *
     * @var array<int, array<string, string>>
     */
    private array $values = [];

    public function __construct(
        private readonly FormAttributes $formAttributes,
        private readonly CustomerAttributeProvider $attributeProvider,
        private readonly AttributeMetadataDataProvider $metadataDataProvider,
        private readonly QuoteValueStorage $storage,
        private readonly ValueNormalizer $normalizer,
        private readonly ValidateRulesBuilder $rulesBuilder,
        private readonly VisibilityService $visibility,
        private readonly ValueValidator $validator,
        private readonly OptionLabels $optionLabels,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    /**
     * Codes of the attributes shown in the checkout (assigned to the checkout form and enabled for the current website).
     *
     * @return string[]
     */
    public function getCodes(): array
    {
        if ($this->codes === null) {
            $this->codes = [];
            $user = $this->attributeProvider->getUserDefined();
            $collection = $this->metadataDataProvider->loadAttributesCollection(
                CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
                EntityDefinitions::FORM_HYVA_CHECKOUT
            );
            foreach ($collection->getItems() as $item) {
                $code = (string) $item->getAttributeCode();
                if ((int) $item->getIsVisible() && isset($user[$code])) {
                    $this->codes[] = $code;
                }
            }
        }
        return $this->codes;
    }

    /**
     * @return array<string, array{position: string, sort: int, prefill: bool}> by attribute code
     */
    public function getSettings(): array
    {
        $user = $this->attributeProvider->getUserDefined();
        $settings = [];
        foreach ($this->getCodes() as $code) {
            $settings[$code] = $this->rulesBuilder->getCheckoutSettings((array) $user[$code]->getValidateRules());
        }
        return $settings;
    }

    /**
     * @return string[] codes of the attributes shown at a position, in checkout order
     */
    public function getCodesForPosition(string $position): array
    {
        $codes = [];
        $index = 0;
        foreach ($this->getSettings() as $code => $setting) {
            if ($setting['position'] === $position) {
                $codes[] = ['code' => $code, 'sort' => $setting['sort'], 'index' => $index++];
            }
        }
        usort($codes, static fn (array $a, array $b): int => [$a['sort'], $a['index']] <=> [$b['sort'], $b['index']]);
        return array_column($codes, 'code');
    }

    public function hasPosition(string $position): bool
    {
        return $this->getCodesForPosition($position) !== [];
    }

    /**
     * The values stored on the quote, by attribute code.
     *
     * @return array<string, string>
     */
    public function getValues(Quote $quote): array
    {
        $quoteId = (int) $quote->getId();
        if ($quoteId <= 0 || $this->getCodes() === []) {
            return [];
        }
        return $this->values[$quoteId] ??= $this->readValues($quoteId);
    }

    /**
     * @return array<string, string>
     */
    private function readValues(int $quoteId): array
    {
        $user = $this->attributeProvider->getUserDefined();
        $byId = [];
        foreach ($this->getCodes() as $code) {
            $byId[(int) $user[$code]->getAttributeId()] = $code;
        }
        $values = [];
        foreach ($this->storage->load($quoteId) as $attributeId => $value) {
            if (isset($byId[$attributeId])) {
                $values[$byId[$attributeId]] = $value;
            }
        }
        return $values;
    }

    /**
     * Form fields of one position in checkout order, with the stored values and the visibility for the current values.
     *
     * @return list<array<string, mixed>> field arrays as built for the storefront forms, plus "visible"
     */
    public function getFields(Quote $quote, string $position): array
    {
        // Most positions have no attribute: no queries for them.
        if (!$this->hasPosition($position)) {
            return [];
        }
        $settings = $this->getSettings();
        $stored = $this->getValues($quote);
        $visible = $this->getVisibleCodes($quote, $stored);

        $this->formFields ??= $this->formAttributes->getCustomerFields(EntityDefinitions::FORM_HYVA_CHECKOUT);
        $fields = [];
        foreach ($this->formFields as $index => $field) {
            $code = $field['code'];
            if (!isset($settings[$code]) || $settings[$code]['position'] !== $position) {
                continue;
            }
            $field['value'] = $this->toFieldValue($field['input'], $stored[$code] ?? '');
            $field['visible'] = in_array($code, $visible, true);
            $field['sort'] = $settings[$code]['sort'];
            $field['index'] = $index;
            $fields[] = $field;
        }
        usort($fields, static fn (array $a, array $b): int => [$a['sort'], $a['index']] <=> [$b['sort'], $b['index']]);
        return $fields;
    }

    /**
     * Normalises and stores the submitted values of some attributes. Values of attributes that the rules or the customer
     * group hide are dropped, also when they belong to another position. Returns the values now on the quote.
     *
     * @param array<string, mixed> $submitted raw values by attribute code (only the attributes of the form that was posted)
     * @return array<string, string>
     */
    public function saveValues(Quote $quote, array $submitted): array
    {
        $user = $this->attributeProvider->getUserDefined();
        $values = array_fill_keys($this->getCodes(), null);
        foreach ($this->getValues($quote) as $code => $value) {
            $values[$code] = $value;
        }
        foreach ($submitted as $code => $raw) {
            if (array_key_exists($code, $values)) {
                $values[$code] = $this->normalizer->normalizeForInput((string) $user[$code]->getFrontendInput(), $raw);
            }
        }
        $values = $this->visibility->dropHidden('customer', $values, (int) $quote->getCustomerGroupId());

        $rows = [];
        foreach ($values as $code => $value) {
            $rows[(int) $user[$code]->getAttributeId()] = $value;
        }
        $this->storage->save((int) $quote->getId(), $rows);
        unset($this->values[(int) $quote->getId()]);
        return array_filter($values, static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * Fills the attributes that have no value on the quote yet: the saved value of the logged-in customer when the attribute
     * is set to prefill, otherwise the attribute's default value. Attributes that already have a value are not touched.
     */
    public function initialize(Quote $quote): void
    {
        $settings = $this->getSettings();
        if ($settings === [] || !(int) $quote->getId()) {
            return;
        }
        $stored = $this->getValues($quote);
        $customer = null;
        if ((int) $quote->getCustomerId() > 0) {
            try {
                $customer = $this->customerRepository->getById((int) $quote->getCustomerId());
            } catch (NoSuchEntityException) {
                $customer = null;
            }
        }

        $user = $this->attributeProvider->getUserDefined();
        $initial = [];
        foreach ($settings as $code => $setting) {
            if (($stored[$code] ?? '') !== '') {
                continue;
            }
            $input = (string) $user[$code]->getFrontendInput();
            $raw = $setting['prefill'] ? $customer?->getCustomAttribute($code)?->getValue() : null;
            $value = $this->normalizer->normalizeForInput($input, $raw);
            if ($value === null || $value === '') {
                $value = $this->normalizer->normalizeForInput($input, $user[$code]->getDefaultValue());
            }
            if ($value !== null && $value !== '') {
                $initial[$code] = $value;
            }
        }
        if ($initial !== []) {
            $this->saveValues($quote, $initial);
        }
    }

    /**
     * @param array<string, string>|null $values stored values, read from the quote when omitted
     * @return string[]
     */
    public function getVisibleCodes(Quote $quote, ?array $values = null): array
    {
        $values ??= $this->getValues($quote);
        $all = array_replace(array_fill_keys($this->getCodes(), null), $values);
        return $this->visibility->getVisibleCodes('customer', array_keys($all), $all, (int) $quote->getCustomerGroupId());
    }

    /**
     * The values that belong on the order: visible attributes with a value.
     *
     * @return array<string, string>
     */
    public function getOrderValues(Quote $quote): array
    {
        $stored = $this->getValues($quote);
        $visible = $this->getVisibleCodes($quote, $stored);
        return array_filter(
            array_intersect_key($stored, array_flip($visible)),
            static fn (string $value): bool => $value !== ''
        );
    }

    /**
     * Messages for the visible attributes: required values and the attribute rules.
     *
     * @param string[]|null $onlyCodes check only these attributes (one position), all when omitted
     * @return string[]
     */
    public function validate(Quote $quote, ?array $onlyCodes = null): array
    {
        $user = $this->attributeProvider->getUserDefined();
        $stored = $this->getValues($quote);
        $visible = $this->getVisibleCodes($quote, $stored);

        $settings = $this->getSettings();
        $messages = [];
        foreach ($this->getCodes() as $code) {
            if (!in_array($code, $visible, true) || ($onlyCodes !== null && !in_array($code, $onlyCodes, true))) {
                continue;
            }
            // A virtual-only cart has no shipping step: attributes placed there cannot be filled in and are not required.
            if ($quote->isVirtual() && CheckoutPositions::isShippingPosition($settings[$code]['position'] ?? '')) {
                continue;
            }
            $attribute = $user[$code];
            foreach ($this->validator->validate(
                (string) $attribute->getStoreLabel($quote->getStoreId()),
                (string) $attribute->getFrontendInput(),
                (bool) $attribute->getIsRequired(),
                (array) $attribute->getValidateRules(),
                ($stored[$code] ?? '') === '' ? null : $stored[$code],
                array_map('strval', array_keys($this->optionLabels->forAttribute($attribute)))
            ) as $error) {
                $messages[] = (string) __($error['message'], ...$error['args']);
            }
        }
        return $messages;
    }

    public function _resetState(): void
    {
        $this->codes = null;
        $this->formFields = null;
        $this->values = [];
    }

    /**
     * @return string|list<string>
     */
    private function toFieldValue(string $input, string $stored): string|array
    {
        if ($input === 'multiselect') {
            return $stored === '' ? [] : explode(ValueNormalizer::MULTI_VALUE_SEPARATOR, $stored);
        }
        if ($input === 'date' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $stored, $matches) === 1) {
            return $matches[1];
        }
        return $stored;
    }
}
