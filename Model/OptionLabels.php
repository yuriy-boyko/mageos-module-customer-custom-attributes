<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model;

use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;

/**
 * Option value => label map of a select-like attribute (empty for other input types), in the labels of the current store or of
 * the store given (the store view of an order: in the admin the current store is the admin store, whose labels are the admin values).
 */
class OptionLabels
{
    /**
     * @return array<string, string>
     */
    public function forAttribute(AbstractAttribute $attribute, int|string|null $storeId = null): array
    {
        if (!$attribute->usesSource()) {
            return [];
        }
        // the option source reads the attribute's store_id (getStoreId())
        $previous = $attribute->getData('store_id');
        if ($storeId !== null) {
            $attribute->setData('store_id', (int) $storeId);
        }
        try {
            $labels = [];
            foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                if (is_array($option['value'] ?? null)) {
                    continue;
                }
                $labels[(string) $option['value']] = (string) $option['label'];
            }
            return $labels;
        } finally {
            if ($storeId !== null) {
                $attribute->setData('store_id', $previous);
            }
        }
    }
}
