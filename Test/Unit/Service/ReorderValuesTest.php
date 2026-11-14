<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\ReorderValues;
use PHPUnit\Framework\TestCase;

class ReorderValuesTest extends TestCase
{
    private ReorderValues $values;

    protected function setUp(): void
    {
        $this->values = new ReorderValues();
    }

    public function testRawValuesOfExistingAttributesAreCarried(): void
    {
        $rows = [
            ['attribute_code' => 'note', 'value' => 'hello', 'value_label' => 'hello'],
            ['attribute_code' => 'color', 'value' => '12', 'value_label' => 'Red'],
            ['attribute_code' => 'tags', 'value' => '3,5', 'value_label' => 'A, B'],
            ['attribute_code' => 'day', 'value' => '2026-05-06 00:00:00', 'value_label' => '2026-05-06'],
        ];
        self::assertSame(
            ['note' => 'hello', 'color' => '12', 'tags' => '3,5', 'day' => '2026-05-06 00:00:00'],
            $this->values->forQuoteAddress($rows, ['note', 'color', 'tags', 'day'])
        );
    }

    public function testDeletedAttributesAreLeftOut(): void
    {
        $rows = [['attribute_code' => 'gone', 'value' => 'x'], ['attribute_code' => 'note', 'value' => 'y']];
        self::assertSame(['note' => 'y'], $this->values->forQuoteAddress($rows, ['note']));
    }

    public function testEmptyAndMalformedRowsAreLeftOut(): void
    {
        $rows = [
            ['attribute_code' => 'note', 'value' => ''],
            ['attribute_code' => 'color', 'value' => null],
            ['attribute_code' => '', 'value' => 'x'],
            ['value' => 'no code'],
            ['attribute_code' => 'tags', 'value' => ['array']],
            ['attribute_code' => 'zero', 'value' => '0'],
        ];
        self::assertSame(['zero' => '0'], $this->values->forQuoteAddress($rows, ['note', 'color', 'tags', 'zero']));
    }

    public function testNoRowsGiveNoValues(): void
    {
        self::assertSame([], $this->values->forQuoteAddress([], ['note']));
    }
}
