<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Resolves the text to show for a stored value at order time, so the order never needs the live attribute again.
 * select / multiselect / boolean values are option ids and are mapped through the option list; a date is shown without
 * its time part ("YYYY-MM-DD", the stored value keeps the time); everything else is shown as stored.
 *
 * Pure PHP, no Magento imports.
 */
class ValueLabelResolver
{
    private const OPTION_INPUT_TYPES = ['select', 'multiselect', 'boolean'];

    /**
     * @param array<string, string> $optionLabels option value => label
     */
    public function resolve(string $inputType, ?string $value, array $optionLabels): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($inputType === 'date' && preg_match('/^(\d{4}-\d{2}-\d{2})[ T]/', $value, $matches) === 1) {
            return $matches[1];
        }
        if (!in_array($inputType, self::OPTION_INPUT_TYPES, true)) {
            return $value;
        }

        $labels = [];
        foreach (explode(ValueNormalizer::MULTI_VALUE_SEPARATOR, $value) as $id) {
            $labels[] = $optionLabels[$id] ?? $id;
        }
        return implode(', ', $labels);
    }
}
