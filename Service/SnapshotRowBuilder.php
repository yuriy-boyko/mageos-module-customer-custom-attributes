<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Builds the order-side snapshot row for one attribute value. The row is everything an order needs to show the value
 * later, with no reference to the attribute definition (which may be changed or deleted afterwards).
 *
 * Pure PHP, no Magento imports.
 */
class SnapshotRowBuilder
{
    public function __construct(private readonly ValueLabelResolver $labelResolver)
    {
    }

    /**
     * @param array<string, string> $optionLabels option value => label
     * @return array{attribute_code: string, attribute_label: string, input_type: string, value: ?string, value_label: ?string}
     */
    public function build(
        string $attributeCode,
        ?string $attributeLabel,
        string $inputType,
        ?string $value,
        array $optionLabels = []
    ): array {
        return [
            'attribute_code' => $attributeCode,
            'attribute_label' => ($attributeLabel === null || $attributeLabel === '') ? $attributeCode : $attributeLabel,
            'input_type' => $inputType,
            'value' => $value,
            'value_label' => $this->labelResolver->resolve($inputType, $value, $optionLabels),
        ];
    }
}
