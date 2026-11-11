<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\FieldAttributeBuilder;
use PHPUnit\Framework\TestCase;

class FieldAttributeBuilderTest extends TestCase
{
    private FieldAttributeBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new FieldAttributeBuilder();
    }

    public function testRequiredOnly(): void
    {
        self::assertSame(['required' => true], $this->builder->build('select', true, ['input_validation' => 'email']));
        self::assertSame([], $this->builder->build('multiselect', false, []));
        self::assertSame([], $this->builder->build('boolean', false, []));
    }

    public function testTextDefaults(): void
    {
        self::assertSame(['type' => 'text'], $this->builder->build('text', false, []));
    }

    public function testTextValidationTypes(): void
    {
        self::assertSame(['type' => 'email'], $this->builder->build('text', false, ['input_validation' => 'email']));
        self::assertSame(['type' => 'url'], $this->builder->build('text', false, ['input_validation' => 'url']));
        $numeric = $this->builder->build('text', false, ['input_validation' => 'numeric']);
        self::assertSame('[0-9]+', $numeric['pattern']);
        self::assertArrayHasKey('pattern_message', $numeric);
        self::assertSame('[A-Za-z]+', $this->builder->build('text', false, ['input_validation' => 'alpha'])['pattern']);
        self::assertSame('[A-Za-z0-9]+', $this->builder->build('text', false, ['input_validation' => 'alphanumeric'])['pattern']);
        self::assertSame(['type' => 'text'], $this->builder->build('text', false, ['input_validation' => 'length']));
    }

    public function testLengthLimitsForTextAndTextarea(): void
    {
        self::assertSame(
            ['required' => true, 'type' => 'text', 'minlength' => 2, 'maxlength' => 10],
            $this->builder->build('text', true, ['input_validation' => 'length', 'min_text_length' => 2, 'max_text_length' => 10])
        );
        self::assertSame(
            ['maxlength' => 500],
            $this->builder->build('textarea', false, ['max_text_length' => '500', 'input_validation' => 'email'])
        );
    }

    public function testDateRange(): void
    {
        self::assertSame(
            ['min' => '2020-01-01', 'max' => '2030-12-31'],
            $this->builder->build('date', false, ['date_range_min' => 1577836800, 'date_range_max' => 1924905600])
        );
        self::assertSame([], $this->builder->build('date', false, []));
    }
}
