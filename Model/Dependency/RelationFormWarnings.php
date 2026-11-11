<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\Dependency;

use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use MageOS\CustomerCustomAttributes\Service\RelationFormWarnings as Finder;
use Magento\Framework\App\ResourceConnection;

/**
 * Translated warnings for the rules of a relation: the dependent attribute is shown in a storefront form where its parent is not.
 */
class RelationFormWarnings
{
    public function __construct(
        private readonly Finder $finder,
        private readonly RelationAttributes $attributes,
        private readonly EntityDefinitions $definitions,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param list<array{parent: string, option: string, dependent: string}> $rules
     * @return string[]
     */
    public function forRules(string $entity, array $rules): array
    {
        if ($rules === [] || !$this->definitions->isValidKey($entity)) {
            return [];
        }
        // Storefront forms only: the admin forms always show every attribute and the address text is not a form.
        $formLabels = [];
        foreach ($this->definitions->get($entity)['optional_forms'] as $code => $label) {
            if (strpos($code, 'adminhtml_') !== 0 && $code !== EntityDefinitions::FORM_ADDRESS_TEXT) {
                // Without the explanation in brackets, which is meant for the form selector
                $formLabels[$code] = trim((string) preg_replace('/\s*\(.*$/', '', (string) __($label)));
            }
        }

        $attributes = $this->attributes->getAttributes($entity);
        $codes = [];
        foreach ($rules as $rule) {
            $codes[$rule['parent']] = true;
            $codes[$rule['dependent']] = true;
        }
        $ids = [];
        $labels = [];
        foreach (array_keys($codes) as $code) {
            if (isset($attributes[$code])) {
                $ids[(int) $attributes[$code]->getAttributeId()] = $code;
                $labels[$code] = (string) ($attributes[$code]->getStoreLabel() ?: $code);
            }
        }
        if ($ids === [] || $formLabels === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('customer_form_attribute'), ['attribute_id', 'form_code'])
            ->where('attribute_id IN (?)', array_keys($ids))
            ->where('form_code IN (?)', array_keys($formLabels));
        $formsByCode = [];
        foreach ($connection->fetchAll($select) as $row) {
            $formsByCode[$ids[(int) $row['attribute_id']]][] = (string) $row['form_code'];
        }

        return array_map(
            static fn (array $warning): string => (string) __($warning['message'], ...$warning['args']),
            $this->finder->find($rules, $formsByCode, $formLabels, $labels)
        );
    }
}
