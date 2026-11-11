<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\RequiredAddressFormCheck;
use PHPUnit\Framework\TestCase;

class RequiredAddressFormCheckTest extends TestCase
{
    private RequiredAddressFormCheck $check;

    protected function setUp(): void
    {
        $this->check = new RequiredAddressFormCheck();
    }

    public function testRequiredInTheAddressBookButNotInTheCheckoutBlocksTheCheckout(): void
    {
        self::assertTrue($this->check->blocksCheckout(true, ['customer_address_edit', 'customer_register_address']));
    }

    public function testAlsoInTheCheckoutFormDoesNotBlock(): void
    {
        self::assertFalse($this->check->blocksCheckout(true, ['customer_address_edit', 'mageos_hyva_checkout']));
    }

    public function testOptionalNeverBlocks(): void
    {
        self::assertFalse($this->check->blocksCheckout(false, ['customer_address_edit']));
    }

    public function testNotInTheAddressBookFormDoesNotBlock(): void
    {
        self::assertFalse($this->check->blocksCheckout(true, ['customer_register_address']));
        self::assertFalse($this->check->blocksCheckout(true, []));
    }

    public function testRequiredForOneWebsiteCounts(): void
    {
        self::assertTrue($this->check->isRequiredAnywhere(false, [1 => ['is_required' => null], 2 => ['is_required' => 1]]));
    }

    public function testNotRequiredAnywhere(): void
    {
        self::assertFalse($this->check->isRequiredAnywhere(false, [1 => ['is_required' => 0], 2 => ['is_required' => null]]));
        self::assertFalse($this->check->isRequiredAnywhere(false, []));
    }

    public function testRequiredForTheStoreCounts(): void
    {
        self::assertTrue($this->check->isRequiredAnywhere(true, [1 => ['is_required' => null]]));
    }
}
