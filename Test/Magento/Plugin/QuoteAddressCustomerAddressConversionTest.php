<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Plugin\QuoteAddressCustomerAddressConversion;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Api\AttributeInterface;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use PHPUnit\Framework\TestCase;

class QuoteAddressCustomerAddressConversionTest extends TestCase
{
    private function plugin(): QuoteAddressCustomerAddressConversion
    {
        $provider = $this->createStub(AddressAttributeProvider::class);
        $provider->method('getUserDefined')->willReturn([
            'e2e_text' => $this->createStub(AbstractAttribute::class),
            'e2e_multi' => $this->createStub(AbstractAttribute::class),
            'e2e_empty' => $this->createStub(AbstractAttribute::class),
        ]);
        return new QuoteAddressCustomerAddressConversion($provider);
    }

    public function testCustomAttributesOfTheSavedAddressAreCopiedAndTheOthersCleared(): void
    {
        $custom = $this->createStub(AttributeInterface::class);
        $custom->method('getValue')->willReturn('saved');
        $address = $this->createStub(AddressInterface::class);
        $address->method('getCustomAttribute')->willReturnCallback(static fn (string $code) => $code === 'e2e_text' ? $custom : null);

        $set = [];
        $quoteAddress = $this->createMock(QuoteAddress::class);
        $quoteAddress->expects(self::exactly(3))->method('setCustomAttribute')->willReturnCallback(
            function (string $code, $value) use (&$set, $quoteAddress) {
                $set[$code] = $value;
                return $quoteAddress;
            }
        );
        $this->plugin()->afterImportCustomerAddressData($quoteAddress, $quoteAddress, $address);
        self::assertSame(['e2e_text' => 'saved', 'e2e_multi' => null, 'e2e_empty' => null], $set);
    }

    public function testAnAddressBuiltFromFlatDataKeepsItsValues(): void
    {
        // Hyvä Checkout builds the data object from the model's flat data: the values are plain keys
        $address = new class implements AddressInterfaceWithArray {
            public function getCustomAttribute($code)
            {
                return null;
            }

            public function __toArray(): array
            {
                return ['e2e_text' => 'flat value', 'e2e_multi' => ['4', '7'], 'street' => ['1 Street']];
            }
        };
        $set = [];
        $quoteAddress = $this->createStub(QuoteAddress::class);
        $quoteAddress->method('setCustomAttribute')->willReturnCallback(
            function (string $code, $value) use (&$set, $quoteAddress) {
                $set[$code] = $value;
                return $quoteAddress;
            }
        );
        $this->plugin()->afterImportCustomerAddressData($quoteAddress, $quoteAddress, $this->wrap($address));
        self::assertSame(['e2e_text' => 'flat value', 'e2e_multi' => '4,7', 'e2e_empty' => null], $set);
    }

    /**
     * A customer address stub that answers getCustomAttribute() and __toArray() like the data object Hyvä Checkout builds.
     */
    private function wrap(AddressInterfaceWithArray $source): AddressInterface
    {
        $address = $this->createStub(\Magento\Customer\Model\Data\Address::class);
        $address->method('getCustomAttribute')->willReturnCallback(static fn (string $code) => $source->getCustomAttribute($code));
        $address->method('__toArray')->willReturnCallback(static fn (): array => $source->__toArray());
        return $address;
    }
}

interface AddressInterfaceWithArray
{
    public function getCustomAttribute($code);

    public function __toArray(): array;
}
