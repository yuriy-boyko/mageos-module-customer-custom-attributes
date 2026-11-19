<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Model\AttributeMetadataDataProvider;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Hyvä Checkout shows the address attributes assigned to the form "customer_register_address". For user-defined
 * attributes this plugin replaces that rule: they appear in the checkout address forms only when the attribute is
 * assigned to the pseudo form "mageos_hyva_checkout" ("Hyvä Checkout Address Forms" in the admin). System attributes are
 * not touched, and "customer_register_address" keeps its normal meaning (the registration address form).
 */
class HyvaCheckoutAddressAttributeFilter implements ResetAfterRequestInterface
{
    /** @var string[]|null */
    private ?array $checkoutCodes = null;

    public function __construct(
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly AttributeMetadataDataProvider $metadataDataProvider
    ) {
    }

    /**
     * @param object $subject Hyva\Checkout\Model\Form\AddressAttributeFormFilter
     * @param string[] $result attribute codes allowed in the checkout address forms
     * @return string[]
     */
    public function afterGetCheckoutAddressAttributeCodes($subject, array $result): array
    {
        $userDefined = array_keys($this->attributeProvider->getUserDefined());
        if ($userDefined === []) {
            return $result;
        }

        $result = array_diff($result, $userDefined);
        foreach ($this->getUserDefinedCheckoutCodes($userDefined) as $code) {
            $result[] = $code;
        }
        return array_values(array_unique($result));
    }

    /**
     * @param string[] $userDefined
     * @return string[]
     */
    private function getUserDefinedCheckoutCodes(array $userDefined): array
    {
        if ($this->checkoutCodes === null) {
            $this->checkoutCodes = [];
            $attributes = $this->metadataDataProvider->loadAttributesCollection(
                AddressMetadataInterface::ENTITY_TYPE_ADDRESS,
                EntityDefinitions::FORM_HYVA_CHECKOUT
            );
            foreach ($attributes->getItems() as $attribute) {
                $code = (string) $attribute->getAttributeCode();
                // The enabled flag can differ per website: a disabled attribute is not offered in the checkout.
                if (in_array($code, $userDefined, true) && (int) $attribute->getIsVisible()) {
                    $this->checkoutCodes[] = $code;
                }
            }
        }
        return $this->checkoutCodes;
    }

    public function _resetState(): void
    {
        $this->checkoutCodes = null;
    }
}
