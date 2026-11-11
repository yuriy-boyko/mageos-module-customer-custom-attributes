<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model;

use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The user-defined (custom) customer attributes, as core EAV attribute models keyed by code.
 */
class CustomerAttributeProvider implements ResetAfterRequestInterface
{
    /** @var array<string, AbstractAttribute>|null */
    private ?array $attributes = null;

    public function __construct(private readonly EavConfig $eavConfig)
    {
    }

    /**
     * @return array<string, AbstractAttribute>
     */
    public function getUserDefined(): array
    {
        if ($this->attributes === null) {
            $this->attributes = [];
            foreach ($this->eavConfig->getEntityAttributes(CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER) as $code => $attribute) {
                if ($attribute->getIsUserDefined()) {
                    $this->attributes[(string) $code] = $attribute;
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
