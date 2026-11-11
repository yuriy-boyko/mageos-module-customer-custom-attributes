<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\SnapshotRowBuilder;
use MageOS\CustomerCustomAttributes\Service\ValueLabelResolver;
use PHPUnit\Framework\TestCase;

class SnapshotRowBuilderTest extends TestCase
{
    private SnapshotRowBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new SnapshotRowBuilder(new ValueLabelResolver());
    }

    public function testSnapshotHoldsEverythingNeededToShowTheValueLater(): void
    {
        self::assertSame(
            [
                'attribute_code' => 'delivery_window',
                'attribute_label' => 'Delivery window',
                'input_type' => 'select',
                'value' => '4',
                'value_label' => 'Morning',
            ],
            $this->builder->build('delivery_window', 'Delivery window', 'select', '4', ['4' => 'Morning', '5' => 'Evening'])
        );
    }

    public function testMissingLabelFallsBackToTheCode(): void
    {
        $row = $this->builder->build('vat_note', null, 'text', 'abc');
        self::assertSame('vat_note', $row['attribute_label']);
        self::assertSame('abc', $row['value_label']);

        self::assertSame('vat_note', $this->builder->build('vat_note', '', 'text', 'abc')['attribute_label']);
    }
}
