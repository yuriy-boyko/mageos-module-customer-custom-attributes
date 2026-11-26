<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\RequiredCustomerFormCheck;
use PHPUnit\Framework\TestCase;

class RequiredCustomerFormCheckTest extends TestCase
{
    private RequiredCustomerFormCheck $check;

    protected function setUp(): void
    {
        $this->check = new RequiredCustomerFormCheck();
    }

    public function testAnOptionalAttributeIsNeverReported(): void
    {
        self::assertSame([], $this->check->missingForms(false, []));
        self::assertSame([], $this->check->missingForms(false, ['adminhtml_customer'], true));
    }

    public function testARequiredAttributeInBothAccountFormsIsFine(): void
    {
        self::assertSame([], $this->check->missingForms(true, ['adminhtml_customer', 'customer_account_create', 'customer_account_edit']));
    }

    public function testTheMissingAccountFormsAreReported(): void
    {
        self::assertSame(['customer_account_create', 'customer_account_edit'], $this->check->missingForms(true, ['adminhtml_customer', 'mageos_hyva_checkout']));
        self::assertSame(['customer_account_edit'], $this->check->missingForms(true, ['customer_account_create']));
        self::assertSame(['customer_account_create'], $this->check->missingForms(true, ['customer_account_edit']));
    }

    public function testAnAttributeTheCustomerCannotChangeIsMissingFromBothForms(): void
    {
        // the field is never printed for the customer, whatever forms it is assigned to
        self::assertSame(
            ['customer_account_create', 'customer_account_edit'],
            $this->check->missingForms(true, ['customer_account_create', 'customer_account_edit'], true)
        );
    }
}
