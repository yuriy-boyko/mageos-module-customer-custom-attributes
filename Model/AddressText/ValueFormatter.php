<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\AddressText;

use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Turns a stored value into the text shown to a customer: option labels for dropdown, multiple select and yes/no, and the
 * store's date format (date only, no time) for dates.
 */
class ValueFormatter
{
    public function __construct(private readonly TimezoneInterface $timezone)
    {
    }

    public function format(AbstractAttribute $attribute, string $raw): string
    {
        $input = (string) $attribute->getFrontendInput();
        if ($input === 'date') {
            return $this->formatDate($raw);
        }
        if (in_array($input, ['select', 'multiselect', 'boolean'], true) && $attribute->usesSource()) {
            $text = $attribute->getSource()->getOptionText($raw);
            if (is_array($text)) {
                return implode(', ', array_map('strval', $text));
            }
            if ($text !== false && $text !== null && $text !== '') {
                return (string) $text;
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
