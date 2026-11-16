<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Turns whatever a form, the REST API or Hyvä Checkout submits into the single string that is stored, or null
 * when there is nothing to store (the value row is then deleted instead of saving an empty string).
 *
 * Pure PHP, no Magento imports.
 */
class ValueNormalizer
{
    public const MULTI_VALUE_SEPARATOR = ',';

    public function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $part = $this->scalarToString($item);
                if ($part !== null) {
                    $parts[] = $part;
                }
            }
            return $parts === [] ? null : implode(self::MULTI_VALUE_SEPARATOR, $parts);
        }
        return $this->scalarToString($value);
    }

    /**
     * Like normalize(), with the rules of an input type. A date is always stored with date and time
     * ("YYYY-MM-DD HH:MM:SS"), the format of the EAV datetime backend: a plain date from a form gets "00:00:00", and a
     * value that went through the customer address keeps its time. The same value is then used on the quote, the order
     * and the address book.
     */
    public function normalizeForInput(string $inputType, mixed $value): ?string
    {
        $normalized = $this->normalize($value);
        if ($normalized === null || $inputType !== 'date') {
            return $normalized;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', $normalized, $matches) === 1) {
            return $matches[1] . ' 00:00:00';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.\d+)?$/', $normalized, $matches) === 1) {
            return $matches[1] . ' ' . $matches[2];
        }
        return $normalized;
    }

    /**
     * The chosen options of a multiple select as a list: an array stays a list of strings, a stored "1,2" is split, nothing gives an
     * empty list. A group of checkboxes needs an array as its starting value, or the browser sends a single true instead of the options.
     *
     * @return string[]
     */
    public function toList(mixed $value): array
    {
        $normalized = $this->normalize($value);
        return $normalized === null ? [] : explode(self::MULTI_VALUE_SEPARATOR, $normalized);
    }

    private function scalarToString(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }
        if ($value instanceof \Stringable) {
            $string = (string) $value;
            return $string === '' ? null : $string;
        }
        return null;
    }
}
