<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model;

use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use Magento\Quote\Model\Quote\Address;

/**
 * Reads the submitted custom attribute values of a quote address (as set by Hyvä Checkout, the REST API or the
 * admin order create form) and returns them normalised. Only attributes that are actually present on the address are returned, so an
 * address loaded without values never wipes stored ones.
 */
class QuoteAddressValueReader
{
    public function __construct(
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly ValueNormalizer $normalizer
    ) {
    }

    /**
     * @return array<string, string|null> attribute code => normalised value (null = cleared)
     */
    public function read(Address $address): array
    {
        $values = [];
        foreach ($this->attributeProvider->getUserDefined() as $code => $attribute) {
            if ($address->hasData($code)) {
                // A plain field posted onto an address that already has custom attributes (e.g. the admin order create
                // form merges a new address into the quote's one): core keeps it beside the custom attribute and the
                // stale custom attribute would win. The posted value is the newer one; move it into the custom attribute.
                $raw = $address->getData($code);
                $address->unsetData($code);
                $address->setCustomAttribute($code, $raw);
            } else {
                $custom = $address->getCustomAttribute($code);
                if ($custom === null) {
                    continue;
                }
                $raw = $custom->getValue();
            }
            $values[$code] = $this->normalizer->normalizeForInput((string) $attribute->getFrontendInput(), $raw);
        }
        return $values;
    }
}
