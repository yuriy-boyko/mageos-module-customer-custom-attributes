<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Model\Attribute;

use MageOS\CustomerCustomAttributes\Model\Attribute\RequiredFormWarning;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\AttributeWebsiteStorage;
use MageOS\CustomerCustomAttributes\Service\AdminOnlyAccess;
use MageOS\CustomerCustomAttributes\Service\RequiredAddressFormCheck;
use MageOS\CustomerCustomAttributes\Service\RequiredCustomerFormCheck;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use Magento\Customer\Model\Attribute;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The admin warning for a required customer attribute that customers cannot fill in (registration or account edit form missing, or the
 * customer cannot change it); the per-website "required" setting counts too.
 */
class RequiredFormWarningTest extends TestCase
{
    /** @var string[] */
    private array $warnings = [];

    /**
     * @param string[] $forms
     * @param array<int, array{is_required: int|null}> $websites
     * @param array<string, mixed> $rules
     */
    private function warn(bool $required, array $forms, array $websites = [], array $rules = [], string $entity = 'customer'): void
    {
        $this->warnings = [];
        $attribute = (new \ReflectionClass(Attribute::class))->newInstanceWithoutConstructor();
        // the model was built without its constructor: tell it which field is the id
        (new \ReflectionProperty($attribute, '_idFieldName'))->setValue($attribute, 'attribute_id');
        $attribute->setData([
            'attribute_id' => 5,
            'attribute_code' => 'tax_code',
            'frontend_label' => 'Tax code',
            'is_required' => $required ? 1 : 0,
            'used_in_forms' => $forms,
            'validate_rules' => $rules,
        ]);
        $storage = $this->createStub(AttributeWebsiteStorage::class);
        $storage->method('load')->willReturn($websites);
        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addWarningMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->warnings[] = (string) $message;
            return $messages;
        });
        (new RequiredFormWarning(new RequiredAddressFormCheck(), $storage, $messages, new RequiredCustomerFormCheck(), new AdminOnlyAccess()))
            ->warn($attribute, $entity);
    }

    public function testARequiredAttributeInBothAccountFormsGivesNoWarning(): void
    {
        $this->warn(true, ['adminhtml_customer', 'customer_account_create', 'customer_account_edit']);
        self::assertSame([], $this->warnings);
    }

    public function testEachMissingFormHasItsOwnWarning(): void
    {
        $this->warn(true, ['customer_account_create']);
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('"Tax code" is required, but not in the account edit form', $this->warnings[0]);

        $this->warn(true, ['customer_account_edit']);
        self::assertStringContainsString('not in the registration form', $this->warnings[0]);

        $this->warn(true, ['adminhtml_customer']);
        self::assertStringContainsString('customers cannot fill it in', $this->warnings[0]);
    }

    public function testAnAttributeTheCustomerCannotChangeIsUnfillable(): void
    {
        $this->warn(true, ['customer_account_create', 'customer_account_edit'], [], [ValidateRulesBuilder::ADMIN_ONLY_KEY => 1]);
        self::assertStringContainsString('customers cannot fill it in', $this->warnings[0] ?? '');
    }

    public function testRequiredOnOneWebsiteCountsAndOptionalIsSilent(): void
    {
        $this->warn(false, ['adminhtml_customer'], [1 => ['is_required' => 1]]);
        self::assertCount(1, $this->warnings, 'required on a website');

        $this->warn(false, ['adminhtml_customer'], [1 => ['is_required' => null]]);
        self::assertSame([], $this->warnings);
    }

    public function testNothingForOtherEntities(): void
    {
        $this->warn(true, [], [], [], 'unknown');
        self::assertSame([], $this->warnings);
    }
}
