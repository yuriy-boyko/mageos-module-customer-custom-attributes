<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\Quote\Address;

use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Quote\Model\Quote\Address\CustomAttributeListInterface;

/**
 * Core returns an empty list here, which makes the quote address drop every custom attribute that is submitted to it
 * (DataObjectHelper::populateWithArray only keeps known codes). Returning the customer address custom attributes makes
 * the quote address keep them, which is the first link of the persistence chain.
 */
class CustomAttributeList implements CustomAttributeListInterface, ResetAfterRequestInterface
{
    /** @var array<string, \Magento\Customer\Api\Data\AttributeMetadataInterface>|null */
    private ?array $attributes = null;

    public function __construct(private readonly AddressMetadataInterface $addressMetadata)
    {
    }

    /**
     * @inheritDoc
     */
    public function getAttributes()
    {
        if ($this->attributes === null) {
            $this->attributes = [];
            $metadata = $this->addressMetadata->getCustomAttributesMetadata(AddressInterface::class);
            if (is_array($metadata)) {
                foreach ($metadata as $attribute) {
                    $this->attributes[$attribute->getAttributeCode()] = $attribute;
                }
            }
        }
        return $this->attributes;
    }

    public function _resetState(): void
    {
        $this->attributes = null;
    }
}
