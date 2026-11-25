<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * The order emails that can carry the "Additional Information" section, and the value of the setting "Show in Emails" of an attribute:
 * the list of emails it appears in (key `mageos_email` of the validate rules). A stored 1 (the earlier Yes/No flag) means every email;
 * nothing means none.
 *
 * Pure PHP, no Magento imports.
 */
class EmailTypes
{
    public const ORDER = 'order';
    public const INVOICE = 'invoice';
    public const SHIPMENT = 'shipment';
    public const CREDITMEMO = 'creditmemo';

    /**
     * @return array<string, string> code => label, in the order the emails are listed
     */
    public static function labels(): array
    {
        return [
            self::ORDER => 'New Order',
            self::INVOICE => 'Invoice',
            self::SHIPMENT => 'Shipment',
            self::CREDITMEMO => 'Credit Memo',
        ];
    }

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return array_keys(self::labels());
    }

    /**
     * The emails of a stored or posted value, known codes only, each once, in the order of {@see all()}.
     *
     * @return string[]
     */
    public function normalize(mixed $value): array
    {
        if (is_array($value)) {
            $codes = array_map('strval', $value);
            return array_values(array_intersect(self::all(), $codes));
        }
        $scalar = is_scalar($value) ? (string) $value : '';
        if ($scalar === '1') {
            return self::all();
        }
        return in_array($scalar, self::all(), true) ? [$scalar] : [];
    }
}
