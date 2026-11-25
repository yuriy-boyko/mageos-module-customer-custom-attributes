<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * The "Additional Information" section of the order emails (new order, invoice, shipment, credit memo): the customer attribute values saved on the order (the snapshot) of the
 * attributes shown in the email, as a table row for the order details table of the email. The labels and texts are those saved with the
 * order, so the section never needs the attribute again.
 *
 * Pure PHP, no Magento imports.
 */
class AdditionalInformation
{
    /** the name of the template variable that holds the section: `{{var mageos_additional_information_html|raw}}` */
    public const VARIABLE = 'mageos_additional_information_html';

    /** set to 1 with the variable when the merchant wants the section put into the template automatically (a setting) */
    public const INSERT_VARIABLE = 'mageos_additional_information_insert';

    /**
     * @param array<int, array<string, mixed>> $snapshotRows rows of the order snapshot (attribute_code, attribute_label, input_type, value, value_label)
     * @param string[] $codes the attributes shown in the email
     * @return list<array{label: string, text: string}>
     */
    public function rows(array $snapshotRows, array $codes): array
    {
        $rows = [];
        foreach ($snapshotRows as $row) {
            if (!in_array((string) ($row['attribute_code'] ?? ''), $codes, true)) {
                continue;
            }
            $text = (string) (($row['value_label'] ?? '') !== '' ? $row['value_label'] : ($row['value'] ?? ''));
            if (($row['input_type'] ?? '') === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}/', $text) === 1) {
                $text = substr($text, 0, 10);
            }
            if (trim($text) === '') {
                continue;
            }
            $rows[] = ['label' => (string) ($row['attribute_label'] ?? $row['attribute_code'] ?? ''), 'text' => $text];
        }
        return $rows;
    }

    /**
     * A table row with a cell over both columns of the order details table, in the markup of the payment and shipping method cells.
     * Empty when there is nothing to show.
     *
     * @param list<array{label: string, text: string}> $rows
     */
    public function html(string $title, array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $html = '<tr><td class="method-info" colspan="2"><h3>' . $this->escape($title) . '</h3>';
        foreach ($rows as $row) {
            $html .= '<p>' . $this->escape($row['label']) . ': ' . nl2br($this->escape($row['text']), false) . '</p>';
        }
        return $html . '</td></tr>';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
