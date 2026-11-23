<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Observer;

use MageOS\CustomerCustomAttributes\Model\Checkout\CustomerCheckoutAttributes;
use MageOS\CustomerCustomAttributes\Observer\QuoteMergeCustomerValues;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QuoteMergeCustomerValuesTest extends TestCase
{
    public function testTheGuestValuesFillOnlyTheEmptyAttributesOfTheCustomerCart(): void
    {
        $guest = $this->createStub(Quote::class);
        $guest->method('getId')->willReturn(5);
        $customer = $this->createStub(Quote::class);
        $customer->method('getId')->willReturn(9);

        $attributes = $this->createMock(CustomerCheckoutAttributes::class);
        $attributes->method('getValues')->willReturnCallback(
            static fn (Quote $quote): array => $quote === $guest
                ? ['e2e_text' => 'guest', 'e2e_order' => 'guest only', 'e2e_blank' => '']
                : ['e2e_text' => 'customer', 'e2e_order' => '']
        );
        $attributes->expects(self::once())->method('saveValues')->with($customer, ['e2e_order' => 'guest only'])->willReturn([]);

        (new QuoteMergeCustomerValues($attributes, $this->createStub(LoggerInterface::class)))
            ->execute(new Observer(['event' => new Event(['quote' => $customer, 'source' => $guest])]));
    }

    public function testNothingToCarryWritesNothing(): void
    {
        $guest = $this->createStub(Quote::class);
        $guest->method('getId')->willReturn(5);
        $customer = $this->createStub(Quote::class);
        $customer->method('getId')->willReturn(9);
        $attributes = $this->createMock(CustomerCheckoutAttributes::class);
        $attributes->method('getValues')->willReturn([]);
        $attributes->expects(self::never())->method('saveValues');

        (new QuoteMergeCustomerValues($attributes, $this->createStub(LoggerInterface::class)))
            ->execute(new Observer(['event' => new Event(['quote' => $customer, 'source' => $guest])]));
    }
}
