<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Combinations of the per-attribute settings of a customer attribute that cannot work together, as messages for the attribute screen
 * (not translated: the caller translates them):
 *  - saving the checkout value to the account needs the attribute in the checkout, and an admin-only value is never written by a customer;
 *  - an attribute in the order email must be saved on the order (the email reads the saved values) and is not an admin-only value.
 *
 * Pure PHP, no Magento imports.
 */
class SettingConflicts
{
    public const CHECKOUT_FORM = EntityDefinitions::FORM_HYVA_CHECKOUT;

    /**
     * @param string[] $forms the forms of the attribute
     * @return string[]
     */
    public function messages(bool $adminOnly, bool $saveToAccount, bool $showInEmail, bool $saveOnOrder, array $forms): array
    {
        $messages = [];
        if ($saveToAccount && $adminOnly) {
            $messages[] = 'An attribute the customer cannot change cannot be saved to the customer account from the checkout.';
        }
        if ($saveToAccount && !in_array(self::CHECKOUT_FORM, $forms, true)) {
            $messages[] = 'Also Save to Customer Account needs the attribute in Hyvä Checkout (Show in Forms).';
        }
        if ($showInEmail && $adminOnly) {
            $messages[] = 'An attribute the customer cannot change is an internal value: it cannot be shown in the order email.';
        }
        if ($showInEmail && !$saveOnOrder) {
            $messages[] = 'Show in Emails needs Save on Orders: the email shows the values saved on the order.';
        }
        return $messages;
    }
}
