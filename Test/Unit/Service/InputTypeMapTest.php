<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\InputTypeMap;
use PHPUnit\Framework\TestCase;

class InputTypeMapTest extends TestCase
{
    private InputTypeMap $map;

    protected function setUp(): void
    {
        $this->map = new InputTypeMap();
    }

    public function testSupportedTypes(): void
    {
        foreach (["text", "textarea", "date", "boolean", "select", "multiselect"] as $type) {
            self::assertTrue($this->map->isSupported($type), $type);
        }
        self::assertFalse($this->map->isSupported("file"));
        self::assertFalse($this->map->isSupported(null));
        self::assertSame(array_keys($this->map->getLabels()), ["text", "textarea", "date", "boolean", "select", "multiselect"]);
    }

    public function testStorage(): void
    {
        self::assertSame("varchar", $this->map->getBackendType("text"));
        self::assertSame("text", $this->map->getBackendType("textarea"));
        self::assertSame("datetime", $this->map->getBackendType("date"));
        self::assertSame("int", $this->map->getBackendType("boolean"));
        self::assertSame("int", $this->map->getBackendType("select"));
        self::assertSame("varchar", $this->map->getBackendType("multiselect"));
    }

    public function testModels(): void
    {
        self::assertNull($this->map->getBackendModel("text"));
        self::assertSame("Magento\Eav\Model\Entity\Attribute\Backend\Datetime", $this->map->getBackendModel("date"));
        self::assertSame("Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend", $this->map->getBackendModel("multiselect"));
        self::assertSame("Magento\Eav\Model\Entity\Attribute\Source\Table", $this->map->getSourceModel("select"));
        self::assertSame("Magento\Eav\Model\Entity\Attribute\Source\Table", $this->map->getSourceModel("multiselect"));
        self::assertSame("Magento\Eav\Model\Entity\Attribute\Source\Boolean", $this->map->getSourceModel("boolean"));
        self::assertNull($this->map->getSourceModel("text"));
    }

    public function testDefaultValueFieldAndOptions(): void
    {
        self::assertSame("default_value_text", $this->map->getDefaultValueField("text"));
        self::assertSame("default_value_yesno", $this->map->getDefaultValueField("boolean"));
        self::assertNull($this->map->getDefaultValueField("select"));
        self::assertNull($this->map->getDefaultValueField("date"));
        self::assertTrue($this->map->usesOptions("select"));
        self::assertTrue($this->map->usesOptions("multiselect"));
        self::assertFalse($this->map->usesOptions("boolean"));
        self::assertTrue($this->map->supportsTextRules("textarea"));
        self::assertFalse($this->map->supportsTextRules("date"));
    }

    public function testDeveloperOptions(): void
    {
        $table = 'Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Table';
        self::assertTrue($this->map->hasDeveloperOptions('select', 'Vendor\\Module\\Model\\Source\\Level'));
        self::assertTrue($this->map->hasDeveloperOptions('multiselect', '\\Vendor\\Module\\Model\\Source\\Level'));
        self::assertFalse($this->map->hasDeveloperOptions('select', $table), 'the option table is managed in the admin');
        self::assertFalse($this->map->hasDeveloperOptions('select', '\\' . $table));
        self::assertFalse($this->map->hasDeveloperOptions('select', null));
        self::assertFalse($this->map->hasDeveloperOptions('select', ''));
        self::assertFalse($this->map->hasDeveloperOptions('text', 'Vendor\\Module\\Model\\Source\\Level'));
        self::assertFalse($this->map->hasDeveloperOptions('boolean', 'Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Boolean'));
    }
}
