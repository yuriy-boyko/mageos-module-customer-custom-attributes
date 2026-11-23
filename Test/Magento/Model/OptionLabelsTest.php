<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Model;

use MageOS\CustomerCustomAttributes\Model\OptionLabels;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use PHPUnit\Framework\TestCase;

class OptionLabelsTest extends TestCase
{
    /**
     * An attribute whose option labels depend on its store id, like the table source (admin values for store 0).
     */
    private function attribute(): AbstractAttribute
    {
        $state = (object) ['storeId' => 0];
        $labels = [0 => 'North (admin)', 2 => 'Nord'];
        $source = $this->createStub(AbstractSource::class);
        $source->method('getAllOptions')->willReturnCallback(static fn (): array => [['value' => '7', 'label' => $labels[$state->storeId] ?? 'North']]);
        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('usesSource')->willReturn(true);
        $attribute->method('getSource')->willReturn($source);
        $attribute->method('getData')->willReturnCallback(static fn ($key = '') => $key === 'store_id' ? $state->storeId : null);
        $attribute->method('setData')->willReturnCallback(static function ($key, $value = null) use ($state, &$attribute) {
            if ($key === 'store_id') {
                $state->storeId = $value;
            }
            return $attribute;
        });
        return $attribute;
    }

    public function testTheLabelsOfTheGivenStoreAndTheStoreOfTheAttributeIsPutBack(): void
    {
        $attribute = $this->attribute();
        $labels = new OptionLabels();
        // in the admin the current store is the admin store: its labels are the admin values
        self::assertSame(['7' => 'North (admin)'], $labels->forAttribute($attribute));
        // the snapshot of an order of store view 2 takes that store view's labels
        self::assertSame(['7' => 'Nord'], $labels->forAttribute($attribute, 2));
        self::assertSame(0, $attribute->getData('store_id'), 'the attribute keeps its store');
    }
}
