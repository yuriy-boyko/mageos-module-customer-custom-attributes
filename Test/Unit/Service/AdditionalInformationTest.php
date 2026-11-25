<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\AdditionalInformation;
use PHPUnit\Framework\TestCase;

class AdditionalInformationTest extends TestCase
{
    private AdditionalInformation $info;

    protected function setUp(): void
    {
        $this->info = new AdditionalInformation();
    }

    private function row(string $code, string $label, string $value, ?string $valueLabel = null, string $input = 'text'): array
    {
        return ['attribute_code' => $code, 'attribute_label' => $label, 'input_type' => $input, 'value' => $value, 'value_label' => $valueLabel];
    }

    public function testOnlyTheAttributesShownInTheEmailAreListed(): void
    {
        $rows = $this->info->rows(
            [$this->row('tax', 'Tax code', 'IT123'), $this->row('erp', 'ERP id', '9981'), $this->row('type', 'Customer type', '3', 'Company', 'select')],
            ['tax', 'type']
        );

        self::assertSame([['label' => 'Tax code', 'text' => 'IT123'], ['label' => 'Customer type', 'text' => 'Company']], $rows);
    }

    public function testTheLabelOfAnOptionBeatsItsValueAndADateLosesItsTime(): void
    {
        $rows = $this->info->rows([$this->row('born', 'Born', '1990-05-17 00:00:00', null, 'date'), $this->row('flag', 'Flag', '0', 'No', 'boolean')], ['born', 'flag']);

        self::assertSame('1990-05-17', $rows[0]['text']);
        self::assertSame('No', $rows[1]['text']);
    }

    public function testAMultiselectShowsItsLabelsAndALongTextKeepsItsLines(): void
    {
        $rows = $this->info->rows(
            [
                $this->row('tags', 'Interests', '1,3', 'Books, Music', 'multiselect'),
                $this->row('note', 'Note', "first\nsecond", null, 'textarea'),
            ],
            ['tags', 'note']
        );

        self::assertSame('Books, Music', $rows[0]['text']);
        self::assertSame("first\nsecond", $rows[1]['text']);
        self::assertStringContainsString("first<br>\nsecond", $this->info->html('Additional Information', [$rows[1]]));
    }

    public function testTheRowsKeepTheOrderOfTheSnapshotNotTheOrderOfTheCodes(): void
    {
        $rows = $this->info->rows([$this->row('b', 'B', '1'), $this->row('a', 'A', '2')], ['a', 'b']);

        self::assertSame(['B', 'A'], array_column($rows, 'label'));
    }

    public function testAnEmptyValueIsLeftOutButZeroIsAValue(): void
    {
        $rows = $this->info->rows([$this->row('a', 'A', ''), $this->row('b', 'B', '  '), $this->row('c', 'C', '0')], ['a', 'b', 'c']);

        self::assertSame([['label' => 'C', 'text' => '0']], $rows);
    }

    public function testTheSectionIsATableRowOverBothColumns(): void
    {
        $html = $this->info->html('Additional Information', [['label' => 'Tax code', 'text' => 'IT123'], ['label' => 'Note', 'text' => "two\nlines"]]);

        self::assertStringStartsWith('<tr><td class="method-info" colspan="2"><h3>Additional Information</h3>', $html);
        self::assertStringContainsString('<p>Tax code: IT123</p>', $html);
        self::assertStringContainsString("<p>Note: two<br>\nlines</p>", $html, 'a line break of a value stays a line break');
        self::assertStringEndsWith('</td></tr>', $html);
    }

    public function testTheValuesAndLabelsAreEscaped(): void
    {
        $html = $this->info->html('Info <b>', [['label' => 'A & "B"', 'text' => '<script>alert(1)</script>']]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('A &amp; &quot;B&quot;: &lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testNothingToShowGivesNoHtml(): void
    {
        self::assertSame('', $this->info->html('Additional Information', []));
    }
}
