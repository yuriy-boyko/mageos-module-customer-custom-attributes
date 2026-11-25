<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Puts the "Additional Information" variable into the text of an order email template: at the end of the order details table, after the
 * row with the payment method and the shipping method (`method-info` cells of the core new order template). Nothing is changed when the
 * template already has the variable (the merchant placed it) or has no such table (a custom template): the merchant adds the variable.
 *
 * Pure PHP, no Magento imports.
 */
class EmailTemplateInserter
{
    private const ANCHOR = 'class="method-info"';

    /**
     * @return string|null the template text with the variable, null when nothing is to be inserted
     */
    public function insert(string $templateText, string $directive): ?string
    {
        if (str_contains($templateText, AdditionalInformation::VARIABLE)) {
            return null;
        }
        $anchor = strrpos($templateText, self::ANCHOR);
        if ($anchor === false) {
            return null;
        }
        $end = stripos($templateText, '</table>', $anchor);
        if ($end === false) {
            return null;
        }
        return substr($templateText, 0, $end) . $directive . "\n" . substr($templateText, $end);
    }
}
