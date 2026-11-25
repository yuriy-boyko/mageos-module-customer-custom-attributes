<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * What the "Shown in" column of the attribute grid says about the forms of an attribute: the storefront forms this module offers (by
 * their labels, in the order of the Show in Forms list), which of them are account forms (the pages where the customer edits the values
 * himself), and how many forms of other modules are assigned. The admin forms are not listed: every attribute is in them.
 *
 * Pure PHP, no Magento imports.
 */
class FormColumn
{
    /** forms where the customer types the value on an account page; core also accepts a value posted to them */
    private const ACCOUNT_FORMS = ['customer_account_create', 'customer_account_edit', 'customer_register_address', 'customer_address_edit'];

    private const ADMIN_PREFIX = 'adminhtml_';

    /**
     * @param array<string, string> $offered form code => label of the forms the module offers for this entity (translated)
     * @param string[] $forms the form codes of the attribute
     * @return array{items: list<array{label: string, account: bool}>, other: int}
     */
    public function describe(array $offered, array $forms): array
    {
        $items = [];
        foreach ($offered as $code => $label) {
            if (str_starts_with($code, self::ADMIN_PREFIX) || !in_array($code, $forms, true)) {
                continue;
            }
            $items[] = ['label' => $label, 'account' => in_array($code, self::ACCOUNT_FORMS, true)];
        }

        $other = 0;
        foreach (array_unique($forms) as $code) {
            if ($code !== '' && !isset($offered[$code]) && !str_starts_with($code, self::ADMIN_PREFIX)) {
                $other++;
            }
        }
        return ['items' => $items, 'other' => $other];
    }
}
