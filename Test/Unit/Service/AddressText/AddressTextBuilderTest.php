<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service\AddressText;

use MageOS\CustomerCustomAttributes\Service\AddressText\AddressTextBuilder;
use PHPUnit\Framework\TestCase;

class AddressTextBuilderTest extends TestCase
{
    private AddressTextBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new AddressTextBuilder();
    }

    public function testNothingToAdd(): void
    {
        self::assertSame('', $this->builder->build('html', []));
        self::assertSame('', $this->builder->build('html', [['label' => 'Note', 'value' => '']]));
        self::assertSame('', $this->builder->build('html', [['label' => '', 'value' => 'x']]));
    }

    public function testHtmlIsEscapedAndJoinedWithBreaks(): void
    {
        $entries = [
            ['label' => 'Note <b>', 'value' => 'a "quote" & <script>'],
            ['label' => 'Window', 'value' => 'Morning'],
        ];
        self::assertSame(
            '<br />Note &lt;b&gt;: a &quot;quote&quot; &amp; &lt;script&gt;<br />Window: Morning',
            $this->builder->build('html', $entries, 'John Doe<br />T: 123')
        );
    }

    public function testHtmlDoesNotDoubleTheBreak(): void
    {
        $entries = [['label' => 'Note', 'value' => 'x']];
        self::assertSame('Note: x', $this->builder->build('html', $entries, "Country<br />\n"));
        self::assertSame('Note: x', $this->builder->build('html', $entries, ''));
        self::assertSame('<br />Note: x', $this->builder->build('html', $entries, 'Country'));
    }

    public function testHtmlKeepsLineBreaksInValues(): void
    {
        self::assertSame(
            '<br />Area: one<br />two',
            $this->builder->build('html', [['label' => 'Area', 'value' => "one\ntwo"]], 'X')
        );
    }

    public function testText(): void
    {
        $entries = [['label' => 'Note', 'value' => "a\r\nb"], ['label' => 'Date', 'value' => '1 Jan 2027']];
        self::assertSame("\nNote: a\nb\nDate: 1 Jan 2027", $this->builder->build('text', $entries, 'John'));
        self::assertSame("Note: a\nb\nDate: 1 Jan 2027", $this->builder->build('text', $entries, "John\n"));
        self::assertSame("Note: a\nb\nDate: 1 Jan 2027", $this->builder->build('text', $entries));
    }

    public function testPdfUsesPipeSeparatorsAndRemovesPipesFromValues(): void
    {
        $entries = [['label' => 'Note', 'value' => "a|b\nc"], ['label' => 'Window', 'value' => 'Morning']];
        self::assertSame('|Note: a b c|Window: Morning|', $this->builder->build('pdf', $entries, 'John'));
        self::assertSame('Note: a b c|Window: Morning|', $this->builder->build('pdf', $entries, "John|\n"));
    }

    public function testOneline(): void
    {
        $entries = [['label' => 'Note', 'value' => "a\nb"], ['label' => 'Window', 'value' => 'Morning']];
        self::assertSame(', Note: a b, Window: Morning', $this->builder->build('oneline', $entries, 'John, Street'));
    }

    public function testUnknownFormatFallsBackToText(): void
    {
        self::assertSame("\nNote: x", $this->builder->build('something', [['label' => 'Note', 'value' => 'x']], 'A'));
    }

    public function testLabelsAndValuesAreTrimmed(): void
    {
        self::assertSame('Note: x', $this->builder->build('text', [['label' => ' Note ', 'value' => " x \n"]]));
    }

    public function testFormatForKnownCodes(): void
    {
        self::assertSame('html', AddressTextBuilder::formatFor('html', true));
        self::assertSame('pdf', AddressTextBuilder::formatFor('pdf', true));
        self::assertSame('oneline', AddressTextBuilder::formatFor('oneline', true));
        self::assertSame('text', AddressTextBuilder::formatFor('text', false));
    }

    public function testAnEscapedFormatWithItsOwnCodeGetsEscapedHtml(): void
    {
        // the address formats of Hyvä Checkout are HTML templates with their own codes
        $format = AddressTextBuilder::formatFor('hyva_checkout_shipping', true);
        self::assertSame('html', $format);
        self::assertSame(
            '<br />Note: &lt;i&gt;x&lt;/i&gt;<br />Notes: one<br />two',
            $this->builder->build($format, [['label' => 'Note', 'value' => '<i>x</i>'], ['label' => 'Notes', 'value' => "one\ntwo"]], 'Ada<br />United States')
        );
        self::assertSame('custom_text', AddressTextBuilder::formatFor('custom_text', false), 'an unescaped format stays text');
    }
}
