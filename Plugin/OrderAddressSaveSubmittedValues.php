<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\AddressValueValidator;
use MageOS\CustomerCustomAttributes\Model\OptionLabels;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\OrderAddressValueStorage;
use MageOS\CustomerCustomAttributes\Service\SnapshotRowBuilder;
use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\Filter\Date as DateFilter;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\OrderAddressRepositoryInterface;
use Magento\Sales\Model\Order\Address;

/**
 * Admin "Edit Order Address": the form posts the values as plain fields (the attribute codes) that core adds to the
 * order address data. Core skips the save of an address whose table columns did not change, so no save event fires;
 * this plugin on the repository's save() updates the snapshot rows of the submitted attributes instead. An empty value
 * removes the row; the label of an existing row is kept; rows of attributes that are not in the form (e.g. deleted
 * ones) stay untouched.
 */
class OrderAddressSaveSubmittedValues
{
    public function __construct(
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly SnapshotRowBuilder $rowBuilder,
        private readonly OrderAddressValueStorage $storage,
        private readonly OptionLabels $optionLabels,
        private readonly ValueNormalizer $normalizer,
        private readonly DateFilter $dateFilter,
        private readonly AddressValueValidator $validator
    ) {
    }

    /**
     * Rejects invalid submitted values before anything is saved; the admin controller shows the message on the form.
     *
     * @throws LocalizedException
     */
    public function beforeSave(OrderAddressRepositoryInterface $subject, OrderAddressInterface $entity): void
    {
        if (!$entity instanceof Address || !$entity->getId()) {
            return;
        }
        $submitted = $this->collectSubmitted($entity);
        if ($submitted === []) {
            return;
        }
        $messages = $this->validator->validate($submitted, $entity->getOrder()?->getStoreId(), array_keys($submitted));
        if ($messages !== []) {
            throw new LocalizedException(__(implode(' ', $messages)));
        }
    }

    public function afterSave(
        OrderAddressRepositoryInterface $subject,
        OrderAddressInterface $result,
        OrderAddressInterface $entity
    ): OrderAddressInterface {
        if ($entity instanceof Address && $entity->getId()) {
            $this->saveSubmittedValues($entity);
        }
        return $result;
    }

    private function saveSubmittedValues(Address $address): void
    {
        $attributes = $this->attributeProvider->getUserDefined();
        $submitted = $this->collectSubmitted($address);
        if ($submitted === []) {
            return;
        }

        $addressId = (int) $address->getId();
        $existing = [];
        foreach ($this->storage->load([$addressId])[$addressId] ?? [] as $row) {
            $existing[$row['attribute_code']] = $row;
        }

        $storeId = $address->getOrder()?->getStoreId();
        $rows = [];
        $remove = [];
        foreach ($submitted as $code => $value) {
            if ($value === null || $value === '') {
                if (isset($existing[$code])) {
                    $remove[] = $code;
                }
                continue;
            }
            $attribute = $attributes[$code];
            $rows[] = $this->rowBuilder->build(
                $code,
                $existing[$code]['attribute_label'] ?? $attribute->getStoreLabel($storeId),
                $existing[$code]['input_type'] ?? (string) $attribute->getFrontendInput(),
                $value,
                $this->optionLabels->forAttribute($attribute)
            );
            $address->unsetData($code);
        }
        foreach ($remove as $code) {
            $address->unsetData($code);
        }

        $this->storage->save($addressId, $rows);
        $this->storage->remove($addressId, $remove);
    }

    /**
     * @return array<string, string|null> normalised submitted values by attribute code
     */
    private function collectSubmitted(Address $address): array
    {
        $submitted = [];
        foreach ($this->attributeProvider->getUserDefined() as $code => $attribute) {
            if ($address->hasData($code)) {
                $submitted[$code] = $this->readValue((string) $attribute->getFrontendInput(), $address->getData($code));
            }
        }
        return $submitted;
    }

    /**
     * Core has already joined a multiselect array into a comma separated string (possibly with an empty first part from
     * the empty option), and the admin date field posts the date in the admin locale format (e.g. 8/9/27).
     */
    private function readValue(string $input, mixed $raw): ?string
    {
        if ($input === 'multiselect' && is_string($raw)) {
            $raw = array_filter(explode(ValueNormalizer::MULTI_VALUE_SEPARATOR, $raw), static fn (string $part): bool => $part !== '');
        }
        if ($input === 'date' && is_string($raw) && $raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) !== 1) {
            $raw = $this->dateFilter->filter($raw);
        }
        return $this->normalizer->normalizeForInput($input, $raw);
    }
}
