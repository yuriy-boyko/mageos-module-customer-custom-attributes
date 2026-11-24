<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * The input types an admin can pick and how each one is stored (EAV backend type, backend and source model).
 *
 * Pure PHP, no Magento imports.
 */
class InputTypeMap
{
    private const TYPES = [
        'text' => [
            'label' => 'Text Field',
            'backend_type' => 'varchar',
            'backend_model' => null,
            'source_model' => null,
            'default_field' => 'default_value_text',
        ],
        'textarea' => [
            'label' => 'Text Area',
            'backend_type' => 'text',
            'backend_model' => null,
            'source_model' => null,
            'default_field' => 'default_value_textarea',
        ],
        'date' => [
            'label' => 'Date',
            'backend_type' => 'datetime',
            'backend_model' => 'Magento\Eav\Model\Entity\Attribute\Backend\Datetime',
            'source_model' => null,
            'default_field' => null,
        ],
        'boolean' => [
            'label' => 'Yes/No',
            'backend_type' => 'int',
            'backend_model' => null,
            'source_model' => 'Magento\Eav\Model\Entity\Attribute\Source\Boolean',
            'default_field' => 'default_value_yesno',
        ],
        'select' => [
            'label' => 'Dropdown',
            'backend_type' => 'int',
            'backend_model' => null,
            'source_model' => 'Magento\Eav\Model\Entity\Attribute\Source\Table',
            'default_field' => null,
        ],
        'multiselect' => [
            'label' => 'Multiple Select',
            'backend_type' => 'varchar',
            'backend_model' => 'Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend',
            'source_model' => 'Magento\Eav\Model\Entity\Attribute\Source\Table',
            'default_field' => null,
        ],
    ];

    public function isSupported(?string $inputType): bool
    {
        return $inputType !== null && isset(self::TYPES[$inputType]);
    }

    /**
     * @return array<string, string> input type => label (not translated)
     */
    public function getLabels(): array
    {
        return array_map(static fn (array $type): string => $type['label'], self::TYPES);
    }

    public function getBackendType(string $inputType): string
    {
        return self::TYPES[$inputType]['backend_type'];
    }

    public function getBackendModel(string $inputType): ?string
    {
        return self::TYPES[$inputType]['backend_model'];
    }

    public function getSourceModel(string $inputType): ?string
    {
        return self::TYPES[$inputType]['source_model'];
    }

    /**
     * Name of the form field that holds the default value for this type, or null when the type has none
     * (select / multiselect take their default from the option list).
     */
    public function getDefaultValueField(string $inputType): ?string
    {
        return self::TYPES[$inputType]['default_field'] ?? null;
    }

    public function usesOptions(?string $inputType): bool
    {
        return $inputType === 'select' || $inputType === 'multiselect';
    }

    /**
     * True for a dropdown or multiple select whose options come from a source model written by a developer (an install or data patch),
     * not from the option table the admin form manages. Its options are read-only in the admin.
     */
    public function hasDeveloperOptions(?string $inputType, ?string $sourceModel): bool
    {
        if (!$this->usesOptions($inputType)) {
            return false;
        }
        $sourceModel = ltrim(trim((string) $sourceModel), '\\');
        return $sourceModel !== '' && strcasecmp($sourceModel, 'Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Table') !== 0;
    }

    public function supportsTextRules(?string $inputType): bool
    {
        return $inputType === 'text' || $inputType === 'textarea';
    }
}
