<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Turns the per-website values posted by the attribute edit form into rows for core's website scope table
 * (customer_eav_attribute_website): enabled, required and default value. An empty choice means "use the default", stored
 * as NULL, which core reads as "no override".
 *
 * Pure PHP, no Magento imports.
 */
class WebsiteScopeNormalizer
{
    public const MAX_DEFAULT_LENGTH = 1000;

    /**
     * @param array<mixed> $posted scope[<website id>][is_visible|is_required|default_value]
     * @param string $inputType the attribute's input type
     * @param int[] $websiteIds the websites that exist
     * @return array{rows: array<int, array{is_visible: int|null, is_required: int|null, default_value: string|null}>, errors: string[]}
     */
    public function normalize(array $posted, string $inputType, array $websiteIds): array
    {
        $rows = [];
        $errors = [];
        foreach ($websiteIds as $websiteId) {
            $values = $posted[$websiteId] ?? null;
            if (!is_array($values)) {
                continue;
            }
            $rows[$websiteId] = [
                'is_visible' => $this->flag($values['is_visible'] ?? '', 'Enabled', $websiteId, $errors),
                'is_required' => $this->flag($values['is_required'] ?? '', 'Required', $websiteId, $errors),
                'default_value' => $this->defaultValue($values['default_value'] ?? '', $inputType, $websiteId, $errors),
            ];
        }
        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param string[] $errors
     */
    private function flag(mixed $value, string $label, int $websiteId, array &$errors): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if ($value === '0' || $value === '1' || $value === 0 || $value === 1) {
            return (int) $value;
        }
        $errors[] = sprintf('"%s" of website %d must be Use Default, Yes or No.', $label, $websiteId);
        return null;
    }

    /**
     * @param string[] $errors
     */
    private function defaultValue(mixed $value, string $inputType, int $websiteId, array &$errors): ?string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        if (!in_array($inputType, ['text', 'textarea', 'boolean'], true)) {
            return null;
        }
        if ($inputType === 'boolean' && $value !== '0' && $value !== '1') {
            $errors[] = sprintf('The default value of website %d must be Yes or No.', $websiteId);
            return null;
        }
        if (mb_strlen($value) > self::MAX_DEFAULT_LENGTH) {
            $errors[] = sprintf('The default value of website %d is longer than %d characters.', $websiteId, self::MAX_DEFAULT_LENGTH);
            return null;
        }
        return $value;
    }
}
