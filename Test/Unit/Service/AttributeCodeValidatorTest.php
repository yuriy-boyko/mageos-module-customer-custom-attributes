<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\AttributeCodeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttributeCodeValidatorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function codes(): array
    {
        return [
            "simple" => ["delivery_note", true],
            "with digits" => ["tax_id2", true],
            "empty" => ["", false],
            "uppercase" => ["Delivery", false],
            "starts with digit" => ["1note", false],
            "starts with underscore" => ["_note", false],
            "space" => ["my note", false],
            "dash" => ["my-note", false],
            "unicode" => ["nöte", false],
            "reserved" => ["quote_id", false],
        ];
    }

    #[DataProvider("codes")]
    public function testCodes(string $code, bool $valid): void
    {
        $error = (new AttributeCodeValidator())->validate($code, 60, ["quote_id", "entity_id"]);
        self::assertSame($valid, $error === null, (string) $error);
    }

    public function testLengthLimit(): void
    {
        $validator = new AttributeCodeValidator();
        self::assertNull($validator->validate(str_repeat("a", 51), 51));
        self::assertNotNull($validator->validate(str_repeat("a", 52), 51));
        self::assertNull($validator->validate(str_repeat("a", 60), 60));
        self::assertNotNull($validator->validate(str_repeat("a", 61), 60));
    }
}
