<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\Attribute;

use MageOS\CustomerCustomAttributes\Model\ResourceModel\AttributeWebsiteStorage;
use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use MageOS\CustomerCustomAttributes\Service\RequiredAddressFormCheck;
use Magento\Customer\Model\Attribute;
use Magento\Framework\Message\ManagerInterface;

/**
 * Warns in the admin when an address attribute is required and in the address book form but not in the Hyvä Checkout address forms:
 * core would block every order, because the attribute cannot be filled at the checkout.
 */
class RequiredAddressFormWarning
{
    public function __construct(
        private readonly RequiredAddressFormCheck $check,
        private readonly AttributeWebsiteStorage $websiteStorage,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function warn(Attribute $attribute, string $entityKey): void
    {
        if ($entityKey !== EntityDefinitions::ADDRESS || !$attribute->getId()) {
            return;
        }
        $required = $this->check->isRequiredAnywhere(
            (bool) $attribute->getIsRequired(),
            $this->websiteStorage->load((int) $attribute->getId())
        );
        $forms = array_map('strval', (array) $attribute->getUsedInForms());
        if (!$this->check->blocksCheckout($required, $forms)) {
            return;
        }
        $label = $attribute->getFrontendLabel();
        $label = is_array($label) ? (string) reset($label) : (string) $label;
        $this->messageManager->addWarningMessage(__(
            'The attribute "%1" is required and in the address book form, but not in the Hyvä Checkout address forms. Magento checks every required attribute of the address book form at the checkout, so no order can be placed until you add the attribute to "Hyvä Checkout Address Forms" or make it optional.',
            $label !== '' ? $label : $attribute->getAttributeCode()
        ));
    }
}
