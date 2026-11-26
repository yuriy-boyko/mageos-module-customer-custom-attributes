<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Model\Attribute;

use MageOS\CustomerCustomAttributes\Model\Attribute\EntityContext;
use MageOS\CustomerCustomAttributes\Model\Attribute\FormProcessor;
use MageOS\CustomerCustomAttributes\Model\Attribute\ReservedCodeProvider;
use MageOS\CustomerCustomAttributes\Service\AdminOnlyAccess;
use MageOS\CustomerCustomAttributes\Service\AttributeCodeValidator;
use MageOS\CustomerCustomAttributes\Service\InputTypeMap;
use MageOS\CustomerCustomAttributes\Service\SettingConflicts;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use Magento\Customer\Model\Attribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\SetFactory;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as OptionCollectionFactory;
use Magento\Framework\Filter\FilterManager;
use PHPUnit\Framework\TestCase;

/**
 * The core flags of the customer grid: taken from the form for customer attributes, and only when the form sent them, so a save that
 * does not show the fields keeps what the attribute has. Address attributes never get them.
 */
class FormProcessorGridFlagsTest extends TestCase
{
    private const FLAGS = ['is_used_in_grid', 'is_visible_in_grid', 'is_filterable_in_grid', 'is_searchable_in_grid'];

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed> the attribute data the processor returns
     */
    private function data(string $entity, array $post): array
    {
        $filter = $this->createStub(FilterManager::class);
        $filter->method('__call')->willReturnCallback(static fn (string $name, array $arguments) => $arguments[0] ?? '');
        $processor = new FormProcessor(
            new InputTypeMap(),
            new ValidateRulesBuilder(),
            new AttributeCodeValidator(),
            $this->createStub(ReservedCodeProvider::class),
            $this->createStub(EavConfig::class),
            $this->createStub(SetFactory::class),
            $filter,
            $this->createStub(OptionCollectionFactory::class),
            new AdminOnlyAccess(),
            new SettingConflicts()
        );
        $context = $this->createStub(EntityContext::class);
        $context->method('resolveForms')->willReturn(['adminhtml_customer']);
        $context->method('getKey')->willReturn($entity);
        $existing = $this->createStub(Attribute::class);
        $existing->method('getAttributeCode')->willReturn('tax_code');
        $existing->method('getFrontendInput')->willReturn('text');
        $existing->method('getId')->willReturn(9);
        $existing->method('getUsedInForms')->willReturn(['adminhtml_customer']);

        return $processor->process($context, ['frontend_label' => ['Tax code']] + $post, $existing)['data'];
    }

    public function testPostedFlagsAreSavedAsZeroOrOne(): void
    {
        $data = $this->data('customer', ['is_used_in_grid' => '1', 'is_visible_in_grid' => '0', 'is_filterable_in_grid' => 'yes', 'is_searchable_in_grid' => '']);

        self::assertSame(1, $data['is_used_in_grid']);
        self::assertSame(0, $data['is_visible_in_grid']);
        self::assertSame(0, $data['is_filterable_in_grid'], 'not a number: off');
        self::assertSame(0, $data['is_searchable_in_grid']);
    }

    public function testFlagsThatWereNotPostedAreNotTouched(): void
    {
        $data = $this->data('customer', ['is_used_in_grid' => '1']);

        self::assertSame(1, $data['is_used_in_grid']);
        foreach (['is_visible_in_grid', 'is_filterable_in_grid', 'is_searchable_in_grid'] as $flag) {
            self::assertArrayNotHasKey($flag, $data, $flag);
        }
    }

    public function testAddressAttributesNeverGetTheFlags(): void
    {
        $data = $this->data('address', array_fill_keys(self::FLAGS, '1'));

        foreach (self::FLAGS as $flag) {
            self::assertArrayNotHasKey($flag, $data, $flag);
        }
    }
}
