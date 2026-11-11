<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\ViewModel;

use MageOS\CustomerCustomAttributes\Model\AddressValueValidator;
use MageOS\CustomerCustomAttributes\Model\Checkout\CustomerCheckoutAttributes;
use MageOS\CustomerCustomAttributes\Model\Dependency\RuleProvider;
use MageOS\CustomerCustomAttributes\Model\SavedAddressRequirements;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Data for the Hyvä Checkout script that hides the dependent address fields instantly (the server follows with the real
 * update): the attributes of the checkout address forms, the rules, the group restrictions and the quote's customer group.
 */
class HyvaCheckoutVisibility implements ArgumentInterface
{
    public function __construct(
        private readonly RuleProvider $ruleProvider,
        private readonly AddressValueValidator $validator,
        private readonly CustomerCheckoutAttributes $customerAttributes,
        private readonly CheckoutSession $checkoutSession,
        private readonly SavedAddressRequirements $requirements,
        private readonly CustomerSession $customerSession,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @return array{codes: string[], rules: list<array{parent: string, option: string, dependent: string}>, restrictions: object, groupId: int, customer: array{codes: string[], rules: list<array{parent: string, option: string, dependent: string}>, restrictions: object}}
     */
    public function getConfig(): array
    {
        return [
            'codes' => $this->validator->getCheckoutCodes(),
            'rules' => $this->ruleProvider->getRules('address'),
            'restrictions' => (object) $this->ruleProvider->getGroupRestrictions('address'),
            'groupId' => (int) $this->checkoutSession->getQuote()->getCustomerGroupId(),
            // The customer attributes shown in their own checkout sections (same rules, other entity)
            'customer' => [
                'codes' => $this->customerAttributes->getCodes(),
                'rules' => $this->ruleProvider->getRules('customer'),
                'restrictions' => (object) $this->ruleProvider->getGroupRestrictions('customer'),
            ],
        ];
    }

    /**
     * The saved addresses of the logged-in customer that lack a required attribute, for the notice next to the address list.
     *
     * @return array{missing: object, editUrl: string, text: string, textMany: string, link: string}
     */
    public function getSavedAddressNotice(): array
    {
        $missing = [];
        if ($this->customerSession->isLoggedIn()) {
            $quote = $this->checkoutSession->getQuote();
            try {
                $customer = $this->customerRepository->getById((int) $this->customerSession->getCustomerId());
                foreach ($customer->getAddresses() ?? [] as $address) {
                    $problems = $this->requirements->getMissing($address, (int) $quote->getCustomerGroupId(), $quote->getStoreId());
                    if ($problems !== []) {
                        $missing[(string) $address->getId()] = $problems;
                    }
                }
            } catch (\Throwable) {
                $missing = [];
            }
        }
        return [
            'missing' => (object) $missing,
            'editUrl' => $this->urlBuilder->getUrl('customer/address/edit', ['id' => '__ID__']),
            'text' => (string) __('Your saved address needs the field %1.', '__FIELDS__'),
            'textMany' => (string) __('Your saved address has some required fields missing.'),
            'link' => (string) __('Click here to edit it'),
        ];
    }
}
