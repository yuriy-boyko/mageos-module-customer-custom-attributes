<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\AddressValueValidator;
use MageOS\CustomerCustomAttributes\Model\QuoteAddressValueReader;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\QuoteAddressValueStorage;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
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
 *
 * Outside the admin only the attributes of the Hyvä Checkout address forms (enabled for the website) are kept on the quote
 * address. A value of any other attribute is dropped: one sent through the REST cart endpoints, or one left over from a saved
 * address chosen before (Hyvä writes a typed address with only the keys of its form, so the other keys would stay and reach the
 * order and, with "save in address book", the new address).
 */
class QuoteAddressSaveValues
{
    public function __construct(
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly QuoteAddressValueReader $valueReader,
        private readonly QuoteAddressValueStorage $storage,
        private readonly AddressValueValidator $validator,
        private readonly State $appState
    ) {
    }

    public function afterSave(AddressResource $subject, AddressResource $result, AbstractModel $object): AddressResource
    {
        // A deleted address (the API replaces the quote's billing and shipping address) has nothing to store values for.
        if (!$object instanceof Address || !$object->getId() || $object->isDeleted()) {
            return $result;
        }

        $idsByCode = $this->attributeProvider->getIdsByCode();
        $kept = $this->isAdmin() ? null : $this->validator->getCheckoutCodes();
        $values = [];
        foreach ($this->valueReader->read($object) as $code => $value) {
            if ($kept !== null && !in_array($code, $kept, true)) {
                // null deletes a row stored before
                $object->setCustomAttribute($code, null);
                $values[$idsByCode[$code]] = null;
                continue;
            }
            $values[$idsByCode[$code]] = $value;
        }
        $this->storage->save((int) $object->getId(), $values);
        return $result;
    }

    private function isAdmin(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (\Magento\Framework\Exception\LocalizedException) {
            // no area (a script or a cron job): keep every value
            return true;
        }
    }
}
