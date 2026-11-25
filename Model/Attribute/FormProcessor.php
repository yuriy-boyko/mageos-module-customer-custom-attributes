<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\Attribute;

use MageOS\CustomerCustomAttributes\Service\AdminOnlyAccess;
use MageOS\CustomerCustomAttributes\Service\AttributeCodeValidator;
use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use MageOS\CustomerCustomAttributes\Service\InputTypeMap;
use MageOS\CustomerCustomAttributes\Service\OptionKeyGuard;
use MageOS\CustomerCustomAttributes\Service\SettingConflicts;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use Magento\Customer\Model\Attribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\SetFactory;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as OptionCollectionFactory;
use Magento\Framework\Filter\FilterManager;

/**
 * Turns the posted attribute form into the data saved on the EAV attribute and collects every validation error.
 * Shared by the Save and Validate actions, so both apply the same rules.
 */
class FormProcessor
{
    public function __construct(
        private readonly InputTypeMap $inputTypes,
        private readonly ValidateRulesBuilder $rulesBuilder,
        private readonly AttributeCodeValidator $codeValidator,
        private readonly ReservedCodeProvider $reservedCodes,
        private readonly EavConfig $eavConfig,
        private readonly SetFactory $attributeSetFactory,
        private readonly FilterManager $filterManager,
        private readonly OptionCollectionFactory $optionCollectionFactory,
        private readonly AdminOnlyAccess $adminOnly,
        private readonly SettingConflicts $conflicts
    ) {
    }

