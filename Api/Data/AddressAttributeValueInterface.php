<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Api\Data;

/**
 * A custom attribute value of an order address as it was ordered: code, label, input type, raw value and the readable
 * value. Exposed through the extension attribute "custom_attribute_values" of the order address.
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
}
