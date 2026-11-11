<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model;

use MageOS\CustomerCustomAttributes\Model\Dependency\VisibilityService;
use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use Magento\Customer\Api\Data\AddressInterface;

/**
 * Which required address attributes a saved customer address lacks. An attribute that has no value but became required
 * after the address was saved (or that a dependency rule or customer group does not hide) would block the order, because
 * core validates the saved address when the order is placed.
 */
class SavedAddressRequirements
{
    public function __construct(
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly AddressValueValidator $validator,
        private readonly VisibilityService $visibility,
        private readonly ValueNormalizer $normalizer
    ) {
    }

    /**
     * @return list<array{code: string, label: string}> the missing attributes, in attribute order
     */
    public function getMissing(AddressInterface $address, ?int $groupId, int|string|null $storeId = null): array
    {
        $attributes = $this->attributeProvider->getUserDefined();
        $checkoutCodes = $this->validator->getCheckoutCodes();
        $values = [];
        foreach ($attributes as $code => $attribute) {
            $values[$code] = $this->normalizer->normalizeForInput(
                (string) $attribute->getFrontendInput(),
                $address->getCustomAttribute($code)?->getValue()
            );
        }
        $visible = $this->visibility->getVisibleCodes('address', array_keys($values), $values, $groupId);

        $missing = [];
        foreach ($attributes as $code => $attribute) {
            if (in_array($code, $checkoutCodes, true)
                && in_array($code, $visible, true)
                && $attribute->getIsRequired()
                && ($values[$code] === null || $values[$code] === '')
            ) {
                $missing[] = ['code' => $code, 'label' => (string) $attribute->getStoreLabel($storeId)];
            }
        }
        return $missing;
    }
}
