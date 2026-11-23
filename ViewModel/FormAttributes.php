<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\ViewModel;

use MageOS\CustomerCustomAttributes\Service\VisibilityResolver;
use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\Dependency\RuleProvider;
use MageOS\CustomerCustomAttributes\Service\FieldAttributeBuilder;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\AttributeMetadataDataProvider;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The custom address attributes of a storefront form, ready to render: label, input type, options, current value and the
 * HTML validation attributes. The same view model serves every storefront form that shows address attributes.
 */
class FormAttributes implements ArgumentInterface
{
    public function __construct(
        private readonly AttributeMetadataDataProvider $metadataDataProvider,
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly CustomerAttributeProvider $customerAttributeProvider,
        private readonly FieldAttributeBuilder $attributeBuilder,
        private readonly ValueNormalizer $normalizer,
        private readonly RuleProvider $ruleProvider,
        private readonly CustomerSession $customerSession,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ValidateRulesBuilder $rulesBuilder
    ) {
    }

    /**
     * @param string $formCode e.g. "customer_address_edit"
     * @return list<array{code: string, label: string, input: string, required: bool, display: string, options: list<array{value: string, label: string}>, value: string|list<string>, attributes: array<string, string|int|bool>}>
     */
    public function getAddressFields(string $formCode, ?AddressInterface $address = null): array
    {
        return $this->buildFields(
            AddressMetadataInterface::ENTITY_TYPE_ADDRESS,
            $formCode,
            $this->attributeProvider->getUserDefined(),
            $address
        );
    }

    /**
     * The custom customer attributes of a storefront form, e.g. "customer_account_create" or "customer_account_edit".
     *
     * @return list<array{code: string, label: string, input: string, required: bool, display: string, options: list<array{value: string, label: string}>, value: string|list<string>, attributes: array<string, string|int|bool>}>
     */
    public function getCustomerFields(string $formCode, ?CustomerInterface $customer = null): array
    {
        return $this->buildFields(
            CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
            $formCode,
            $this->customerAttributeProvider->getUserDefined(),
            $customer
        );
    }

    /**
     * @param array<string, \Magento\Eav\Model\Entity\Attribute\AbstractAttribute> $userDefined
     * @return list<array<string, mixed>>
     */
    private function buildFields(string $entityType, string $formCode, array $userDefined, ?object $entity): array
    {
        $collection = $this->metadataDataProvider->loadAttributesCollection($entityType, $formCode);

        $fields = [];
        foreach ($collection->getItems() as $item) {
            $code = (string) $item->getAttributeCode();
            if (!isset($userDefined[$code]) || !(int) $item->getIsVisible()) {
                continue;
            }
            $attribute = $userDefined[$code];
            $input = (string) $attribute->getFrontendInput();
            $rules = (array) $attribute->getValidateRules();
            $required = (bool) $attribute->getIsRequired();

            $attributes = $this->attributeBuilder->build($input, $required, $rules);
            if (isset($attributes['pattern_message'])) {
                $attributes['title'] = (string) __($attributes['pattern_message']);
                unset($attributes['pattern_message']);
            }

            $fields[] = [
                'code' => $code,
                'label' => (string) $attribute->getStoreLabel(),
                'input' => $input,
                'required' => $required,
                'display' => $this->rulesBuilder->getDisplay($input, $rules),
                'options' => $this->getOptions($attribute, $input),
                'value' => $this->getValue($attribute, $input, $entity),
                'attributes' => $attributes,
            ];
        }
        return $fields;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function getOptions(\Magento\Eav\Model\Entity\Attribute\AbstractAttribute $attribute, string $input): array
    {
        if (!in_array($input, ['select', 'multiselect', 'boolean'], true) || !$attribute->usesSource()) {
            return [];
        }
        $options = [];
        foreach ($attribute->getSource()->getAllOptions(false) as $option) {
            if (($option['value'] ?? '') === '' || is_array($option['value'])) {
                continue;
            }
            $options[] = ['value' => (string) $option['value'], 'label' => (string) $option['label']];
        }
        return $options;
    }

    /**
     * @return string|list<string>
     */
    private function getValue(
        \Magento\Eav\Model\Entity\Attribute\AbstractAttribute $attribute,
        string $input,
        ?object $entity
    ): string|array {
        $custom = $entity?->getCustomAttribute((string) $attribute->getAttributeCode());
        $raw = $custom !== null ? $custom->getValue() : ($entity?->getId() ? null : $attribute->getDefaultValue());
        $value = $this->normalizer->normalize($raw) ?? '';

        if ($input === 'multiselect') {
            return $value === '' ? [] : explode(ValueNormalizer::MULTI_VALUE_SEPARATOR, $value);
        }
        if ($input === 'date' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches) === 1) {
            return $matches[1];
        }
        return $value;
    }

    /**
     * Data for the storefront script that hides the fields of attributes whose dependency rule or customer group does not
     * apply (the same rules the server enforces).
     *
     * @param string $entity "customer" or "address"
     * @param list<array{code: string}> $fields the fields of the form (see getAddressFields / getCustomerFields)
     * @return array{codes: string[], rules: list<array{parent: string, option: string, dependent: string}>, restrictions: object, groupId: int|null}
     */
    public function getVisibilityConfig(string $entity, array $fields): array
    {
        $groupId = $this->customerSession->isLoggedIn()
            ? (int) $this->customerSession->getCustomerGroupId()
            : (int) $this->scopeConfig->getValue('customer/create_account/default_group', ScopeInterface::SCOPE_STORE);
        return [
            'codes' => array_column($fields, 'code'),
            'rules' => $this->ruleProvider->getRules($entity),
            // only what applies to this customer group, not the list of groups
            'restrictions' => (object) VisibilityResolver::restrictionsForGroup($this->ruleProvider->getGroupRestrictions($entity), $groupId),
            'groupId' => $groupId,
        ];
    }
}
