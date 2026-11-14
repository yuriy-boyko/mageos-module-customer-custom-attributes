<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\AddressText;

use MageOS\CustomerCustomAttributes\Model\OptionLabels;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Turns a stored value into the text shown to a customer: option labels for dropdown, multiple select and yes/no, and the
 * store's date format (date only, no time) for dates.
 */
class ValueFormatter implements ResetAfterRequestInterface
{
    /** @var array<int, array<string, string>> option labels by attribute id, read once per request (not once per address) */
    private array $labels = [];

    public function __construct(
        private readonly TimezoneInterface $timezone,
        private readonly OptionLabels $optionLabels
    ) {
    }

    public function _resetState(): void
    {
        $this->labels = [];
    }

    public function format(AbstractAttribute $attribute, string $raw): string
    {
        $input = (string) $attribute->getFrontendInput();
        if ($input === 'date') {
            return $this->formatDate($raw);
        }
        if (in_array($input, ['select', 'multiselect', 'boolean'], true) && $attribute->usesSource()) {
            // One read of the options per attribute and request: the source's getOptionText() asks the database on every call,
            // which costs a statement per address and dropdown on pages that list addresses.
            $map = $this->labels[(int) $attribute->getId()] ??= $this->optionLabels->forAttribute($attribute);
            $texts = [];
            foreach (explode(',', $raw) as $value) {
                $value = trim($value);
                if ($value !== '' && isset($map[$value])) {
                    $texts[] = $map[$value];
                }
            }
            if ($texts !== []) {
                return implode(', ', $texts);
            }
        }
        return $raw;
    }

    /**
     * The stored date is a calendar date, so it is formatted in UTC: no timezone shift, and no time shown.
     */
    public function formatDate(string $raw): string
    {
        try {
            $date = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
            return $this->timezone->formatDateTime($date, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, null, 'UTC');
        } catch (\Throwable $e) {
            return $raw;
        }
    }
}
