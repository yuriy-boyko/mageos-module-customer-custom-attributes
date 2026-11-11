<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model;

use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use MageOS\CustomerCustomAttributes\Service\ValueValidator;
use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Model\AttributeMetadataDataProvider;

/**
 * Validates address attribute values against the attribute definitions (rules, options, required) and returns translated
 * messages. Adapter around the pure Service\ValueValidator.
 */
class AddressValueValidator
{
    public function __construct(
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly OptionLabels $optionLabels,
        private readonly ValueValidator $validator,
        private readonly AttributeMetadataDataProvider $metadataDataProvider
    ) {
    }

    /**
     * @param array<string, string|null> $values normalised values by attribute code; unknown codes are ignored
     * @param string[] $enforceRequired codes whose "required" flag is enforced (the attribute is shown in the form the
     *        values came from); other attributes are only checked when they have a value
     * @return string[] translated messages
     */
    public function validate(array $values, int|string|null $storeId, array $enforceRequired): array
    {
        $attributes = $this->attributeProvider->getUserDefined();
        $messages = [];
        foreach ($values as $code => $value) {
            if (!isset($attributes[$code])) {
                continue;
            }
            $attribute = $attributes[$code];
            $input = (string) $attribute->getFrontendInput();
            $errors = $this->validator->validate(
                (string) $attribute->getStoreLabel($storeId),
                $input,
                (bool) $attribute->getIsRequired() && in_array($code, $enforceRequired, true),
                (array) $attribute->getValidateRules(),
                $value,
                array_map('strval', array_keys($this->optionLabels->forAttribute($attribute)))
            );
            foreach ($errors as $error) {
                $messages[] = (string) __($error['message'], ...$error['args']);
            }
        }
        return $messages;
    }

    /**
     * Codes of the attributes shown in the checkout address forms (assigned to the form and enabled for the current website).
     *
     * @return string[]
     */
    public function getCheckoutCodes(): array
    {
        $codes = [];
        $collection = $this->metadataDataProvider->loadAttributesCollection(
            AddressMetadataInterface::ENTITY_TYPE_ADDRESS,
            EntityDefinitions::FORM_HYVA_CHECKOUT
        );
        foreach ($collection->getItems() as $item) {
            if ((int) $item->getIsVisible()) {
                $codes[] = (string) $item->getAttributeCode();
            }
        }
        return $codes;
    }

    /**
     * Codes of all user-defined address attributes (the admin address forms always include them).
     *
     * @return string[]
     */
    public function getAllCodes(): array
    {
        return array_keys($this->attributeProvider->getUserDefined());
    }
}
