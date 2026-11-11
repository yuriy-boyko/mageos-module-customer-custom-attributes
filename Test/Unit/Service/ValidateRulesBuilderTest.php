<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use PHPUnit\Framework\TestCase;

class ValidateRulesBuilderTest extends TestCase
{
    private ValidateRulesBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ValidateRulesBuilder();
    }

    public function testNoRulesForOtherTypes(): void
    {
        foreach (["select", "multiselect", "boolean"] as $type) {
            $result = $this->builder->build($type, ["input_validation" => "email", "min_text_length" => "3"]);
            self::assertSame([], $result["rules"]);
            self::assertSame("", $result["frontend_class"]);
            self::assertSame([], $result["errors"]);
        }
    }

    public function testTextValidationRuleAndBrowserClass(): void
    {
        $result = $this->builder->build("text", ["input_validation" => "email"]);
        self::assertSame(["input_validation" => "email"], $result["rules"]);
        self::assertSame("validate-email", $result["frontend_class"]);
    }

    public function testLengthWithoutValidationSelectsTheLengthRule(): void
    {
        $result = $this->builder->build("text", ["input_validation" => "", "min_text_length" => "2", "max_text_length" => "10"]);
        self::assertSame(["input_validation" => "length", "min_text_length" => 2, "max_text_length" => 10], $result["rules"]);
        self::assertSame("", $result["frontend_class"]);
        self::assertSame([], $result["errors"]);
    }

    public function testTextareaGetsNoBrowserClass(): void
    {
        $result = $this->builder->build("textarea", ["input_validation" => "alpha", "max_text_length" => "100"]);
        self::assertSame(["input_validation" => "alpha", "max_text_length" => 100], $result["rules"]);
        self::assertSame("", $result["frontend_class"]);
    }

    public function testEmptyInputGivesNoRules(): void
    {
        $result = $this->builder->build("text", ["input_validation" => "", "min_text_length" => "", "max_text_length" => ""]);
        self::assertSame([], $result["rules"]);
        self::assertSame([], $result["errors"]);
    }

    public function testInvalidValuesAreReported(): void
    {
        self::assertNotSame([], $this->builder->build("text", ["input_validation" => "bogus"])["errors"]);
        self::assertNotSame([], $this->builder->build("text", ["min_text_length" => "-1"])["errors"]);
        self::assertNotSame([], $this->builder->build("text", ["max_text_length" => "abc"])["errors"]);
        self::assertNotSame([], $this->builder->build("text", ["min_text_length" => "5", "max_text_length" => "2"])["errors"]);
    }

    public function testDateRange(): void
    {
        $result = $this->builder->build("date", ["date_range_min" => "2020-01-01", "date_range_max" => "2030-12-31"]);
        self::assertSame(["date_range_min" => 1577836800, "date_range_max" => 1924905600], $result["rules"]);
        self::assertSame([], $result["errors"]);
    }

    public function testDateRangeErrors(): void
    {
        self::assertNotSame([], $this->builder->build("date", ["date_range_min" => "01/02/2020"])["errors"]);
        self::assertNotSame([], $this->builder->build("date", ["date_range_max" => "2020-02-31"])["errors"]);
        self::assertNotSame([], $this->builder->build("date", ["date_range_min" => "2030-01-01", "date_range_max" => "2020-01-01"])["errors"]);
        self::assertSame([], $this->builder->build("date", ["date_range_min" => "", "date_range_max" => ""])["rules"]);
    }

    public function testFormatDateIsTheReverse(): void
    {
        self::assertSame("2020-01-01", $this->builder->formatDate(1577836800));
        self::assertSame("2020-01-01", $this->builder->formatDate("1577836800"));
        self::assertSame("", $this->builder->formatDate(null));
        self::assertSame("", $this->builder->formatDate(0));
    }

    /**
     * @dataProvider displayProvider
     */
    public function testDisplayAs(string $inputType, string $displayAs, array $expectedRules, bool $expectError): void
    {
        $result = $this->builder->build($inputType, ["display_as" => $displayAs]);
        self::assertSame($expectedRules, $result["rules"]);
        self::assertSame($expectError, $result["errors"] !== []);
    }

    /**
     * @return array<string, array{string, string, array, bool}>
     */
    public static function displayProvider(): array
    {
        return [
            "select as radio" => ["select", "radio", ["mageos_display" => "radio"], false],
            "yes/no as radio" => ["boolean", "radio", ["mageos_display" => "radio"], false],
            "multiselect as checkbox" => ["multiselect", "checkbox", ["mageos_display" => "checkbox"], false],
            "default" => ["select", "", [], false],
            "checkbox on a dropdown is rejected" => ["select", "checkbox", [], true],
            "radio on a multiselect is rejected" => ["multiselect", "radio", [], true],
            "unknown value is rejected" => ["select", "tiles", [], true],
        ];
    }

    public function testGetDisplayOnlyReturnsAValidChoiceForTheInputType(): void
    {
        self::assertSame("radio", $this->builder->getDisplay("select", ["mageos_display" => "radio"]));
        self::assertSame("radio", $this->builder->getDisplay("boolean", ["mageos_display" => "radio"]));
        self::assertSame("checkbox", $this->builder->getDisplay("multiselect", ["mageos_display" => "checkbox"]));
        self::assertSame("", $this->builder->getDisplay("multiselect", ["mageos_display" => "radio"]));
        self::assertSame("", $this->builder->getDisplay("select", ["mageos_display" => "checkbox"]));
        self::assertSame("", $this->builder->getDisplay("text", ["mageos_display" => "radio"]));
        self::assertSame("", $this->builder->getDisplay("select", []));
    }

    public function testCheckoutSettingsAreStoredForAnyInputType(): void
    {
        $result = $this->builder->build("text", [
            "checkout_position" => "payment_below",
            "checkout_sort" => "20",
            "checkout_prefill" => "1",
        ]);
        self::assertSame([], $result["errors"]);
        self::assertSame("payment_below", $result["rules"]["mageos_checkout_position"]);
        self::assertSame(20, $result["rules"]["mageos_checkout_sort"]);
        self::assertSame(1, $result["rules"]["mageos_checkout_prefill"]);
    }

    public function testNoCheckoutPositionStoresNoCheckoutSettings(): void
    {
        $rules = $this->builder->build("date", ["checkout_sort" => "5", "checkout_prefill" => "1"])["rules"];
        self::assertArrayNotHasKey("mageos_checkout_position", $rules);
        self::assertArrayNotHasKey("mageos_checkout_sort", $rules);
        self::assertArrayNotHasKey("mageos_checkout_prefill", $rules);
    }

    public function testInvalidCheckoutSettingsAreReported(): void
    {
        self::assertContains("Invalid checkout position.", $this->builder->build("text", ["checkout_position" => "nowhere"])["errors"]);
        self::assertContains(
            "The checkout sort order must be a whole number.",
            $this->builder->build("text", ["checkout_position" => "order_summary", "checkout_sort" => "-1"])["errors"]
        );
    }

    public function testGetCheckoutSettingsFallsBackToTheDefaultPosition(): void
    {
        self::assertSame(["position" => "payment_above", "sort" => 0, "prefill" => false], $this->builder->getCheckoutSettings([]));
        self::assertSame(
            ["position" => "order_summary", "sort" => 3, "prefill" => true],
            $this->builder->getCheckoutSettings([
                "mageos_checkout_position" => "order_summary",
                "mageos_checkout_sort" => 3,
                "mageos_checkout_prefill" => 1,
            ])
        );
        self::assertSame("payment_above", $this->builder->getCheckoutSettings(["mageos_checkout_position" => "bogus"])["position"]);
    }
}
