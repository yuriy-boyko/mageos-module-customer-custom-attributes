<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Describes the two attribute areas the admin manages: customer attributes and customer address attributes.
 * The admin URLs carry the area as the `entity` parameter (`customer` or `address`).
 *
 * Pure PHP, no Magento imports.
 */
class EntityDefinitions
{
    public const CUSTOMER = 'customer';
    public const ADDRESS = 'address';

    /**
     * Pseudo form codes. They are stored in the core form assignment table like real form codes and act as per
     * attribute switches: where an address attribute appears in the Hyvä Checkout address forms, and whether its value
     * is added to the address text.
     */
    public const FORM_HYVA_CHECKOUT = 'mageos_hyva_checkout';
    public const FORM_ADDRESS_TEXT = 'mageos_address_text';

    private const DEFINITIONS = [
        self::CUSTOMER => [
            'eav_entity_type' => 'customer',
            'label' => 'Customer Attribute',
            'plural_label' => 'Customer Attributes',
            'acl' => 'MageOS_CustomerCustomAttributes::customer_attributes',
            'menu' => 'MageOS_CustomerCustomAttributes::customer_attributes',
            // 51 leaves room for a "customer_" prefix inside the 60 character EAV code limit
            'max_code_length' => 51,
            'mandatory_forms' => ['adminhtml_customer'],
            'optional_forms' => [
                'customer_account_create' => 'Customer Registration',
                'customer_account_edit' => 'Customer Account Edit',
                'adminhtml_checkout' => 'Admin Order Create (Account Information)',
                self::FORM_HYVA_CHECKOUT => 'Hyvä Checkout (own section, values kept on the order only)',
            ],
        ],
        self::ADDRESS => [
            'eav_entity_type' => 'customer_address',
            'label' => 'Customer Address Attribute',
            'plural_label' => 'Customer Address Attributes',
            'acl' => 'MageOS_CustomerCustomAttributes::customer_address_attributes',
            'menu' => 'MageOS_CustomerCustomAttributes::customer_address_attributes',
            'max_code_length' => 60,
            'mandatory_forms' => ['adminhtml_customer_address'],
            'optional_forms' => [
                'customer_register_address' => 'Registration Address Form',
                'customer_address_edit' => 'Address Book (Customer Account)',
                self::FORM_HYVA_CHECKOUT => 'Hyvä Checkout Address Forms',
                self::FORM_ADDRESS_TEXT => 'Address Text (checkout cards, account, order pages, emails, admin views)',
            ],
        ],
    ];

    /**
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function isValidKey(?string $key): bool
    {
        return $key !== null && isset(self::DEFINITIONS[$key]);
    }

    /**
     * Unknown or missing keys fall back to the customer area.
     */
    public function resolveKey(?string $key): string
    {
        return $this->isValidKey($key) ? (string) $key : self::CUSTOMER;
    }

    /**
     * @return array{eav_entity_type: string, label: string, plural_label: string, acl: string, menu: string, max_code_length: int, mandatory_forms: string[], optional_forms: array<string, string>}
     */
    public function get(?string $key): array
    {
        return self::DEFINITIONS[$this->resolveKey($key)];
    }

    /**
     * Admin forms an attribute always belongs to, merged with the forms the admin picked. Unknown form codes are
     * dropped, so a tampered request cannot assign an attribute to arbitrary forms. The stored forms that this module does not
     * offer (assigned by core or another module) are kept.
     *
     * @param string[] $selected
     * @param string[] $stored the forms the attribute has now (empty for a new attribute)
     * @return string[]
     */
    public function resolveForms(?string $key, array $selected, array $stored = []): array
    {
        $definition = $this->get($key);
        $allowed = array_keys($definition['optional_forms']);
        $forms = $definition['mandatory_forms'];
        foreach ($selected as $form) {
            if (is_string($form) && in_array($form, $allowed, true) && !in_array($form, $forms, true)) {
                $forms[] = $form;
            }
        }
        // Forms this module does not offer (core forms such as checkout_register, forms of other modules) are not on the screen:
        // keep their assignment, since saving the attribute rewrites all of its form rows.
        foreach ($stored as $form) {
            if (is_string($form) && $form !== '' && !in_array($form, $allowed, true) && !in_array($form, $forms, true)) {
                $forms[] = $form;
            }
        }
        return $forms;
    }
}
