<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Checks an attribute code before it is created. Attribute values are carried as data keys on quote and order
 * objects, so a code must not collide with a column of those tables.
 *
 * Pure PHP, no Magento imports.
 */
class AttributeCodeValidator
{
    /**
     * @param string[] $reservedCodes codes that may not be used (column names of the related tables)
     * @return string|null error message, null when the code is valid
     */
    public function validate(string $code, int $maxLength, array $reservedCodes = []): ?string
    {
        if ($code === '') {
            return 'Attribute code is required.';
        }
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
            return 'Attribute code may only contain lowercase letters, digits and underscores, and must start with a letter.';
        }
        if (strlen($code) > $maxLength) {
            return sprintf('Attribute code must not be longer than %d characters.', $maxLength);
        }
        if (in_array($code, $reservedCodes, true)) {
            return sprintf('"%s" is reserved and cannot be used as an attribute code.', $code);
        }
        return null;
    }
}
