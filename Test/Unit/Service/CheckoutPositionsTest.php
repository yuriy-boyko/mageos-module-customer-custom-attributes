<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\CheckoutPositions;
use PHPUnit\Framework\TestCase;

class CheckoutPositionsTest extends TestCase
{
    public function testSevenPositionsWithLabels(): void
    {
        self::assertCount(7, CheckoutPositions::labels());
        self::assertSame(array_keys(CheckoutPositions::labels()), CheckoutPositions::codes());
        self::assertSame('Above Shipping Address', CheckoutPositions::labels()[CheckoutPositions::SHIPPING_ADDRESS_ABOVE]);
    }

    public function testShippingPositions(): void
    {
        self::assertTrue(CheckoutPositions::isShippingPosition('shipping_address_above'));
        self::assertTrue(CheckoutPositions::isShippingPosition('shipping_methods_below'));
        self::assertFalse(CheckoutPositions::isShippingPosition('payment_above'));
        self::assertFalse(CheckoutPositions::isShippingPosition('order_summary'));
        self::assertFalse(CheckoutPositions::isShippingPosition(null));
    }

    public function testValidation(): void
    {
        self::assertTrue(CheckoutPositions::isValid('order_summary'));
        self::assertFalse(CheckoutPositions::isValid('bogus'));
        self::assertFalse(CheckoutPositions::isValid(5));
        self::assertFalse(CheckoutPositions::isValid(null));
        self::assertTrue(CheckoutPositions::isValid(CheckoutPositions::default()));
    }
}
