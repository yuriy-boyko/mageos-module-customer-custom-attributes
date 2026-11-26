<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\Attribute;

use MageOS\CustomerCustomAttributes\Model\ResourceModel\AttributeWebsiteStorage;
use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use MageOS\CustomerCustomAttributes\Service\AdminOnlyAccess;
use MageOS\CustomerCustomAttributes\Service\RequiredAddressFormCheck;
use MageOS\CustomerCustomAttributes\Service\RequiredCustomerFormCheck;
use Magento\Customer\Model\Attribute;
use Magento\Framework\Message\ManagerInterface;

/**
 * Warns in the admin about a required attribute that a form cannot fill:
 *  - an address attribute in the address book form but not in the Hyvä Checkout address forms (core would block every order), or the
 *    other way round (a saved address cannot be completed in the account);
 *  - a customer attribute that is not in the registration or the account edit form, or that the customer cannot change: core checks every
 *    required customer attribute on each customer save, so registrations or account saves of customers without a value fail.
 */
class RequiredFormWarning
{
    public function __construct(
        private readonly RequiredAddressFormCheck $check,
        private readonly AttributeWebsiteStorage $websiteStorage,
        private readonly ManagerInterface $messageManager,
        private readonly RequiredCustomerFormCheck $customerCheck,
        private readonly AdminOnlyAccess $adminOnly
    ) {
    }

    public function warn(Attribute $attribute, string $entityKey): void
    {
        if (!$attribute->getId()) {
            return;
        }
        if ($entityKey === EntityDefinitions::CUSTOMER) {
            $this->warnCustomer($attribute);
            return;
        }
        if ($entityKey !== EntityDefinitions::ADDRESS) {
            return;
        }
        $required = $this->check->isRequiredAnywhere(
            (bool) $attribute->getIsRequired(),
            $this->websiteStorage->load((int) $attribute->getId())
        );
        $forms = array_map('strval', (array) $attribute->getUsedInForms());
        $label = $attribute->getFrontendLabel();
        $label = is_array($label) ? (string) reset($label) : (string) $label;
        if ($this->check->onlyInCheckout($required, $forms)) {
            $this->messageManager->addWarningMessage(__(
                'The attribute "%1" is required and in the Hyvä Checkout address forms, but not in the address book form. A saved address without a value for it cannot be completed in the customer account: the customer has to type a new address at the checkout. Add it to "Address Book (Customer Account)" so that customers can complete their saved addresses.',
                $label !== '' ? $label : $attribute->getAttributeCode()
            ));
            return;
        }
        if (!$this->check->blocksCheckout($required, $forms)) {
            return;
        }
        $this->messageManager->addWarningMessage(__(
            'The attribute "%1" is required and in the address book form, but not in the Hyvä Checkout address forms. Magento checks every required attribute of the address book form at the checkout, so no order can be placed until you add the attribute to "Hyvä Checkout Address Forms" or make it optional.',
            $label !== '' ? $label : $attribute->getAttributeCode()
        ));
    }

    private function warnCustomer(Attribute $attribute): void
    {
        $required = $this->check->isRequiredAnywhere(
            (bool) $attribute->getIsRequired(),
            $this->websiteStorage->load((int) $attribute->getId())
        );
        $missing = $this->customerCheck->missingForms(
            $required,
            array_map('strval', (array) $attribute->getUsedInForms()),
            $this->adminOnly->isAdminOnly((array) $attribute->getValidateRules())
        );
        $message = self::customerMessage($missing);
        if ($message === null) {
            return;
        }
        $label = $attribute->getFrontendLabel();
        $label = is_array($label) ? (string) reset($label) : (string) $label;
        $this->messageManager->addWarningMessage(__($message, $label !== '' ? $label : $attribute->getAttributeCode()));
    }

    /**
     * The warning for the forms a required customer attribute is missing from (%1 is the attribute), null when there is nothing to say.
     *
     * @param string[] $missing codes from RequiredCustomerFormCheck::missingForms()
     */
    public static function customerMessage(array $missing): ?string
    {
        $registration = in_array(RequiredCustomerFormCheck::REGISTRATION_FORM, $missing, true);
        $edit = in_array(RequiredCustomerFormCheck::ACCOUNT_EDIT_FORM, $missing, true);
        if ($registration && $edit) {
            return 'The attribute "%1" is required, but customers cannot fill it in (it is not in the registration and account edit forms, or the customer cannot change it). Magento checks every required attribute when a customer is saved: registrations, and account saves of customers without a value, fail. Make it optional, or show it in those forms.';
        }
        if ($registration) {
            return 'The attribute "%1" is required, but not in the registration form. Magento checks every required attribute when a customer is saved, so new customers cannot register. Add it to "Customer Registration" or make it optional.';
        }
        if ($edit) {
            return 'The attribute "%1" is required, but not in the account edit form. Magento checks every required attribute when a customer is saved, so a customer without a value cannot save the account. Add it to "Customer Account Edit" or make it optional.';
        }
        return null;
    }
}
