<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\FormColumn;
use PHPUnit\Framework\TestCase;

class FormColumnTest extends TestCase
{
    private const CUSTOMER_FORMS = [
        'customer_account_create' => 'Customer Registration',
        'customer_account_edit' => 'Customer Account Edit',
        'adminhtml_checkout' => 'Admin Order Create (Account Information)',
        'mageos_hyva_checkout' => 'Hyvä Checkout',
    ];

    private const ADDRESS_FORMS = [
        'customer_register_address' => 'Registration Address Form',
        'customer_address_edit' => 'Address Book',
        'mageos_hyva_checkout' => 'Hyvä Checkout Address Forms',
        'mageos_address_text' => 'Address Text',
    ];

    private FormColumn $column;

    protected function setUp(): void
    {
        $this->column = new FormColumn();
    }

    public function testTheFormsAreListedInTheOrderOfTheShowInFormsList(): void
    {
        // the codes come from the database in alphabetical order
        $result = $this->column->describe(self::CUSTOMER_FORMS, ['adminhtml_customer', 'customer_account_edit', 'mageos_hyva_checkout']);

        self::assertSame(
            [['label' => 'Customer Account Edit', 'account' => true], ['label' => 'Hyvä Checkout', 'account' => false]],
            $result['items']
        );
        self::assertSame(0, $result['other']);
    }

    public function testTheAccountFormsAreMarked(): void
    {
        $customer = $this->column->describe(self::CUSTOMER_FORMS, ['customer_account_create', 'customer_account_edit']);
        self::assertSame([true, true], array_column($customer['items'], 'account'));

        $address = $this->column->describe(self::ADDRESS_FORMS, ['customer_register_address', 'customer_address_edit', 'mageos_hyva_checkout', 'mageos_address_text']);
        self::assertSame(['Registration Address Form', 'Address Book', 'Hyvä Checkout Address Forms', 'Address Text'], array_column($address['items'], 'label'));
        self::assertSame([true, true, false, false], array_column($address['items'], 'account'));
    }

    public function testTheAdminFormsAreNotListed(): void
    {
        $result = $this->column->describe(self::CUSTOMER_FORMS, ['adminhtml_customer', 'adminhtml_checkout']);

        self::assertSame([], $result['items'], 'every attribute is in the admin forms');
        self::assertSame(0, $result['other']);
        self::assertSame(['items' => [], 'other' => 0], $this->column->describe(self::ADDRESS_FORMS, ['adminhtml_customer_address']));
    }

    public function testFormsOfOtherModulesAreCounted(): void
    {
        $result = $this->column->describe(self::CUSTOMER_FORMS, ['customer_account_edit', 'is_customer_attribute', 'show_in_email', 'adminhtml_something', 'show_in_email']);

        self::assertCount(1, $result['items']);
        self::assertSame(2, $result['other'], 'two other forms, counted once each; the admin forms are not "other"');
    }

    public function testAnAttributeInNoFormHasNothingToShow(): void
    {
        self::assertSame(['items' => [], 'other' => 0], $this->column->describe(self::CUSTOMER_FORMS, []));
    }
}
