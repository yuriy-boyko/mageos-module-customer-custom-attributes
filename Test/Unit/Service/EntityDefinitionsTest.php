<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use PHPUnit\Framework\TestCase;

class EntityDefinitionsTest extends TestCase
{
    private EntityDefinitions $definitions;

    protected function setUp(): void
    {
        $this->definitions = new EntityDefinitions();
    }

    public function testUnknownKeyFallsBackToCustomer(): void
    {
        self::assertSame('customer', $this->definitions->resolveKey('nonsense'));
        self::assertSame('customer', $this->definitions->resolveKey(null));
        self::assertSame('address', $this->definitions->resolveKey('address'));
    }

    public function testEntityTypeCodes(): void
    {
        self::assertSame('customer', $this->definitions->get('customer')['eav_entity_type']);
        self::assertSame('customer_address', $this->definitions->get('address')['eav_entity_type']);
    }

    public function testCodeLengthLimits(): void
    {
        self::assertSame(51, $this->definitions->get('customer')['max_code_length']);
        self::assertSame(60, $this->definitions->get('address')['max_code_length']);
    }

    public function testAdminFormIsAlwaysIncluded(): void
    {
        self::assertSame(['adminhtml_customer_address'], $this->definitions->resolveForms('address', []));
        self::assertSame(
            ['adminhtml_customer_address', 'customer_register_address'],
            $this->definitions->resolveForms('address', ['customer_register_address'])
        );
    }

    public function testUnknownAndDuplicateFormsAreDropped(): void
    {
        self::assertSame(
            ['adminhtml_customer', 'customer_account_edit'],
            $this->definitions->resolveForms(
                'customer',
                ['bogus', 'customer_account_edit', 'customer_account_edit', 'adminhtml_customer', 5]
            )
        );
    }

    public function testAddressFormsAreNotAcceptedForCustomers(): void
    {
        self::assertSame(
            ['adminhtml_customer'],
            $this->definitions->resolveForms('customer', ['customer_register_address'])
        );
    }

    public function testAddressSwitchesAreOptionalForms(): void
    {
        $forms = $this->definitions->get('address')['optional_forms'];
        self::assertArrayHasKey('mageos_hyva_checkout', $forms);
        self::assertArrayHasKey('mageos_address_text', $forms);
        self::assertArrayHasKey('customer_register_address', $forms);
        self::assertArrayHasKey('customer_address_edit', $forms);
        self::assertLessThanOrEqual(32, strlen('mageos_hyva_checkout'));
        self::assertLessThanOrEqual(32, strlen('mageos_address_text'));
        self::assertSame(
            ['adminhtml_customer_address', 'mageos_hyva_checkout', 'mageos_address_text'],
            $this->definitions->resolveForms('address', ['mageos_hyva_checkout', 'mageos_address_text', 'unknown'])
        );
    }

    public function testCustomerAreaHasTheCheckoutSwitchButNoAddressTextSwitch(): void
    {
        $forms = $this->definitions->get('customer')['optional_forms'];
        self::assertArrayHasKey('mageos_hyva_checkout', $forms);
        self::assertArrayNotHasKey('mageos_address_text', $forms);
    }
}
