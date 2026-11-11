<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Checks a normalised attribute value against the attribute definition: required, text length, input validation
 * (numeric, alpha, alphanumeric, email, URL), date range and allowed options. The same rules the storefront forms get as
 * HTML attributes, enforced on the server wherever values are accepted (order placement, admin forms).
 *
 * Returns message templates with arguments instead of translated text, so the Magento adapter translates them.
 *
 * Pure PHP, no Magento imports.
 */
class ValueValidator
{
    public const MSG_REQUIRED = '"%1" is a required value.';
    public const MSG_MIN_LENGTH = '"%1" length must be equal or greater than %2 characters.';
    public const MSG_MAX_LENGTH = '"%1" length must be equal or less than %2 characters.';
    public const MSG_NUMERIC = '"%1" contains non-numeric characters.';
    public const MSG_ALPHA = '"%1" contains non-alphabetic characters.';
    public const MSG_ALPHANUMERIC = '"%1" contains non-alphanumeric characters.';
    public const MSG_EMAIL = '"%1" is not a valid email address.';
    public const MSG_URL = '"%1" is not a valid URL.';
    public const MSG_DATE_FORMAT = '"%1" is not a valid date.';
    public const MSG_DATE_MIN = '"%1" must be a date on or after %2.';
    public const MSG_DATE_MAX = '"%1" must be a date on or before %2.';
    public const MSG_OPTION = '"%1" has an invalid value.';

    private const PATTERNS = [
        'numeric' => ['/^[0-9]+$/', self::MSG_NUMERIC],
        'alpha' => ['/^[A-Za-z]+$/', self::MSG_ALPHA],
        'alphanumeric' => ['/^[A-Za-z0-9]+$/', self::MSG_ALPHANUMERIC],
    ];

    /**
     * @param array<string, mixed> $rules the attribute's validate rules
     * @param string|null $value normalised value (multiselect: comma separated), null or '' when empty
     * @param string[] $optionIds allowed option values of a select or multiselect
     * @return list<array{message: string, args: list<string>}>
     */
    public function validate(
        string $label,
        string $inputType,
        bool $required,
        array $rules,
        ?string $value,
        array $optionIds = []
    ): array {
        if ($value === null || $value === '') {
            return $required ? [$this->error(self::MSG_REQUIRED, $label)] : [];
        }

        return match ($inputType) {
            'text', 'textarea' => $this->validateText($label, $inputType, $rules, $value),
            'date' => $this->validateDate($label, $rules, $value),
            'select' => in_array($value, $optionIds, true) ? [] : [$this->error(self::MSG_OPTION, $label)],
            'multiselect' => $this->validateMultiselect($label, $value, $optionIds),
            'boolean' => in_array($value, ['0', '1'], true) ? [] : [$this->error(self::MSG_OPTION, $label)],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $rules
     * @return list<array{message: string, args: list<string>}>
     */
    private function validateText(string $label, string $inputType, array $rules, string $value): array
    {
        $errors = [];
        $length = mb_strlen($value);
        $min = (int) ($rules['min_text_length'] ?? 0);
        $max = (int) ($rules['max_text_length'] ?? 0);
        if ($min > 0 && $length < $min) {
            $errors[] = $this->error(self::MSG_MIN_LENGTH, $label, (string) $min);
        }
        if ($max > 0 && $length > $max) {
            $errors[] = $this->error(self::MSG_MAX_LENGTH, $label, (string) $max);
        }

        $validation = (string) ($rules['input_validation'] ?? '');
        if ($inputType === 'text' && $validation !== '') {
            if (isset(self::PATTERNS[$validation])) {
                if (preg_match(self::PATTERNS[$validation][0], $value) !== 1) {
                    $errors[] = $this->error(self::PATTERNS[$validation][1], $label);
                }
            } elseif ($validation === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = $this->error(self::MSG_EMAIL, $label);
            } elseif ($validation === 'url' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                $errors[] = $this->error(self::MSG_URL, $label);
            }
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $rules
     * @return list<array{message: string, args: list<string>}>
     */
    private function validateDate(string $label, array $rules, string $value): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: \d{2}:\d{2}:\d{2})?$/', $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            return [$this->error(self::MSG_DATE_FORMAT, $label)];
        }
        $date = $m[1] . '-' . $m[2] . '-' . $m[3];

        $errors = [];
        $min = (int) ($rules['date_range_min'] ?? 0);
        $max = (int) ($rules['date_range_max'] ?? 0);
        if ($min !== 0 && $date < gmdate('Y-m-d', $min)) {
            $errors[] = $this->error(self::MSG_DATE_MIN, $label, gmdate('Y-m-d', $min));
        }
        if ($max !== 0 && $date > gmdate('Y-m-d', $max)) {
            $errors[] = $this->error(self::MSG_DATE_MAX, $label, gmdate('Y-m-d', $max));
        }
        return $errors;
    }

    /**
     * @param string[] $optionIds
     * @return list<array{message: string, args: list<string>}>
     */
    private function validateMultiselect(string $label, string $value, array $optionIds): array
    {
        foreach (explode(ValueNormalizer::MULTI_VALUE_SEPARATOR, $value) as $id) {
            if (!in_array($id, $optionIds, true)) {
                return [$this->error(self::MSG_OPTION, $label)];
            }
        }
        return [];
    }

    /**
     * @return array{message: string, args: list<string>}
     */
    private function error(string $message, string ...$args): array
    {
        return ['message' => $message, 'args' => array_values($args)];
    }
}