    /**
     * @param array<string, mixed> $post posted form data, with the options already merged in
     * @param Attribute|null $existing the attribute being edited, null for a new one
     * @return array{data: array<string, mixed>, errors: string[]}
     */
    public function process(EntityContext $context, array $post, ?Attribute $existing): array
    {
        $errors = [];
        $data = [];

        if ($existing !== null) {
            $code = (string) $existing->getAttributeCode();
            $inputType = (string) $existing->getFrontendInput();
        } else {
            $code = trim((string) ($post['attribute_code'] ?? ''));
            $inputType = (string) ($post['frontend_input'] ?? '');
            $this->validateNewAttribute($context, $code, $inputType, $errors);
        }
        $data['attribute_code'] = $code;
        $data['frontend_input'] = $inputType;

        $data['frontend_label'] = $this->prepareLabels($post['frontend_label'] ?? [], $errors);
        $data['is_required'] = empty($post['is_required']) ? 0 : 1;
        $data['is_visible'] = array_key_exists('is_visible', $post) ? ((int) $post['is_visible'] ? 1 : 0) : 1;
        $data['sort_order'] = $this->prepareSortOrder($post['sort_order'] ?? 0, $errors);
        $data['used_in_forms'] = $context->resolveForms(
            (array) ($post['used_in_forms'] ?? []),
            $existing !== null ? array_values((array) $existing->getUsedInForms()) : []
        );

        if ($context->getKey() === EntityDefinitions::CUSTOMER
            && $this->adminOnly->conflictsWithRequired(!empty($post['admin_only']), (bool) $data['is_required'], $data['used_in_forms'])
        ) {
            $errors[] = (string) __('An attribute the customer cannot change cannot be required in the registration or account forms.');
        }
        if ($context->getKey() === EntityDefinitions::CUSTOMER) {
            foreach ($this->conflicts->messages(
                !empty($post['admin_only']),
                !empty($post['checkout_save_to_account']),
                (new \MageOS\CustomerCustomAttributes\Service\EmailTypes())->normalize($post['show_in_email'] ?? null) !== [],
                (string) ($post['save_on_order'] ?? '1') !== '0',
                $data['used_in_forms']
            ) as $message) {
                $errors[] = (string) __($message);
            }
        }

        if ($this->inputTypes->isSupported($inputType)) {
            $rules = $this->rulesBuilder->build($inputType, $post);
            $errors = array_merge($errors, array_map(static fn (string $message): string => (string) __($message), $rules['errors']));
            $data['validate_rules'] = $rules['rules'];
            $data['frontend_class'] = $rules['frontend_class'];
            $data['default_value'] = $this->prepareDefaultValue($inputType, $post);

            // An attribute with a developer's source model keeps its options: nothing is validated or saved for them.
            if ($this->inputTypes->usesOptions($inputType)
                && !($existing !== null && $this->inputTypes->hasDeveloperOptions($inputType, (string) $existing->getSourceModel()))
            ) {
                $options = is_array($post['option'] ?? null) ? $post['option'] : [];
                $this->validateOptions($options, $errors);
                $ownIds = $existing !== null && $existing->getId()
                    ? $this->optionCollectionFactory->create()->setAttributeFilter((int) $existing->getId())->getAllIds()
                    : [];
                if (OptionKeyGuard::foreignIds($options, $ownIds) !== []) {
                    $errors[] = (string) __('The options do not belong to this attribute. Reload the page and try again.');
                }
                $data['option'] = $options;
                $data['default'] = is_array($post['default'] ?? null) ? $post['default'] : [];
            }
        }

        if ($existing === null && $this->inputTypes->isSupported($inputType)) {
            $entityType = $context->getEntityType();
            $setId = (int) $entityType->getDefaultAttributeSetId();
            $data += [
                'entity_type_id' => $entityType->getId(),
                'backend_type' => $this->inputTypes->getBackendType($inputType),
                'backend_model' => $this->inputTypes->getBackendModel($inputType),
                'source_model' => $this->inputTypes->getSourceModel($inputType),
                'is_user_defined' => 1,
                'is_system' => 0,
                'attribute_set_id' => $setId,
                'attribute_group_id' => $this->attributeSetFactory->create()->getDefaultGroupId($setId),
            ];
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * @param string[] $errors
     */
    private function validateNewAttribute(EntityContext $context, string $code, string $inputType, array &$errors): void
    {
        if (!$this->inputTypes->isSupported($inputType)) {
            $errors[] = (string) __('Please select a valid input type.');
        }

        $codeError = $this->codeValidator->validate($code, $context->getMaxCodeLength(), $this->reservedCodes->get($context->getKey()));
        if ($codeError !== null) {
            $errors[] = (string) __($codeError);
            return;
        }

        $existing = $this->eavConfig->getAttribute($context->getEntityTypeCode(), $code);
        if ($existing && $existing->getId()) {
            $errors[] = (string) __('An attribute with the code "%1" already exists.', $code);
        }
    }

    /**
     * @param mixed $labels store id => label
     * @param string[] $errors
     * @return array<int|string, string>
     */
    private function prepareLabels(mixed $labels, array &$errors): array
    {
        $result = [];
        foreach ((array) $labels as $storeId => $label) {
            $result[$storeId] = trim($this->filterManager->stripTags((string) $label));
        }
        if (($result[0] ?? '') === '') {
            $errors[] = (string) __('The default label is required.');
            $result[0] = '';
        }
        return $result;
    }

    /**
     * @param string[] $errors
     */
    private function prepareSortOrder(mixed $value, array &$errors): int
    {
        if ($value === '' || $value === null) {
            return 0;
        }
        if (!is_scalar($value) || !ctype_digit((string) $value)) {
            $errors[] = (string) __('Sort order must be a whole number, zero or greater.');
            return 0;
        }
        return (int) $value;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function prepareDefaultValue(string $inputType, array $post): ?string
    {
        $field = $this->inputTypes->getDefaultValueField($inputType);
        if ($field === null || !isset($post[$field]) || $post[$field] === '') {
            return null;
        }
        return $this->filterManager->stripTags((string) $post[$field]);
    }

    /**
     * Every kept option needs a unique, non-empty admin label, and a dropdown needs at least one option.
     *
     * @param array<string, mixed> $options
     * @param string[] $errors
     */
    private function validateOptions(array $options, array &$errors): void
    {
        $values = is_array($options['value'] ?? null) ? $options['value'] : [];
        $deleted = is_array($options['delete'] ?? null) ? $options['delete'] : [];

        $adminLabels = [];
        foreach ($values as $key => $labels) {
            if (!empty($deleted[$key])) {
                continue;
            }
            $label = trim((string) (is_array($labels) ? ($labels[0] ?? '') : ''));
            if ($label === '') {
                $errors[] = (string) __('The admin value of every option is required.');
                continue;
            }
            $adminLabels[] = $label;
        }
        if ($adminLabels === [] && !in_array((string) __('The admin value of every option is required.'), $errors, true)) {
            $errors[] = (string) __('Add at least one option.');
        }
        if (count($adminLabels) !== count(array_unique($adminLabels))) {
            $errors[] = (string) __('The admin value of each option must be unique.');
        }
    }
}
