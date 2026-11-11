<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\AddressText;

/**
 * Builds the "label, value" entries added to an address text from the two kinds of address data the renderer receives:
 *  - a customer address or quote address: flat array with the attribute codes as keys, labels and options from the live
 *    attribute definition;
 *  - an order address: the snapshot rows (key "mageos_cca_attributes"), so an order shows what was ordered even when the
 *    attribute was renamed or deleted later.
 */
class EntryProvider
{
    public const SNAPSHOT_KEY = 'mageos_cca_attributes';

    public function __construct(
        private readonly AddressTextAttributes $textAttributes,
        private readonly ValueFormatter $valueFormatter
    ) {
    }

    /**
     * @param array<string, mixed> $addressData
     * @return array<int, array{label: string, value: string}>
     */
    public function entriesFor(array $addressData): array
    {
        if (is_array($addressData[self::SNAPSHOT_KEY] ?? null)) {
            return $this->fromSnapshot($addressData[self::SNAPSHOT_KEY]);
        }
        return $this->fromFlatArray($addressData);
    }

    /**
     * @param array<string, mixed> $addressData
     * @return array<int, array{label: string, value: string}>
     */
    private function fromFlatArray(array $addressData): array
    {
        $entries = [];
        foreach ($this->textAttributes->getShown() as $code => $attribute) {
            // A disabled attribute (for the website) is no longer offered; the lines of placed orders come from their snapshot.
            if (!(int) $attribute->getIsVisible()) {
                continue;
            }
            $raw = $addressData[$code] ?? null;
            if (is_array($raw)) {
                $raw = implode(',', $raw);
            }
            if ($raw === null || $raw === '' || !is_scalar($raw)) {
                continue;
            }
            $entries[] = [
                'label' => (string) $attribute->getStoreLabel(),
                'value' => $this->valueFormatter->format($attribute, (string) $raw),
            ];
        }
        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{label: string, value: string}>
     */
    private function fromSnapshot(array $rows): array
    {
        $hidden = $this->textAttributes->getHiddenCodes();
        // Same order as the live attributes (sort order); attributes that no longer exist keep their order after them.
        $position = array_flip(array_keys($this->textAttributes->getShown()));
        $indexed = array_values($rows);
        usort($indexed, static function (array $a, array $b) use ($position): int {
            $pa = $position[(string) ($a['attribute_code'] ?? '')] ?? PHP_INT_MAX;
            $pb = $position[(string) ($b['attribute_code'] ?? '')] ?? PHP_INT_MAX;
            return $pa <=> $pb;
        });
        $entries = [];
        foreach ($indexed as $row) {
            $code = (string) ($row['attribute_code'] ?? '');
            if ($code === '' || in_array($code, $hidden, true)) {
                continue;
            }
            $value = ($row['input_type'] ?? '') === 'date'
                ? $this->valueFormatter->formatDate((string) ($row['value'] ?? ''))
                : (string) ($row['value_label'] ?? $row['value'] ?? '');
            $entries[] = ['label' => (string) ($row['attribute_label'] ?? $code), 'value' => $value];
        }
        return $entries;
    }
}
