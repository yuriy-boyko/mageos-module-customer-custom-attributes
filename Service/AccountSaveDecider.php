<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * "Also Save to Customer Account": which of the values a logged-in customer typed in the checkout are written to the account.
 * A value is written when its attribute has the setting, it is not an admin-only attribute, the customer typed a value (an empty
 * value never erases the stored one) and the account has another value.
 *
 * Pure PHP, no Magento imports.
 */
class AccountSaveDecider
{
    /**
     * @param array<string, array<string, mixed>> $rulesByCode the validate rules of the attributes shown in the checkout, by code
     * @param array<string, string|null> $typed what the customer typed, by code (visible attributes only)
     * @param array<string, string|null> $account what the account has now, by code
     * @return array<string, string> the value to write, by code
     */
    public function valuesToSave(array $rulesByCode, array $typed, array $account, ValidateRulesBuilder $rules, AdminOnlyAccess $adminOnly): array
    {
        $write = [];
        foreach ($rulesByCode as $code => $attributeRules) {
            if (!$rules->savesToAccount($attributeRules) || $adminOnly->isAdminOnly($attributeRules)) {
                continue;
            }
            $value = $typed[$code] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ((string) ($account[$code] ?? '') !== $value) {
                $write[(string) $code] = $value;
            }
        }
        return $write;
    }
}
