<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\SavedAddressRequirements;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\CustomerManagement;
use Magento\Quote\Model\Quote;

/**
 * When an order is placed with a saved address that lacks a required attribute (e.g. one added after the address was saved),
 * core fails with only the attribute name. This says which address needs what, and what to do about it.
 */
class CustomerManagementSavedAddressMessage
{
    public function __construct(
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly SavedAddressRequirements $requirements
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function beforeValidateAddresses(CustomerManagement $subject, Quote $quote): void
    {
        $addresses = [
            (string) __('billing') => $quote->getBillingAddress(),
        ];
        if (!$quote->isVirtual()) {
            $addresses[(string) __('shipping')] = $quote->getShippingAddress();
        }

        $messages = [];
        foreach ($addresses as $type => $quoteAddress) {
            $id = (int) $quoteAddress->getCustomerAddressId();
            if ($id <= 0) {
                continue;
            }
            try {
                $missing = $this->requirements->getMissing(
                    $this->addressRepository->getById($id),
                    (int) $quote->getCustomerGroupId(),
                    $quote->getStoreId()
                );
            } catch (NoSuchEntityException) {
                continue;
            }
            if ($missing !== []) {
                // One missing field is named; several get a generic text.
                $messages[$type] = count($missing) === 1
                    ? (string) __(
                        'Your saved %1 address needs the field %2. Please edit the address in My Account > Address Book, or choose another address.',
                        $type,
                        '"' . $missing[0]['label'] . '"'
                    )
                    : (string) __(
                        'Your saved %1 address has some required fields missing. Please edit the address in My Account > Address Book, or choose another address.',
                        $type
                    );
            }
        }
        if ($messages !== []) {
            throw new LocalizedException(__(implode(' ', array_unique($messages))));
        }
    }
}
