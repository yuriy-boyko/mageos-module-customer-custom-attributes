<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Api\Data;

/**
 * A custom attribute value of an order address as it was ordered: code, label, input type, raw value and the readable
 * value. Exposed through the extension attributes "custom_attribute_values" of the order address and "customer_attribute_values"
 * of the order.
 *
 * The setters exist so that an order or order address read through the Web API can be sent back as it was read (the Web API
 * builds its input objects with setters). Values set this way are not saved: the snapshot is written when the order is placed
 * and changed only by the admin Edit Order Address form.
 *
 * @api
 */
interface AddressAttributeValueInterface
{
    /**
     * @return string
     */
    public function getAttributeCode();

    /**
     * @return string|null
     */
    public function getAttributeLabel();

    /**
     * @return string|null
     */
    public function getInputType();

    /**
     * @return string|null
     */
    public function getValue();

    /**
     * @return string|null
     */
    public function getValueLabel();

    /**
     * @param string $attributeCode
     * @return $this
     */
    public function setAttributeCode($attributeCode);

    /**
     * @param string|null $attributeLabel
     * @return $this
     */
    public function setAttributeLabel($attributeLabel);

    /**
     * @param string|null $inputType
     * @return $this
     */
    public function setInputType($inputType);

    /**
     * @param string|null $value
     * @return $this
     */
    public function setValue($value);

    /**
     * @param string|null $valueLabel
     * @return $this
     */
    public function setValueLabel($valueLabel);
}
