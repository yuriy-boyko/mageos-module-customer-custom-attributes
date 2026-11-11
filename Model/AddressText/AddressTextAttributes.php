<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\AddressText;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Model\AttributeMetadataDataProvider;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The user-defined address attributes whose values are added to the address text: those assigned to the pseudo form
 * "mageos_address_text" ("Address Text" in "Show in Forms"), in sort order.
 */
class AddressTextAttributes implements ResetAfterRequestInterface
{
    /** @var array<string, AbstractAttribute>|null */
    private ?array $shown = null;

    public function __construct(
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly AttributeMetadataDataProvider $metadataDataProvider
    ) {
    }

    /**
     * @return array<string, AbstractAttribute> code => attribute
     */
    public function getShown(): array
    {
        if ($this->shown === null) {
            $this->shown = [];
            $userDefined = $this->attributeProvider->getUserDefined();
            $collection = $this->metadataDataProvider->loadAttributesCollection(
                AddressMetadataInterface::ENTITY_TYPE_ADDRESS,
                EntityDefinitions::FORM_ADDRESS_TEXT
            );
            foreach ($collection->getItems() as $item) {
                $code = (string) $item->getAttributeCode();
                if (isset($userDefined[$code])) {
                    $this->shown[$code] = $userDefined[$code];
                }
            }
        }
        return $this->shown;
    }

    /**
     * Existing user-defined attributes that are switched off. An order must not show them, while a deleted attribute
     * (not in this list) is still shown from the order's own snapshot.
     *
     * @return string[]
     */
    public function getHiddenCodes(): array
    {
        return array_values(array_diff(array_keys($this->attributeProvider->getUserDefined()), array_keys($this->getShown())));
    }

    public function _resetState(): void
    {
        $this->shown = null;
    }
}
