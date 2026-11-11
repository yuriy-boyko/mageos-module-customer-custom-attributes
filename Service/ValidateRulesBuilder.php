<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Turns the validation fields of the attribute form into the EAV `validate_rules` array and the matching
 * browser-side `frontend_class`, and reports invalid combinations.
 *
 * Core only applies min/max text length when an `input_validation` rule exists, so entering a length without a
 * validation type selects the "length" rule automatically.
 *
 * Pure PHP, no Magento imports.
 */
class ValidateRulesBuilder
{
    /** input_validation rule => browser validation class */
    public const INPUT_VALIDATIONS = [
        'alphanumeric' => 'validate-alphanum',
        'numeric' => 'validate-digits',
        'alpha' => 'validate-alpha',
        'url' => 'validate-url',
        'email' => 'validate-email',
        'length' => '',
    ];

    public const DATE_FORMAT = 'Y-m-d';

    /** Key in the validate rules (ignored by core) that holds how a dropdown, multiple select or yes/no is displayed. */
    public const DISPLAY_KEY = 'mageos_display';

    /** Keys in the validate rules for customer attributes shown in the checkout: position, sort order inside it, prefill from the customer. */
    public const CHECKOUT_POSITION_KEY = 'mageos_checkout_position';
    public const CHECKOUT_SORT_KEY = 'mageos_checkout_sort';
    public const CHECKOUT_PREFILL_KEY = 'mageos_checkout_prefill';

    public const DISPLAY_RADIO = 'radio';
    public const DISPLAY_CHECKBOX = 'checkbox';

    /**
     * @param array<string, mixed> $data posted form data
     * @return array{rules: array<string, int|string>, frontend_class: string, errors: string[]}
     */
    public function build(string $inputType, array $data): array
    {
        $rules = [];
        $frontendClass = '';
        $errors = [];

        if ($inputType === 'text' || $inputType === 'textarea') {
            $validation = (string) ($data['input_validation'] ?? '');
            if ($validation !== '' && !isset(self::INPUT_VALIDATIONS[$validation])) {
                $errors[] = 'Invalid input validation.';
                $validation = '';
            }

            $min = $this->parseLength($data['min_text_length'] ?? null, 'Minimum text length', $errors);
            $max = $this->parseLength($data['max_text_length'] ?? null, 'Maximum text length', $errors);
            if ($min > 0 && $max > 0 && $min > $max) {
                $errors[] = 'Minimum text length cannot be greater than the maximum text length.';
            }
            if ($validation === '' && ($min > 0 || $max > 0)) {
                $validation = 'length';
            }

            if ($validation !== '') {
                $rules['input_validation'] = $validation;
                if ($inputType === 'text') {
                    $frontendClass = self::INPUT_VALIDATIONS[$validation];
                }
            }
            if ($min > 0) {
                $rules['min_text_length'] = $min;
            }
            if ($max > 0) {
                $rules['max_text_length'] = $max;
            }
        } elseif (in_array($inputType, ['select', 'multiselect', 'boolean'], true)) {
            $display = (string) ($data['display_as'] ?? '');
            if ($display !== '' && $display !== $this->getAllowedDisplay($inputType)) {
                $errors[] = 'Invalid display type for this input type.';
            } elseif ($display !== '') {
                $rules[self::DISPLAY_KEY] = $display;
            }
        } elseif ($inputType === 'date') {
            $min = $this->parseDate($data['date_range_min'] ?? null, 'Minimum date', $errors);
            $max = $this->parseDate($data['date_range_max'] ?? null, 'Maximum date', $errors);
            if ($min !== null && $max !== null && $min > $max) {
                $errors[] = 'Minimum date cannot be later than the maximum date.';
            }
            if ($min !== null) {
                $rules['date_range_min'] = $min;
            }
            if ($max !== null) {
                $rules['date_range_max'] = $max;
            }
        }

        $position = (string) ($data['checkout_position'] ?? '');
        if ($position !== '') {
            if (!CheckoutPositions::isValid($position)) {
                $errors[] = 'Invalid checkout position.';
            } else {
                $rules[self::CHECKOUT_POSITION_KEY] = $position;
                $sort = trim((string) ($data['checkout_sort'] ?? ''));
                if ($sort !== '' && (preg_match('/^\d{1,6}$/', $sort) !== 1)) {
                    $errors[] = 'The checkout sort order must be a whole number.';
                } elseif ($sort !== '' && (int) $sort > 0) {
                    $rules[self::CHECKOUT_SORT_KEY] = (int) $sort;
                }
                if (!empty($data['checkout_prefill'])) {
                    $rules[self::CHECKOUT_PREFILL_KEY] = 1;
                }
            }
        }

        return ['rules' => $rules, 'frontend_class' => $frontendClass, 'errors' => $errors];
    }

    /**
     * The checkout settings of a customer attribute from its stored rules.
     *
     * @param array<string, mixed> $rules
     * @return array{position: string, sort: int, prefill: bool}
     */
    public function getCheckoutSettings(array $rules): array
    {
        $position = $rules[self::CHECKOUT_POSITION_KEY] ?? '';
        return [
            'position' => CheckoutPositions::isValid($position) ? (string) $position : CheckoutPositions::default(),
            'sort' => max(0, (int) ($rules[self::CHECKOUT_SORT_KEY] ?? 0)),
            'prefill' => !empty($rules[self::CHECKOUT_PREFILL_KEY]),
        ];
    }

    /**
     * Reverse of the date parsing, for filling the form from stored rules.
     */
    /**
     * How the options are drawn on the storefront: "radio" (dropdown and yes/no shown as radio buttons), "checkbox"
     * (multiple select shown as checkboxes) or "" (the default dropdown or list). The data stays a standard select or
     * multiselect value, so only the form changes.
     */
    public function getDisplay(string $inputType, array $rules): string
    {
        $display = (string) ($rules[self::DISPLAY_KEY] ?? '');
        $choiceInput = in_array($inputType, ['select', 'multiselect', 'boolean'], true);
        return $choiceInput && $display !== '' && $display === $this->getAllowedDisplay($inputType) ? $display : '';
    }

    private function getAllowedDisplay(string $inputType): string
    {
        return $inputType === 'multiselect' ? self::DISPLAY_CHECKBOX : self::DISPLAY_RADIO;
    }

    public function formatDate(mixed $timestamp): string
    {
        if (!is_numeric($timestamp) || (int) $timestamp === 0) {
            return '';
        }
        return gmdate(self::DATE_FORMAT, (int) $timestamp);
    }

    /**
     * @param string[] $errors
     */
    private function parseLength(mixed $value, string $label, array &$errors): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_scalar($value) || !ctype_digit((string) $value)) {
            $errors[] = sprintf('%s must be a whole number, zero or greater.', $label);
            return 0;
        }
        return (int) $value;
    }

    /**
     * @param string[] $errors
     */
    private function parseDate(mixed $value, string $label, array &$errors): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value, new \DateTimeZone('UTC')) : false;
        if ($date === false || $date->format(self::DATE_FORMAT) !== $value) {
            $errors[] = sprintf('%s must be a date in the format YYYY-MM-DD.', $label);
            return null;
        }
        return $date->getTimestamp();
    }
}
