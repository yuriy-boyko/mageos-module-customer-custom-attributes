<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Magento validates every required attribute of the address book form ("customer_address_edit") on the address of the quote at the
 * checkout. An address attribute that is required and in the address book form, but not in the Hyvä Checkout address forms, can never
 * be filled at the checkout, so no order can be placed. This tells when that is the case, so the admin can warn about it.
 *
 * Pure PHP, no Magento imports.
 */
class RequiredAddressFormCheck
{
    private const ADDRESS_BOOK_FORM = 'customer_address_edit';

    /**
     * @param bool $required the attribute is required (for the whole store or for at least one website)
     * @param string[] $forms the form codes the attribute is assigned to
     */
    public function blocksCheckout(bool $required, array $forms): bool
    {
        return $required
            && in_array(self::ADDRESS_BOOK_FORM, $forms, true)
            && !in_array(EntityDefinitions::FORM_HYVA_CHECKOUT, $forms, true);
    }

    /**
     * Whether the attribute is required anywhere: for the whole store, or by the setting of at least one website.
     *
     * @param array<int, array{is_required: int|null}> $websiteRows the per-website settings by website id (null: inherits)
     */
    public function isRequiredAnywhere(bool $requiredForStore, array $websiteRows): bool
    {
        foreach ($websiteRows as $row) {
            if ($row['is_required'] === 1) {
                return true;
            }
        }
        return $requiredForStore;
    }
}
