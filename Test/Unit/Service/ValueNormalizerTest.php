<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValueNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: ?string}>
     */
    public static function values(): array
    {
        return [
            'null' => [null, null],
            'empty string' => ['', null],
            'text' => ['Acme Ltd', 'Acme Ltd'],
            'zero string is a real value' => ['0', '0'],
            'int' => [5, '5'],
            'float' => [1.5, '1.5'],
            'true' => [true, '1'],
            'false' => [false, '0'],
            'multiselect array' => [['3', '5', '8'], '3,5,8'],
            'array drops empty items' => [['3', '', null, '8'], '3,8'],
            'empty array' => [[], null],
            'array of only empty' => [['', null], null],
            'array with zero' => [['0', '2'], '0,2'],
        ];
    }

    #[DataProvider('values')]
    public function testNormalize(mixed $input, ?string $expected): void
    {
        self::assertSame($expected, (new ValueNormalizer())->normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: ?string}>
     */
    public static function inputValues(): array
    {
        return [
            'plain date gets midnight' => ['date', '2026-12-31', '2026-12-31 00:00:00'],
            'date with time is kept' => ['date', '2026-12-31 10:30:00', '2026-12-31 10:30:00'],
            'date with midnight is kept' => ['date', '2026-12-31 00:00:00', '2026-12-31 00:00:00'],
            'date with ISO T' => ['date', '2026-12-31T10:30:00', '2026-12-31 10:30:00'],
            'date with fraction' => ['date', '2026-12-31 10:30:00.000000', '2026-12-31 10:30:00'],
            'unparsable date is kept' => ['date', 'not a date', 'not a date'],
            'text is not touched' => ['text', '2026-12-31', '2026-12-31'],
            'empty date' => ['date', '', null],
            'multiselect array' => ['multiselect', ['1', '2'], '1,2'],
        ];
    }

    #[DataProvider('inputValues')]
    public function testNormalizeForInput(string $inputType, mixed $input, ?string $expected): void
    {
        self::assertSame($expected, (new ValueNormalizer())->normalizeForInput($inputType, $input));
    }
}
