<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Finds rules that cannot work in a storefront form: the dependent attribute is shown in a form but the parent attribute is not,
 * so the parent never has a value there and the dependent attribute stays hidden. Pure PHP, no Magento imports.
 */
class RelationFormWarnings
{
    /**
     * @param list<array{parent: string, option: string, dependent: string}> $rules
     * @param array<string, string[]> $formsByCode form codes each attribute is shown in, by attribute code
     * @param array<string, string> $formLabels the forms to check (code => label); other forms are ignored
     * @param array<string, string> $attributeLabels display label by attribute code (the code is used when missing)
     * @return list<array{message: string, args: list<string>}>
     */
    public function find(array $rules, array $formsByCode, array $formLabels, array $attributeLabels): array
    {
        $warnings = [];
        $seen = [];
        foreach ($rules as $index => $rule) {
            $parent = $rule['parent'];
            $dependent = $rule['dependent'];
            foreach ($formLabels as $form => $label) {
                $key = $parent . '|' . $dependent . '|' . $form;
                if (isset($seen[$key])
                    || !in_array($form, $formsByCode[$dependent] ?? [], true)
                    || in_array($form, $formsByCode[$parent] ?? [], true)
                ) {
                    continue;
                }
                $seen[$key] = true;
                $warnings[] = [
                    'message' => 'Rule %1: "%2" is shown in "%3", but its parent "%4" is not, so "%2" stays hidden there.',
                    'args' => [
                        (string) ($index + 1),
                        $attributeLabels[$dependent] ?? $dependent,
                        $label,
                        $attributeLabels[$parent] ?? $parent,
                    ],
                ];
            }
        }
        return $warnings;
    }
}
