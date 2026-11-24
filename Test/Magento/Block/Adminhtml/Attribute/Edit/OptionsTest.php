<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Block\Adminhtml\Attribute\Edit;

use MageOS\CustomerCustomAttributes\Block\Adminhtml\Attribute\Edit\Options;
use MageOS\CustomerCustomAttributes\Controller\Adminhtml\Attribute as AttributeAction;
use MageOS\CustomerCustomAttributes\Model\OptionLabels;
use MageOS\CustomerCustomAttributes\Service\InputTypeMap;
use Magento\Backend\Block\Template\Context;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Escaper;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\Validator\UniversalFactory;
use PHPUnit\Framework\TestCase;

/**
 * The option area of the attribute edit page: the options of a developer's source model are listed read-only (and escaped), the
 * editable option table stays for every other attribute.
 */
class OptionsTest extends TestCase
{
    protected function setUp(): void
    {
        // the backend block base class asks the global object manager for two helpers
        $manager = $this->createStub(ObjectManagerInterface::class);
        $manager->method('get')->willReturnCallback(fn (string $class) => $this->createStub($class));
        ObjectManager::setInstance($manager);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ObjectManager::class, '_instance'))->setValue(null, null);
    }

    private function block(?AbstractAttribute $attribute): Options
    {
        $context = $this->createStub(Context::class);
        $context->method('getEscaper')->willReturn(new Escaper());
        $registry = new Registry();
        if ($attribute !== null) {
            $registry->register(AttributeAction::REGISTRY_KEY, $attribute);
        }
        return new Options(
            $context,
            $registry,
            $this->createStub(CollectionFactory::class),
            $this->createStub(UniversalFactory::class),
            new InputTypeMap(),
            new OptionLabels()
        );
    }

    /**
     * @param array<int, array{value: mixed, label: string}> $options
     */
    private function attribute(string $input, ?string $sourceModel, array $options = []): AbstractAttribute
    {
        $source = $this->createStub(AbstractSource::class);
        $source->method('getAllOptions')->willReturn($options);
        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getSourceModel')->willReturn($sourceModel);
        $attribute->method('usesSource')->willReturn(true);
        $attribute->method('getSource')->willReturn($source);
        return $attribute;
    }

    public function testTheOptionsOfADeveloperSourceAreListedWithValueAndLabel(): void
    {
        $html = $this->block($this->attribute('select', 'Vendor\\Module\\Source\\Level', [
            ['value' => 0, 'label' => 'No'],
            ['value' => 1, 'label' => 'Total'],
            ['value' => 2, 'label' => 'Partial'],
        ]))->developerOptionsHtml();

        self::assertNotNull($html);
        self::assertStringContainsString('id="mageos-cca-developer-options"', $html);
        self::assertStringContainsString('<td>0</td><td>No</td>', $html, 'the value 0 is listed too');
        self::assertStringContainsString('<td>1</td><td>Total</td>', $html);
        self::assertStringContainsString('<td>2</td><td>Partial</td>', $html);
        self::assertStringContainsString('Vendor\\Module\\Source\\Level', $html, 'it says where the options come from');
        self::assertStringNotContainsString('manage-options-panel', $html, 'no editable option table');
    }

    public function testValuesAndLabelsAreEscaped(): void
    {
        $html = (string) $this->block($this->attribute('select', 'Vendor\\Module\\Source\\Level', [
            ['value' => '"><script>alert(1)</script>', 'label' => '<b>Bold</b>'],
        ]))->developerOptionsHtml();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt;', $html);
    }

    public function testTheOptionTableAttributesKeepTheEditableTable(): void
    {
        $table = 'Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Table';
        self::assertNull($this->block($this->attribute('select', $table))->developerOptionsHtml());
        self::assertNull($this->block($this->attribute('multiselect', $table))->developerOptionsHtml());
        self::assertNull($this->block($this->attribute('select', null))->developerOptionsHtml());
    }

    public function testOtherInputTypesAndANewAttributeShowNothingOfTheirOwn(): void
    {
        self::assertNull($this->block($this->attribute('text', 'Vendor\\Module\\Source\\Level'))->developerOptionsHtml());
        self::assertNull($this->block(null)->developerOptionsHtml(), 'the new-attribute page has no attribute yet');
    }
}
