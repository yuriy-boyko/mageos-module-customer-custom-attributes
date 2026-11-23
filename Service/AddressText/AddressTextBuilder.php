<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service\AddressText;

/**
 * Builds the lines that are appended to the standard address text ("Label: value", one per attribute) in the format of the
 * address renderer in use: html, text, pdf or oneline. Values are escaped for html; line breaks inside a value are kept in
 * html and text and flattened for pdf and oneline.
 *
 * Pure PHP, no Magento imports.
 */
class AddressTextBuilder
{
    public const FORMAT_HTML = 'html';
    public const FORMAT_PDF = 'pdf';
    public const FORMAT_ONELINE = 'oneline';

    /**
     * The output format for an address format: its own code for pdf and oneline, html for the html code and for every other
     * format that escapes HTML (an HTML template with its own code, such as the address formats of Hyvä Checkout), text otherwise.
     */
    public static function formatFor(string $code, bool $escapeHtml): string
    {
        if (in_array($code, [self::FORMAT_HTML, self::FORMAT_PDF, self::FORMAT_ONELINE], true)) {
            return $code;
        }
        return $escapeHtml ? self::FORMAT_HTML : $code;
    }

    /**
     * @param array<int, array{label: string, value: string}> $entries
     * @param string $existing the address text already rendered, used to join the new lines cleanly
     * @return string text to append to $existing, empty when there is nothing to add
     */
    public function build(string $format, array $entries, string $existing = ''): string
    {
        $lines = [];
        foreach ($entries as $entry) {
            $label = trim((string) ($entry['label'] ?? ''));
            $value = trim((string) ($entry['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }
            $lines[] = [$label, $value];
        }
        if ($lines === []) {
            return '';
        }

        return match ($format) {
            self::FORMAT_HTML => $this->html($lines, $existing),
            self::FORMAT_PDF => $this->pdf($lines, $existing),
            self::FORMAT_ONELINE => $this->oneline($lines),
            default => $this->text($lines, $existing),
        };
    }

    /**
     * @param array<int, array{0: string, 1: string}> $lines
     */
    private function html(array $lines, string $existing): string
    {
        $out = [];
        foreach ($lines as [$label, $value]) {
            $out[] = $this->escape($label) . ': ' . preg_replace('/\r\n|\r|\n/', '<br />', $this->escape($value));
        }
        $glue = preg_match('#<br\s*/?>\s*$#i', $existing) === 1 || trim($existing) === '' ? '' : '<br />';
        return $glue . implode('<br />', $out);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $lines
     */
    private function text(array $lines, string $existing): string
    {
        $out = [];
        foreach ($lines as [$label, $value]) {
            $out[] = $label . ': ' . str_replace(["\r\n", "\r"], "\n", $value);
        }
        $glue = $existing === '' || substr($existing, -1) === "\n" ? '' : "\n";
        return $glue . implode("\n", $out);
    }

    /**
     * The pdf format separates lines with "|", so a "|" inside a value must not survive.
     *
     * @param array<int, array{0: string, 1: string}> $lines
     */
    private function pdf(array $lines, string $existing): string
    {
        $out = [];
        foreach ($lines as [$label, $value]) {
            $out[] = $this->flatten($label . ': ' . $value, '|') . '|';
        }
        $glue = $existing === '' || preg_match('/\|\s*$/', $existing) === 1 ? '' : '|';
        return $glue . implode('', $out);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $lines
     */
    private function oneline(array $lines): string
    {
        $out = [];
        foreach ($lines as [$label, $value]) {
            $out[] = $this->flatten($label . ': ' . $value);
        }
        return ', ' . implode(', ', $out);
    }

    private function flatten(string $text, string $remove = ''): string
    {
        $text = preg_replace('/\s*[\r\n]+\s*/', ' ', $text) ?? $text;
        return $remove === '' ? $text : str_replace($remove, ' ', $text);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
