<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\Data;

use MageOS\CustomerCustomAttributes\Api\Data\AddressAttributeValueInterface;
use Magento\Framework\DataObject;

class AddressAttributeValue extends DataObject implements AddressAttributeValueInterface
{
    /**
     * @inheritDoc
     */
    public function getAttributeCode()
    {
        return (string) $this->getData('attribute_code');
    }

    /**
     * @inheritDoc
     */
    public function getAttributeLabel()
    {
        return $this->getData('attribute_label');
    }

    /**
     * @inheritDoc
     */
    public function getInputType()
    {
        return $this->getData('input_type');
    }

    /**
     * @inheritDoc
     */
    public function getValue()
    {
        return $this->getData('value');
    }

    /**
     * @inheritDoc
     */
    public function getValueLabel()
    {
        return $this->getData('value_label');
    }

    /**
     * @inheritDoc
     */
    public function setAttributeCode($attributeCode)
    {
        return $this->setData('attribute_code', $attributeCode);
    }

    /**
     * @inheritDoc
     */
    public function setAttributeLabel($attributeLabel)
    {
        return $this->setData('attribute_label', $attributeLabel);
    }

    /**
     * @inheritDoc
     */
    public function setInputType($inputType)
    {
        return $this->setData('input_type', $inputType);
    }

    /**
     * @inheritDoc
     */
    public function setValue($value)
    {
        return $this->setData('value', $value);
    }

    /**
     * @inheritDoc
     */
    public function setValueLabel($valueLabel)
    {
        return $this->setData('value_label', $valueLabel);
    }
}
