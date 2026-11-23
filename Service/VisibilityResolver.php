<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Works out which attributes of a form are visible, given the submitted values, the dependency rules and the customer group.
 *
 * Dependency rule: "attribute X is shown when parent attribute P has option O". An attribute that is the target of
 * rules is visible when at least one of its rules matches a parent that is itself visible (so rules nest). An attribute
 * without rules is visible. A rule whose parent is not part of the form is ignored. Rules that point at each other in a
 * circle never become visible.
 *
 * Group restriction: an attribute with a list of customer group ids is visible only for a customer in one of them, and
 * so are its dependents.
 *
 * Pure PHP, no Magento imports.
 */
class VisibilityResolver
{
    /**
     * @param string[] $codes attribute codes of the form
     * @param array<string, string|null> $values normalised values by code (multiselect: comma separated)
     * @param list<array{parent: string, option: string, dependent: string}> $rules
     * @param array<string, int[]> $groupRestrictions allowed customer group ids by attribute code; no entry = all groups
     * @return string[] visible codes, in the order of $codes
     */
    public function resolve(array $codes, array $values, array $rules, array $groupRestrictions = [], ?int $groupId = null): array
    {
        $inForm = array_flip($codes);

        // Attributes the customer group may see.
        $allowed = [];
        foreach ($codes as $code) {
            $groups = $groupRestrictions[$code] ?? [];
            $allowed[$code] = $groups === [] || ($groupId !== null && in_array($groupId, $groups, true));
        }

        $rulesByDependent = [];
        foreach ($rules as $rule) {
            if (isset($inForm[$rule['parent']], $inForm[$rule['dependent']])) {
                $rulesByDependent[$rule['dependent']][] = $rule;
            }
        }

        // Start from the attributes without rules, then add the ones whose rule matches a visible parent until nothing changes.
        $visible = [];
        foreach ($codes as $code) {
            if ($allowed[$code] && !isset($rulesByDependent[$code])) {
                $visible[$code] = true;
            }
        }
        do {
            $changed = false;
            foreach ($rulesByDependent as $dependent => $dependentRules) {
                if (isset($visible[$dependent]) || !$allowed[$dependent]) {
                    continue;
                }
                foreach ($dependentRules as $rule) {
                    if (isset($visible[$rule['parent']]) && $this->hasOption($values[$rule['parent']] ?? null, $rule['option'])) {
                        $visible[$dependent] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        return array_values(array_filter($codes, static fn (string $code): bool => isset($visible[$code])));
    }

    /**
     * The values with the ones of hidden attributes set to null.
     *
     * @param array<string, string|null> $values
     * @param string[] $visible
     * @return array<string, string|null>
     */
    public function dropHidden(array $values, array $visible): array
    {
        $visible = array_flip($visible);
        foreach ($values as $code => $value) {
            if (!isset($visible[$code])) {
                $values[$code] = null;
            }
        }
        return $values;
    }

    private function hasOption(?string $value, string $option): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        return in_array($option, explode(ValueNormalizer::MULTI_VALUE_SEPARATOR, $value), true);
    }

    /**
     * The group restrictions as the browser gets them: an empty list for an attribute the group may see, [-1] (no group) for one
     * it may not. The page so tells the script what applies to this customer without listing the other groups.
     *
     * @param array<string, int[]> $groupRestrictions code => allowed customer group ids (no entry or an empty list: all groups)
     * @return array<string, int[]>
     */
    public static function restrictionsForGroup(array $groupRestrictions, ?int $groupId): array
    {
        $result = [];
        foreach ($groupRestrictions as $code => $groups) {
            $groups = array_map('intval', (array) $groups);
            $result[$code] = $groups === [] || ($groupId !== null && in_array($groupId, $groups, true)) ? [] : [-1];
        }
        return $result;
    }
}
