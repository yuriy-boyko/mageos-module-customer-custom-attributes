<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\EmailTypes;
use PHPUnit\Framework\TestCase;

class EmailTypesTest extends TestCase
{
    private EmailTypes $types;

    protected function setUp(): void
    {
        $this->types = new EmailTypes();
    }

    public function testTheFourEmailsInTheirListOrder(): void
    {
        self::assertSame(['order', 'invoice', 'shipment', 'creditmemo'], EmailTypes::all());
        self::assertSame(['New Order', 'Invoice', 'Shipment', 'Credit Memo'], array_values(EmailTypes::labels()));
    }

    public function testAListKeepsTheKnownEmailsOnceInTheFixedOrder(): void
    {
        self::assertSame(['order', 'creditmemo'], $this->types->normalize(['creditmemo', 'x', 'order', 'order']));
        self::assertSame(['invoice'], $this->types->normalize([' invoice', 'invoice']), 'a code with a space is not a code');
        self::assertSame([], $this->types->normalize([]));
    }

    public function testTheEarlierYesMeansEveryEmail(): void
    {
        foreach ([1, '1', true] as $yes) {
            self::assertSame(EmailTypes::all(), $this->types->normalize($yes));
        }
    }

    public function testOneCodeAsAStringIsThatEmail(): void
    {
        self::assertSame(['shipment'], $this->types->normalize('shipment'));
    }

    public function testNothingOrNonsenseIsNoEmail(): void
    {
        foreach ([null, '', '0', 0, false, 'bogus', new \stdClass()] as $none) {
            self::assertSame([], $this->types->normalize($none));
        }
    }
}
