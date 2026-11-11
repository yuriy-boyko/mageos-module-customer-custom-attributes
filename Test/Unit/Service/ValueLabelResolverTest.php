<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\ValueLabelResolver;
use PHPUnit\Framework\TestCase;

class ValueLabelResolverTest extends TestCase
{
    private ValueLabelResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ValueLabelResolver();
    }

    public function testEmptyValueHasNoLabel(): void
    {
        self::assertNull($this->resolver->resolve('select', null, ['1' => 'A']));
        self::assertNull($this->resolver->resolve('text', '', []));
    }

    public function testTextIsShownAsStored(): void
    {
        self::assertSame('Acme Ltd', $this->resolver->resolve('text', 'Acme Ltd', []));
    }

    public function testSelectMapsOptionIdToLabel(): void
    {
        self::assertSame('Red', $this->resolver->resolve('select', '12', ['12' => 'Red', '13' => 'Blue']));
    }

    public function testMultiselectMapsEveryId(): void
    {
        self::assertSame(
            'Red, Blue',
            $this->resolver->resolve('multiselect', '12,13', ['12' => 'Red', '13' => 'Blue'])
        );
    }

    public function testDeletedOptionFallsBackToTheStoredId(): void
    {
        self::assertSame('Red, 99', $this->resolver->resolve('multiselect', '12,99', ['12' => 'Red']));
    }

    public function testBoolean(): void
    {
        self::assertSame('Yes', $this->resolver->resolve('boolean', '1', ['1' => 'Yes', '0' => 'No']));
        self::assertSame('No', $this->resolver->resolve('boolean', '0', ['1' => 'Yes', '0' => 'No']));
    }

    public function testDateIsShownWithoutTime(): void
    {
        self::assertSame('2026-05-05', $this->resolver->resolve('date', '2026-05-05 00:00:00', []));
        self::assertSame('2026-05-05', $this->resolver->resolve('date', '2026-05-05T10:30:00', []));
    }

    public function testDateWithoutTimeOrUnknownFormatIsShownAsStored(): void
    {
        self::assertSame('2026-05-05', $this->resolver->resolve('date', '2026-05-05', []));
        self::assertSame('soon', $this->resolver->resolve('date', 'soon', []));
    }
}
