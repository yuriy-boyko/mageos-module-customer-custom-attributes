<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\QuoteAddressValueReader;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\QuoteAddressValueStorage;
use Magento\Framework\Model\AbstractModel;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\ResourceModel\Quote\Address as AddressResource;

/**
 * Stores the custom attribute values of a quote address in mageos_cca_quote_address_value every time the address is
 * saved. Hyvä Checkout and the admin order create save the quote address when the form is submitted.
 *
 * This is a plugin on the resource's save() and not an observer of the save-after event: the quote address resource
 * is version controlled and skips the save, and so the event, when no table column changed, e.g. when only a custom
 * value was edited.
 */
class QuoteAddressSaveValues
{
    public function __construct(
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly QuoteAddressValueReader $valueReader,
        private readonly QuoteAddressValueStorage $storage
    ) {
    }

    public function afterSave(AddressResource $subject, AddressResource $result, AbstractModel $object): AddressResource
    {
        // A deleted address (the API replaces the quote's billing and shipping address) has nothing to store values for.
        if (!$object instanceof Address || !$object->getId() || $object->isDeleted()) {
            return $result;
        }

        $idsByCode = $this->attributeProvider->getIdsByCode();
        $values = [];
        foreach ($this->valueReader->read($object) as $code => $value) {
            $values[$idsByCode[$code]] = $value;
        }
        $this->storage->save((int) $object->getId(), $values);
        return $result;
    }
}
