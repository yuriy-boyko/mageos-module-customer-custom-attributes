<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\Dependency\VisibilityService;
use MageOS\CustomerCustomAttributes\Plugin\StorefrontFormHiddenValues;
use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\TestCase;

class StorefrontFormHiddenValuesTest extends TestCase
{
    private function plugin(string $action, array $posted): StorefrontFormHiddenValues
    {
        $request = $this->createStub(Http::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getFullActionName')->willReturn($action);
        $request->method('getPostValue')->willReturn($posted);

        $attribute = function (string $input): AbstractAttribute {
            $stub = $this->createStub(AbstractAttribute::class);
            $stub->method('getFrontendInput')->willReturn($input);
            return $stub;
        };
        $attributes = ['e2e_parent' => $attribute('select'), 'e2e_dep' => $attribute('text')];
        $addressAttributes = $this->createStub(AddressAttributeProvider::class);
        $addressAttributes->method('getUserDefined')->willReturn($attributes);
        $customerAttributes = $this->createStub(CustomerAttributeProvider::class);
        $customerAttributes->method('getUserDefined')->willReturn($attributes);
        // the dependent is hidden for the values being saved
        $visibility = $this->createStub(VisibilityService::class);
        $visibility->method('getVisibleCodes')->willReturn(['e2e_parent']);
        $session = $this->createStub(CustomerSession::class);
        $session->method('isLoggedIn')->willReturn(true);
        $session->method('getCustomerGroupId')->willReturn(1);

        return new StorefrontFormHiddenValues($request, $customerAttributes, $addressAttributes, $visibility, new ValueNormalizer(), $session, $this->createStub(ScopeConfigInterface::class));
    }

    private function value(?string $value): ?AttributeInterface
    {
        if ($value === null) {
            return null;
        }
        $stub = $this->createStub(AttributeInterface::class);
        $stub->method('getValue')->willReturn($value);
        return $stub;
    }

    public function testAPostedValueOfAHiddenAddressFieldGetsTheStoredValueBack(): void
    {
        $address = $this->createMock(AddressInterface::class);
        $address->method('getId')->willReturn(7);
        $address->method('getCustomAttribute')->willReturnCallback(fn (string $code) => $this->value(['e2e_parent' => '2', 'e2e_dep' => 'posted anyway'][$code] ?? null));
        $address->expects(self::once())->method('setCustomAttribute')->with('e2e_dep', 'stored before');
        $stored = $this->createStub(AddressInterface::class);
        $stored->method('getCustomAttribute')->willReturnCallback(fn (string $code) => $this->value($code === 'e2e_dep' ? 'stored before' : null));
        $repository = $this->createStub(AddressRepositoryInterface::class);
        $repository->method('getById')->willReturn($stored);

        $this->plugin('customer_address_formPost', ['e2e_parent' => '2', 'e2e_dep' => 'posted anyway'])->beforeSave($repository, $address);
    }

    public function testAHiddenFieldThatWasNotPostedIsLeftAlone(): void
    {
        $address = $this->createMock(AddressInterface::class);
        $address->method('getId')->willReturn(7);
        $address->method('getCustomAttribute')->willReturn(null);
        $address->expects(self::never())->method('setCustomAttribute');
        $this->plugin('customer_address_formPost', ['e2e_parent' => '2'])->beforeSave($this->createStub(AddressRepositoryInterface::class), $address);
    }

    public function testOtherSavesAreNotTouched(): void
    {
        $address = $this->createMock(AddressInterface::class);
        $address->expects(self::never())->method('setCustomAttribute');
        $this->plugin('checkout_index_index', ['e2e_dep' => 'x'])->beforeSave($this->createStub(AddressRepositoryInterface::class), $address);
    }

    public function testANewCustomerHasNothingStored(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(null);
        $customer->method('getGroupId')->willReturn(1);
        $customer->method('getCustomAttribute')->willReturnCallback(fn (string $code) => $this->value($code === 'e2e_dep' ? 'posted anyway' : null));
        $customer->expects(self::once())->method('setCustomAttribute')->with('e2e_dep', null);
        $result = $this->plugin('customer_account_createpost', ['e2e_dep' => 'posted anyway'])
            ->beforeSave($this->createStub(CustomerRepositoryInterface::class), $customer, 'hash');
        self::assertSame([$customer, 'hash'], $result, 'the password hash is passed on');
    }
}
