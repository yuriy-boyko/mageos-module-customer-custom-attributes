<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\EmailTemplateInserter;
use PHPUnit\Framework\TestCase;

class EmailTemplateInserterTest extends TestCase
{
    private const DIRECTIVE = '{{var mageos_additional_information_html|raw}}';

    /** the order details table of the core new order email */
    private const CORE = <<<'HTML'
<table class="order-details">
    <tr>
        <td class="address-details"><h3>{{trans "Billing Info"}}</h3></td>
    </tr>
    <tr>
        <td class="method-info">
            <h3>{{trans "Payment Method"}}</h3>
            {{var payment_html|raw}}
        </td>
        {{depend order_data.is_not_virtual}}
        <td class="method-info">
            <h3>{{trans "Shipping Method"}}</h3>
        </td>
        {{/depend}}
    </tr>
</table>
{{layout handle="sales_email_order_items" order_id=$order_id area="frontend"}}
HTML;

    public function testTheVariableGoesAtTheEndOfTheOrderDetailsTable(): void
    {
        $result = (new EmailTemplateInserter())->insert(self::CORE, self::DIRECTIVE);

        self::assertNotNull($result);
        $variable = strpos($result, self::DIRECTIVE);
        self::assertGreaterThan(strpos($result, 'Shipping Method'), $variable, 'below the shipping method');
        self::assertLessThan(strpos($result, '</table>'), $variable, 'inside the table, so the section is a row of it');
        self::assertLessThan(strpos($result, '{{layout handle'), $variable, 'above the items');
        self::assertSame(1, substr_count($result, self::DIRECTIVE));
    }

    public function testNothingIsInsertedWhenTheMerchantPlacedTheVariable(): void
    {
        $template = str_replace('</table>', self::DIRECTIVE . '</table>', self::CORE);

        self::assertNull((new EmailTemplateInserter())->insert($template, self::DIRECTIVE));
    }

    public function testNothingIsInsertedIntoATemplateWithoutTheMethodCells(): void
    {
        self::assertNull((new EmailTemplateInserter())->insert('<table><tr><td>Custom order mail</td></tr></table>', self::DIRECTIVE));
        self::assertNull((new EmailTemplateInserter())->insert('<td class="method-info">no table end', self::DIRECTIVE));
    }

    public function testTheLastMethodCellDecidesWhenThereAreSeveralTables(): void
    {
        $template = '<table><tr><td class="method-info">a</td></tr></table><table><tr><td class="method-info">b</td></tr></table>';
        $result = (new EmailTemplateInserter())->insert($template, self::DIRECTIVE);

        self::assertSame('<table><tr><td class="method-info">a</td></tr></table><table><tr><td class="method-info">b</td></tr>' . self::DIRECTIVE . "\n</table>", $result);
    }
}
