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
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\Collection as OptionCollection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as OptionCollectionFactory;
use Magento\Framework\Filter\FilterManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Saving an existing dropdown: an attribute whose options come from a developer's source model has no rows in the option table, so
 * the "add at least one option" rule and the option data of the form must not apply to it. They still apply to the table source.
 */
class FormProcessorDeveloperOptionsTest extends TestCase
{
    private function processor(): FormProcessor
    {
        $options = $this->createStub(OptionCollection::class);
        $options->method('setAttributeFilter')->willReturnSelf();
        $options->method('getAllIds')->willReturn([]);
        $optionFactory = $this->createStub(OptionCollectionFactory::class);
        $optionFactory->method('create')->willReturn($options);

        $filter = $this->createStub(FilterManager::class);
        $filter->method('__call')->willReturnCallback(static fn (string $name, array $arguments) => $arguments[0] ?? '');

        return new FormProcessor(
            new InputTypeMap(),
            new ValidateRulesBuilder(),
            new AttributeCodeValidator(),
            $this->createStub(ReservedCodeProvider::class),
            $this->createStub(EavConfig::class),
            $this->createStub(SetFactory::class),
            $filter,
            $optionFactory,
            new AdminOnlyAccess(),
            new SettingConflicts()
        );
    }

    private function existing(string $input, ?string $sourceModel): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn('e2e_a_level');
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getSourceModel')->willReturn($sourceModel);
        $attribute->method('getId')->willReturn(15);
        $attribute->method('getUsedInForms')->willReturn(['customer_address_edit']);
        return $attribute;
    }

    private function context(): EntityContext
    {
        $context = $this->createStub(EntityContext::class);
        $context->method('resolveForms')->willReturn(['adminhtml_customer_address', 'customer_address_edit']);
        $context->method('getKey')->willReturn('address');
        return $context;
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function developerSources(): array
    {
        return [
            'a source of the developer' => ['select', 'Vendor\\Module\\Model\\Source\\Level'],
            'with a leading backslash' => ['select', '\\Vendor\\Module\\Model\\Source\\Level'],
            'a multiple select' => ['multiselect', 'Vendor\\Module\\Model\\Source\\Level'],
        ];
    }

    #[DataProvider('developerSources')]
    public function testADeveloperSourceNeedsNoOptionsAndSavesNone(string $input, ?string $sourceModel): void
    {
        $result = $this->processor()->process($this->context(), ['frontend_label' => [0 => 'Level']], $this->existing($input, $sourceModel));

        self::assertSame([], $result['errors']);
        self::assertArrayNotHasKey('option', $result['data'], 'nothing is written to the option table');
        self::assertArrayNotHasKey('default', $result['data']);
    }

    public function testOptionsPostedForADeveloperSourceAreIgnored(): void
    {
        $post = ['frontend_label' => [0 => 'Level'], 'option' => ['value' => ['option_0' => [0 => 'Injected']], 'delete' => []], 'default' => ['option_0']];
        $result = $this->processor()->process($this->context(), $post, $this->existing('select', 'Vendor\\Module\\Model\\Source\\Level'));

        self::assertSame([], $result['errors']);
        self::assertArrayNotHasKey('option', $result['data']);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function managedSources(): array
    {
        return [
            'the option table' => ['Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Table'],
            'with a leading backslash' => ['\\Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Table'],
            'no source model' => [null],
        ];
    }

    #[DataProvider('managedSources')]
    public function testTheOptionTableStillNeedsAnOption(?string $sourceModel): void
    {
        $result = $this->processor()->process($this->context(), ['frontend_label' => [0 => 'Level']], $this->existing('select', $sourceModel));

        self::assertContains('Add at least one option.', $result['errors']);
        self::assertArrayHasKey('option', $result['data']);
    }
}
