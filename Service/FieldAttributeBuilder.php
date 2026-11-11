<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Maps an attribute's validation rules to the native HTML validation attributes of its form field (the Hyvä form
 * validation reads these), so the browser enforces the same rules the server applies.
 *
 * Pure PHP, no Magento imports.
 */
class FieldAttributeBuilder
{
    private const PATTERNS = [
        'numeric' => ['[0-9]+', 'Only numbers (0-9) are allowed.'],
        'alpha' => ['[A-Za-z]+', 'Only letters (a-z, A-Z) are allowed.'],
        'alphanumeric' => ['[A-Za-z0-9]+', 'Only letters (a-z, A-Z) and numbers (0-9) are allowed.'],
    ];

    /**
     * @param array<string, mixed> $rules the attribute's validate_rules
     * @return array<string, string|int|bool> HTML attribute => value (true = attribute without value);
     *         "pattern_message" is returned for the caller to translate and put into "title"
     */
    public function build(string $inputType, bool $required, array $rules): array
    {
        $attributes = [];
        if ($required) {
            $attributes['required'] = true;
        }

        if ($inputType === 'text' || $inputType === 'textarea') {
            if ($inputType === 'text') {
                $attributes['type'] = 'text';
                $validation = (string) ($rules['input_validation'] ?? '');
                if ($validation === 'email' || $validation === 'url') {
                    $attributes['type'] = $validation;
                } elseif (isset(self::PATTERNS[$validation])) {
                    $attributes['pattern'] = self::PATTERNS[$validation][0];
                    $attributes['pattern_message'] = self::PATTERNS[$validation][1];
                }
            }
            if ((int) ($rules['min_text_length'] ?? 0) > 0) {
                $attributes['minlength'] = (int) $rules['min_text_length'];
            }
            if ((int) ($rules['max_text_length'] ?? 0) > 0) {
                $attributes['maxlength'] = (int) $rules['max_text_length'];
            }
        } elseif ($inputType === 'date') {
            if ((int) ($rules['date_range_min'] ?? 0) !== 0) {
                $attributes['min'] = gmdate('Y-m-d', (int) $rules['date_range_min']);
            }
            if ((int) ($rules['date_range_max'] ?? 0) !== 0) {
                $attributes['max'] = gmdate('Y-m-d', (int) $rules['date_range_max']);
            }
        }
        return $attributes;
    }
}
