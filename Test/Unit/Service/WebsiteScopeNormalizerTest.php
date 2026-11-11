<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\WebsiteScopeNormalizer;
use PHPUnit\Framework\TestCase;

class WebsiteScopeNormalizerTest extends TestCase
{
    private WebsiteScopeNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new WebsiteScopeNormalizer();
    }

    public function testEmptyChoicesMeanUseDefault(): void
    {
        $result = $this->normalizer->normalize([2 => ['is_visible' => '', 'is_required' => '', 'default_value' => '']], 'text', [1, 2]);
        self::assertSame([2 => ['is_visible' => null, 'is_required' => null, 'default_value' => null]], $result['rows']);
        self::assertSame([], $result['errors']);
    }

    public function testYesAndNoAreStoredAsIntegers(): void
    {
        $result = $this->normalizer->normalize([1 => ['is_visible' => '0', 'is_required' => '1']], 'text', [1, 2]);
        self::assertSame(0, $result['rows'][1]['is_visible']);
        self::assertSame(1, $result['rows'][1]['is_required']);
        self::assertArrayNotHasKey(2, $result['rows']);
    }

    public function testInvalidFlagIsAnError(): void
    {
        $result = $this->normalizer->normalize([1 => ['is_visible' => 'maybe']], 'text', [1]);
        self::assertNotSame([], $result['errors']);
        self::assertNull($result['rows'][1]['is_visible']);
    }

    public function testWebsitesThatDoNotExistAreIgnored(): void
    {
        self::assertSame([], $this->normalizer->normalize([9 => ['is_visible' => '1']], 'text', [1, 2])['rows']);
    }

    /**
     * @dataProvider defaultProvider
     */
    public function testDefaultValue(string $inputType, string $posted, ?string $expected, bool $expectError): void
    {
        $result = $this->normalizer->normalize([1 => ['default_value' => $posted]], $inputType, [1]);
        self::assertSame($expected, $result['rows'][1]['default_value']);
        self::assertSame($expectError, $result['errors'] !== []);
    }

    /**
     * @return array<string, array{string, string, string|null, bool}>
     */
    public static function defaultProvider(): array
    {
        return [
            'text is trimmed' => ['text', '  hello ', 'hello', false],
            'textarea' => ['textarea', 'a b', 'a b', false],
            'yes/no yes' => ['boolean', '1', '1', false],
            'yes/no no' => ['boolean', '0', '0', false],
            'yes/no other' => ['boolean', 'x', null, true],
            'dropdown has no default here' => ['select', '225', null, false],
            'date has no default here' => ['date', '2027-01-01', null, false],
            'too long' => ['text', str_repeat('a', 1001), null, true],
        ];
    }
}
