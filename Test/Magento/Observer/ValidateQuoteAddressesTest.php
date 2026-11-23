<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Observer;

use MageOS\CustomerCustomAttributes\Model\AddressValueValidator;
use MageOS\CustomerCustomAttributes\Model\Dependency\VisibilityService;
use MageOS\CustomerCustomAttributes\Model\QuoteAddressValueReader;
use MageOS\CustomerCustomAttributes\Observer\ValidateQuoteAddresses;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\TestCase;

class ValidateQuoteAddressesTest extends TestCase
{
    private function observe(string $area, array $read, array $checkoutCodes, array $allCodes, array $visible, array $messages, Address $address): void
    {
        $reader = $this->createStub(QuoteAddressValueReader::class);
        $reader->method('read')->willReturn($read);
        $validator = $this->createStub(AddressValueValidator::class);
        $validator->method('getCheckoutCodes')->willReturn($checkoutCodes);
        $validator->method('getAllCodes')->willReturn($allCodes);
        $validator->method('validate')->willReturn($messages);
        $visibility = $this->createStub(VisibilityService::class);
        $visibility->method('getVisibleCodes')->willReturnCallback(
            static fn (string $entity, array $codes): array => array_values(array_intersect($codes, $visible))
        );
        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturn($area);

        $quote = $this->createStub(Quote::class);
        $quote->method('getBillingAddress')->willReturn($address);
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getCustomerGroupId')->willReturn(1);
        $quote->method('getStoreId')->willReturn(1);

        (new ValidateQuoteAddresses($reader, $validator, $visibility, $state))
            ->execute(new Observer(['event' => new Event(['quote' => $quote, 'order' => null])]));
    }

    public function testOutsideTheAdminAValueOfAnAttributeNotInTheCheckoutFormIsDropped(): void
    {
        $address = $this->createMock(Address::class);
        $address->expects(self::once())->method('setCustomAttribute')->with('e2e_out', null);
        $this->observe(Area::AREA_WEBAPI_REST, ['e2e_in' => 'a', 'e2e_out' => 'sent anyway'], ['e2e_in'], ['e2e_in', 'e2e_out'], ['e2e_in', 'e2e_out'], [], $address);
    }

    public function testTheAdminKeepsEveryAttribute(): void
    {
        $address = $this->createMock(Address::class);
        $address->expects(self::never())->method('setCustomAttribute');
        $this->observe(Area::AREA_ADMINHTML, ['e2e_in' => 'a', 'e2e_out' => 'admin value'], ['e2e_in'], ['e2e_in', 'e2e_out'], ['e2e_in', 'e2e_out'], [], $address);
    }

    public function testAValueHiddenByARuleIsDroppedEverywhere(): void
    {
        $address = $this->createMock(Address::class);
        $address->expects(self::once())->method('setCustomAttribute')->with('e2e_dep', null);
        $this->observe(Area::AREA_ADMINHTML, ['e2e_parent' => '1', 'e2e_dep' => 'hidden'], [], ['e2e_parent', 'e2e_dep'], ['e2e_parent'], [], $address);
    }

    public function testValidationMessagesStopTheOrder(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Billing Address: "X" is a required value.');
        $this->observe(Area::AREA_FRONTEND, [], ['e2e_in'], ['e2e_in'], ['e2e_in'], ['"X" is a required value.'], $this->createStub(Address::class));
    }
}
