<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model;

use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;

/**
 * Option value => label map of a select-like attribute (empty for other input types).
 */
class OptionLabels
{
    /**
     * @return array<string, string>
     */
    public function forAttribute(AbstractAttribute $attribute): array
    {
        if (!$attribute->usesSource()) {
            return [];
        }
        $labels = [];
        foreach ($attribute->getSource()->getAllOptions(false) as $option) {
            if (is_array($option['value'] ?? null)) {
                continue;
            }
            $labels[(string) $option['value']] = (string) $option['label'];
        }
        return $labels;
    }
}
