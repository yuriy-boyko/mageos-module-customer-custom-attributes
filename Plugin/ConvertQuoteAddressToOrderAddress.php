<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\QuoteAddressValueReader;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\ToOrderAddress;
use Magento\Sales\Api\Data\OrderAddressInterface;

/**
 * Carries the quote address values to the new order address when the order is placed. The values are held on the order
 * address under DATA_KEY until the order address is saved and the snapshot rows are written.
 */
class ConvertQuoteAddressToOrderAddress
{
    public const DATA_KEY = 'mageos_cca_values';

    public function __construct(private readonly QuoteAddressValueReader $valueReader)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function afterConvert(
        ToOrderAddress $subject,
        OrderAddressInterface $result,
        Address $object,
        $data = []
    ): OrderAddressInterface {
        $values = array_filter($this->valueReader->read($object), static fn (?string $value): bool => $value !== null);
        if ($values !== []) {
            $result->setData(self::DATA_KEY, $values);
        }
        return $result;
    }
}
