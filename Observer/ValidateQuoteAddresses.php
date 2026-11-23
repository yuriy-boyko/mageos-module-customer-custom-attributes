<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Observer;

use MageOS\CustomerCustomAttributes\Model\AddressValueValidator;
use MageOS\CustomerCustomAttributes\Model\Dependency\VisibilityService;
use MageOS\CustomerCustomAttributes\Model\QuoteAddressValueReader;
use MageOS\CustomerCustomAttributes\Plugin\ConvertQuoteAddressToOrderAddress;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote\Address;

/**
 * Event sales_model_service_quote_submit_before: checks the custom values of the billing and shipping address against the
 * attribute rules before the order is created, whatever way they got onto the quote (Hyvä Checkout, REST, admin).
 * The "required" flag is enforced for the attributes shown in the form the customer filled: the checkout address forms,
 * or all attributes in the admin. Outside the admin, values of attributes that are not in those forms (or disabled for the
 * website) are removed from the address first. Values of attributes hidden by a dependency rule or a customer group restriction
 * are removed as well and are not required.
 */
class ValidateQuoteAddresses implements ObserverInterface
{
    public function __construct(
        private readonly QuoteAddressValueReader $valueReader,
        private readonly AddressValueValidator $validator,
        private readonly VisibilityService $visibility,
        private readonly State $appState
    ) {
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        $order = $observer->getEvent()->getOrder();
        $enforce = $this->isAdmin() ? $this->validator->getAllCodes() : $this->validator->getCheckoutCodes();

        $addresses = [(string) __('Billing Address') => [$quote->getBillingAddress(), $order?->getBillingAddress()]];
        if (!$quote->isVirtual()) {
            $addresses[(string) __('Shipping Address')] = [$quote->getShippingAddress(), $order?->getShippingAddress()];
        }

        $messages = [];
        foreach ($addresses as $title => [$address, $orderAddress]) {
            if (!$address instanceof Address) {
                continue;
            }
            $values = array_fill_keys($enforce, null);
            $values = array_replace($values, $this->valueReader->read($address));

            // Outside the admin only the attributes of the Hyvä Checkout address forms (enabled for the website) are kept: a value
            // for any other attribute (sent through the REST cart endpoints, or carried from a saved address) is dropped, so a
            // customer cannot set an attribute the store keeps for the admin, has not put in the checkout or has disabled.
            if (!$this->isAdmin()) {
                foreach (array_diff(array_keys($values), $enforce) as $outside) {
                    if ($values[$outside] !== null) {
                        $address->setCustomAttribute($outside, null);
                        $this->removeFromOrderAddress($orderAddress, $outside);
                    }
                    unset($values[$outside]);
                }
            }

            // Attributes hidden by a dependency rule or the customer group are neither required nor kept on the order.
            $visible = $this->visibility->getVisibleCodes('address', array_keys($values), $values, (int) $quote->getCustomerGroupId());
            foreach (array_diff(array_keys($values), $visible) as $hidden) {
                if ($values[$hidden] !== null) {
                    $address->setCustomAttribute($hidden, null);
                    $this->removeFromOrderAddress($orderAddress, $hidden);
                }
                $values[$hidden] = null;
            }
            foreach ($this->validator->validate($values, $quote->getStoreId(), array_intersect($enforce, $visible)) as $message) {
                $messages[] = $title . ': ' . $message;
            }
        }
        if ($messages !== []) {
            throw new LocalizedException(__(implode(' ', array_unique($messages))));
        }
    }

    /**
     * The order's addresses are converted before this event fires; the values they carry are taken out as well.
     */
    private function removeFromOrderAddress(mixed $orderAddress, string $code): void
    {
        if (!is_object($orderAddress) || !method_exists($orderAddress, 'getData')) {
            return;
        }
        $carried = $orderAddress->getData(ConvertQuoteAddressToOrderAddress::DATA_KEY);
        if (is_array($carried) && array_key_exists($code, $carried)) {
            unset($carried[$code]);
            $orderAddress->setData(ConvertQuoteAddressToOrderAddress::DATA_KEY, $carried);
        }
    }

    private function isAdmin(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (LocalizedException) {
            return false;
        }
    }
}
