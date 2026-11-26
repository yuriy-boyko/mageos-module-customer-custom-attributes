<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Magento checks every required customer attribute whenever a customer is saved (the EAV data validator), in every area. A required
 * attribute that is not in the registration form cannot be filled by a new customer, so the registration fails; one that is not in the
 * account edit form makes every account save of a customer without a value fail. This tells which of the two forms a required attribute
 * is missing from, so the admin can warn about it.
 *
 * Pure PHP, no Magento imports.
 */
class RequiredCustomerFormCheck
{
    public const REGISTRATION_FORM = 'customer_account_create';
    public const ACCOUNT_EDIT_FORM = 'customer_account_edit';

    /**
     * @param bool $required the attribute is required (for the whole store or for at least one website)
     * @param string[] $forms the form codes the attribute is assigned to
     * @param bool $adminOnly "Customer Cannot Change This Value": the field is never printed in the storefront forms
     * @return string[] the storefront forms (codes above) the customer cannot fill the attribute in, in that order; empty when not required
     */
    public function missingForms(bool $required, array $forms, bool $adminOnly = false): array
    {
        if (!$required) {
            return [];
        }
        $missing = [];
        foreach ([self::REGISTRATION_FORM, self::ACCOUNT_EDIT_FORM] as $form) {
            if ($adminOnly || !in_array($form, $forms, true)) {
                $missing[] = $form;
            }
        }
        return $missing;
    }
}
