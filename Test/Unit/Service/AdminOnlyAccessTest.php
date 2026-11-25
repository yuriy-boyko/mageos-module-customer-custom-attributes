<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\AdminOnlyAccess;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminOnlyAccessTest extends TestCase
{
    private AdminOnlyAccess $access;

    protected function setUp(): void
    {
        $this->access = new AdminOnlyAccess();
    }

    public function testTheSettingIsOffUnlessTheRuleKeyIsSet(): void
    {
        self::assertFalse($this->access->isAdminOnly([]), 'saved before the setting existed');
        self::assertFalse($this->access->isAdminOnly([ValidateRulesBuilder::ADMIN_ONLY_KEY => 0]));
        self::assertTrue($this->access->isAdminOnly([ValidateRulesBuilder::ADMIN_ONLY_KEY => 1]));
        self::assertTrue($this->access->isAdminOnly([ValidateRulesBuilder::ADMIN_ONLY_KEY => '1']), 'after a JSON round trip');
    }

    /**
     * @return array<string, array{0: ?string, 1: ?int, 2: string, 3: string, 4: bool}>
     */
    public static function contexts(): array
    {
        return [
            'storefront registration post' => ['frontend', null, 'customer_account_createpost', 'POST', true],
            'storefront account edit post' => ['frontend', null, 'Customer_Account_EditPost', 'post', true],
            'storefront account edit page (no save)' => ['frontend', null, 'customer_account_editpost', 'GET', false],
            'another storefront action' => ['frontend', null, 'checkout_index_index', 'POST', false],
            'REST with a customer token' => ['webapi_rest', 3, '', 'PUT', true],
            'REST without a token' => ['webapi_rest', 4, '', 'POST', true],
            'SOAP with a customer token' => ['webapi_soap', 3, '', 'POST', true],
            'GraphQL with a customer token' => ['graphql', 3, '', 'POST', true],
            'REST with an admin token' => ['webapi_rest', 2, '', 'PUT', false],
            'REST with an integration token' => ['webapi_rest', 1, '', 'PUT', false],
            'REST with an unknown user' => ['webapi_rest', null, '', 'PUT', false],
            'the admin area' => ['adminhtml', null, 'customer_index_save', 'POST', false],
            'the command line (no area)' => [null, null, '', '', false],
            'a global setup area' => ['global', null, '', '', false],
        ];
    }

    #[DataProvider('contexts')]
    public function testWhichSavesAreTheCustomers(?string $area, ?int $userType, string $action, string $method, bool $expected): void
    {
        self::assertSame($expected, $this->access->appliesTo($area, $userType, $action, $method));
    }

    public function testAdminOnlyCannotBeRequiredInAFormTheCustomerFillsIn(): void
    {
        self::assertTrue($this->access->conflictsWithRequired(true, true, ['adminhtml_customer', 'customer_account_edit']));
        self::assertTrue($this->access->conflictsWithRequired(true, true, ['customer_account_create']));
        self::assertFalse($this->access->conflictsWithRequired(true, false, ['customer_account_edit']), 'optional');
        self::assertFalse($this->access->conflictsWithRequired(false, true, ['customer_account_edit']), 'not admin-only');
        self::assertFalse($this->access->conflictsWithRequired(true, true, ['adminhtml_customer', 'mageos_hyva_checkout']), 'no account form');
    }
}
