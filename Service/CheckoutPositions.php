<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * The places of the checkout where customer attributes can be shown. The codes are stored in the attribute's validate rules,
 * the checkout layout of the companion module maps every code to a container of the checkout.
 */
final class CheckoutPositions
{
    public const SHIPPING_ADDRESS_ABOVE = 'shipping_address_above';
    public const SHIPPING_ADDRESS_BELOW = 'shipping_address_below';
    public const SHIPPING_METHODS_ABOVE = 'shipping_methods_above';
    public const SHIPPING_METHODS_BELOW = 'shipping_methods_below';
    public const PAYMENT_ABOVE = 'payment_above';
    public const PAYMENT_BELOW = 'payment_below';
    public const ORDER_SUMMARY = 'order_summary';

    private const LABELS = [
        self::SHIPPING_ADDRESS_ABOVE => 'Above Shipping Address',
        self::SHIPPING_ADDRESS_BELOW => 'Below Shipping Address',
        self::SHIPPING_METHODS_ABOVE => 'Above Shipping Methods',
        self::SHIPPING_METHODS_BELOW => 'Below Shipping Methods',
        self::PAYMENT_ABOVE => 'Above Payment Method',
        self::PAYMENT_BELOW => 'Below Payment Method',
        self::ORDER_SUMMARY => 'Order Summary',
    ];

    /**
     * @return array<string, string> position code => English label
     */
    public static function labels(): array
    {
        return self::LABELS;
    }

    /**
     * @return string[]
     */
    public static function codes(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(mixed $code): bool
    {
        return is_string($code) && isset(self::LABELS[$code]);
    }

    /**
     * Positions inside the shipping steps of the checkout. A virtual-only cart has no shipping step, so nothing is shown there.
     */
    public static function isShippingPosition(mixed $code): bool
    {
        return in_array($code, [
            self::SHIPPING_ADDRESS_ABOVE,
            self::SHIPPING_ADDRESS_BELOW,
            self::SHIPPING_METHODS_ABOVE,
            self::SHIPPING_METHODS_BELOW,
        ], true);
    }

    public static function default(): string
    {
        return self::PAYMENT_ABOVE;
    }
}
