<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Plugin;

use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Plugin\CustomerAdminOnlyValues;
use MageOS\CustomerCustomAttributes\Service\AdminOnlyAccess;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Attribute;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

/**
 * "Customer Cannot Change This Value": the values of admin-only attributes in a save the customer (or a visitor) sends are replaced
 * by the stored ones; saves of the admin and of integrations are not touched.
 */
class CustomerAdminOnlyValuesTest extends TestCase
{
    private function attribute(array $rules): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getValidateRules')->willReturn($rules);
        return $attribute;
    }

    private function valueOf(?string $value): ?AttributeInterface
    {
        if ($value === null) {
            return null;
        }
        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getValue')->willReturn($value);
        return $attribute;
    }

    /**
     * @param array<string, ?string> $submitted the custom attribute values the save carries (null = not carried)
     * @param array<string, ?string> $stored the values of the stored customer
     * @return array<string, mixed> what was set on the customer, by code
     */
    private function save(?string $area, ?int $userType, string $action, string $method, array $submitted, array $stored, ?int $id = 5): array
    {
        $attributes = $this->createStub(CustomerAttributeProvider::class);
        $attributes->method('getUserDefined')->willReturn([
            'internal' => $this->attribute([ValidateRulesBuilder::ADMIN_ONLY_KEY => 1]),
            'internal_two' => $this->attribute([ValidateRulesBuilder::ADMIN_ONLY_KEY => 1]),
            'public' => $this->attribute([]),
        ]);
        $state = $this->createStub(State::class);
        if ($area === null) {
            $state->method('getAreaCode')->willThrowException(new \Magento\Framework\Exception\LocalizedException(__('Area code is not set')));
        } else {
            $state->method('getAreaCode')->willReturn($area);
        }
        $request = $this->createStub(Http::class);
        $request->method('getFullActionName')->willReturn($action);
        $request->method('getMethod')->willReturn($method);
        $userContext = $this->createStub(UserContextInterface::class);
        $userContext->method('getUserType')->willReturn($userType);

        $storedCustomer = $this->createStub(CustomerInterface::class);
        $storedCustomer->method('getCustomAttribute')->willReturnCallback(fn (string $code) => $this->valueOf($stored[$code] ?? null));
        $repository = $this->createStub(CustomerRepositoryInterface::class);
        $repository->method('getById')->willReturn($storedCustomer);

        $set = [];
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);
        $customer->method('getCustomAttribute')->willReturnCallback(fn (string $code) => $this->valueOf($submitted[$code] ?? null));
        $customer->method('setCustomAttribute')->willReturnCallback(function (string $code, $value) use (&$set, $customer) {
            $set[$code] = $value;
            return $customer;
        });

        $plugin = new CustomerAdminOnlyValues($attributes, new AdminOnlyAccess(), $state, $request, $userContext);
        $result = $plugin->beforeSave($repository, $customer, 'hash');
        self::assertSame([$customer, 'hash'], $result, 'the arguments of save() pass through');

        return $set;
    }

    public function testACustomerTokenKeepsTheStoredValues(): void
    {
        $set = $this->save('webapi_rest', 3, '', 'PUT', ['internal' => 'hacked', 'public' => 'new'], ['internal' => 'by admin', 'public' => 'old']);

        self::assertSame(['internal' => 'by admin'], $set, 'only the admin-only attribute is put back; the other is not touched');
    }

    public function testAVisitorCannotSetItOnANewCustomer(): void
    {
        $set = $this->save('webapi_rest', 4, '', 'POST', ['internal' => 'hacked', 'internal_two' => 'hacked too'], [], null);

        self::assertSame(['internal' => null, 'internal_two' => null], $set, 'removed for a new customer');
    }

    public function testTheStorefrontAccountFormsKeepTheStoredValues(): void
    {
        $set = $this->save('frontend', null, 'customer_account_editpost', 'POST', ['internal' => 'hacked'], ['internal' => 'by admin']);
        self::assertSame(['internal' => 'by admin'], $set);

        $set = $this->save('frontend', null, 'customer_account_createpost', 'POST', ['internal' => 'hacked'], [], null);
        self::assertSame(['internal' => null], $set);
    }

    public function testAnAttributeTheSaveDoesNotCarryIsNotTouched(): void
    {
        self::assertSame([], $this->save('webapi_rest', 3, '', 'PUT', ['public' => 'new'], ['internal' => 'by admin']));
    }

    public function testTheAdminAnIntegrationAndTheCodeWriteFreely(): void
    {
        $submitted = ['internal' => 'admin value'];
        $stored = ['internal' => 'old'];
        self::assertSame([], $this->save('adminhtml', null, 'customer_index_save', 'POST', $submitted, $stored), 'admin area');
        self::assertSame([], $this->save('webapi_rest', 2, '', 'PUT', $submitted, $stored), 'admin token');
        self::assertSame([], $this->save('webapi_rest', 1, '', 'PUT', $submitted, $stored), 'integration token');
        self::assertSame([], $this->save(null, null, '', '', $submitted, $stored), 'command line or a setup script');
        self::assertSame([], $this->save('frontend', null, 'checkout_index_index', 'POST', $submitted, $stored), 'another storefront action');
    }
}
