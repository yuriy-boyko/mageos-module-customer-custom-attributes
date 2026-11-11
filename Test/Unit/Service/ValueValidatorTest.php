<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\ValueValidator;
use PHPUnit\Framework\TestCase;

class ValueValidatorTest extends TestCase
{
    private ValueValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ValueValidator();
    }

    /**
     * @return list<string> message templates
     */
    private function messages(array $errors): array
    {
        return array_column($errors, 'message');
    }

    public function testEmptyValueIsValidWhenNotRequired(): void
    {
        self::assertSame([], $this->validator->validate('L', 'text', false, ['min_text_length' => 3], null));
        self::assertSame([], $this->validator->validate('L', 'text', false, [], ''));
    }

    public function testEmptyValueFailsWhenRequired(): void
    {
        self::assertSame([ValueValidator::MSG_REQUIRED], $this->messages($this->validator->validate('L', 'select', true, [], null)));
        self::assertSame(['L'], $this->validator->validate('L', 'text', true, [], '')[0]['args']);
    }

    public function testTextLengthIsCountedInCharacters(): void
    {
        $rules = ['min_text_length' => 3, 'max_text_length' => 6];
        self::assertSame([ValueValidator::MSG_MIN_LENGTH], $this->messages($this->validator->validate('L', 'text', false, $rules, 'ab')));
        self::assertSame(['L', '3'], $this->validator->validate('L', 'text', false, $rules, 'ab')[0]['args']);
        self::assertSame([ValueValidator::MSG_MAX_LENGTH], $this->messages($this->validator->validate('L', 'text', false, $rules, 'abcdefg')));
        self::assertSame([], $this->validator->validate('L', 'text', false, $rules, 'ü😀ü'));
        self::assertSame([], $this->validator->validate('L', 'text', false, $rules, 'abcdef'));
    }

    /**
     * @dataProvider inputValidationProvider
     */
    public function testInputValidation(string $rule, string $value, ?string $expectedMessage): void
    {
        $errors = $this->validator->validate('L', 'text', false, ['input_validation' => $rule], $value);
        self::assertSame($expectedMessage === null ? [] : [$expectedMessage], $this->messages($errors));
    }

    /**
     * @return array<string, array{string, string, string|null}>
     */
    public static function inputValidationProvider(): array
    {
        return [
            'numeric ok' => ['numeric', '12345', null],
            'numeric bad' => ['numeric', '12abc', ValueValidator::MSG_NUMERIC],
            'alpha ok' => ['alpha', 'Abc', null],
            'alpha bad' => ['alpha', 'Ab1', ValueValidator::MSG_ALPHA],
            'alphanumeric ok' => ['alphanumeric', 'Ab1', null],
            'alphanumeric bad' => ['alphanumeric', 'Ab 1', ValueValidator::MSG_ALPHANUMERIC],
            'email ok' => ['email', 'a@b.co', null],
            'email bad' => ['email', 'notanemail', ValueValidator::MSG_EMAIL],
            'url ok' => ['url', 'https://example.com/x', null],
            'url bad' => ['url', 'example', ValueValidator::MSG_URL],
            'length only has no format' => ['length', 'anything !', null],
        ];
    }

    public function testInputValidationDoesNotApplyToTextArea(): void
    {
        self::assertSame([], $this->validator->validate('L', 'textarea', false, ['input_validation' => 'numeric'], "abc\ndef"));
    }

    public function testDateFormatAndRange(): void
    {
        $rules = ['date_range_min' => strtotime('2026-01-01 UTC'), 'date_range_max' => strtotime('2027-12-31 UTC')];
        self::assertSame([], $this->validator->validate('L', 'date', false, $rules, '2026-06-15 00:00:00'));
        self::assertSame([], $this->validator->validate('L', 'date', false, $rules, '2026-01-01'));
        self::assertSame([ValueValidator::MSG_DATE_MIN], $this->messages($this->validator->validate('L', 'date', false, $rules, '2025-12-31 00:00:00')));
        self::assertSame(['L', '2026-01-01'], $this->validator->validate('L', 'date', false, $rules, '2025-12-31')[0]['args']);
        self::assertSame([ValueValidator::MSG_DATE_MAX], $this->messages($this->validator->validate('L', 'date', false, $rules, '2028-01-01 00:00:00')));
        self::assertSame([ValueValidator::MSG_DATE_FORMAT], $this->messages($this->validator->validate('L', 'date', false, [], '5/6/27')));
        self::assertSame([ValueValidator::MSG_DATE_FORMAT], $this->messages($this->validator->validate('L', 'date', false, [], '2026-02-30')));
    }

    public function testSelectAcceptsOnlyKnownOptions(): void
    {
        self::assertSame([], $this->validator->validate('L', 'select', false, [], '225', ['225', '226']));
        self::assertSame([ValueValidator::MSG_OPTION], $this->messages($this->validator->validate('L', 'select', false, [], '99999', ['225', '226'])));
    }

    public function testMultiselectChecksEveryValue(): void
    {
        self::assertSame([], $this->validator->validate('L', 'multiselect', false, [], '222,224', ['222', '223', '224']));
        self::assertSame([ValueValidator::MSG_OPTION], $this->messages($this->validator->validate('L', 'multiselect', false, [], '222,999', ['222', '223'])));
    }

    public function testBooleanAcceptsZeroAndOne(): void
    {
        self::assertSame([], $this->validator->validate('L', 'boolean', false, [], '0'));
        self::assertSame([], $this->validator->validate('L', 'boolean', false, [], '1'));
        self::assertSame([ValueValidator::MSG_OPTION], $this->messages($this->validator->validate('L', 'boolean', false, [], '7')));
    }

    public function testBooleanZeroCountsAsAValueWhenRequired(): void
    {
        self::assertSame([], $this->validator->validate('L', 'boolean', true, [], '0'));
    }
}
