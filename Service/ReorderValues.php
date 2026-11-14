<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Which values of an order address are carried to the new quote address when an order is reordered in the admin. Core copies only the
 * standard address fields; the custom values come from the order's snapshot rows. A value whose attribute no longer exists is left out
 * (the snapshot keeps it for the old order), and so is an empty value. Pure PHP, no Magento imports.
 */
class ReorderValues
{
    /**
     * @param array<int, array<string, mixed>> $snapshotRows rows of the order address (attribute_code, value, ...)
     * @param string[] $existingCodes codes of the address attributes that exist now
     * @return array<string, string> attribute code => raw value
     */
    public function forQuoteAddress(array $snapshotRows, array $existingCodes): array
    {
        $values = [];
        foreach ($snapshotRows as $row) {
            $code = (string) ($row['attribute_code'] ?? '');
            $value = $row['value'] ?? null;
            if ($code === '' || !in_array($code, $existingCodes, true) || $value === null || $value === '' || !is_scalar($value)) {
                continue;
            }
            $values[$code] = (string) $value;
        }
        return $values;
    }
}
