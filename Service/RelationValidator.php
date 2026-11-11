<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * Checks a dependency relation before it is saved: a name, at least one rule, known attributes, a valid option of a
 * select-like parent, no rule on itself, no duplicates, and no circle together with the rules of the other relations.
 *
 * Returns message templates with arguments (translated by the adapter).
 *
 * Pure PHP, no Magento imports.
 */
class RelationValidator
{
    public const MSG_NAME = 'Please enter a name for the relation.';
    public const MSG_ENTITY = 'Please select what the relation is for.';
    public const MSG_NO_RULES = 'Please add at least one rule.';
    public const MSG_PARENT = 'Rule %1: the parent attribute is not valid. Only dropdown, multiple select and yes/no attributes can be parents.';
    public const MSG_OPTION = 'Rule %1: the option is not an option of the parent attribute.';
    public const MSG_DEPENDENT = 'Rule %1: the dependent attribute is not valid.';
    public const MSG_SELF = 'Rule %1: an attribute cannot depend on itself.';
    public const MSG_DUPLICATE = 'Rule %1 is a duplicate of an earlier rule.';
    public const MSG_CIRCLE = 'The rules form a circle: an attribute would depend on itself through other attributes (%1).';

    /**
     * @param string $entity "customer" or "address" ('' when not chosen)
     * @param list<array{parent: string, option: string, dependent: string}> $rules the rows of this relation
     * @param array<string, string[]> $parentOptions allowed option values by code of the attributes that may be parents
     * @param string[] $dependentCodes codes of the attributes that may be dependents
     * @param list<array{parent: string, option: string, dependent: string}> $otherRules rules of the other relations of the entity
     * @return list<array{message: string, args: list<string>}>
     */
    public function validate(
        string $name,
        string $entity,
        array $rules,
        array $parentOptions,
        array $dependentCodes,
        array $otherRules = []
    ): array {
        $errors = [];
        if (trim($name) === '') {
            $errors[] = ['message' => self::MSG_NAME, 'args' => []];
        }
        if (!in_array($entity, ['customer', 'address'], true)) {
            $errors[] = ['message' => self::MSG_ENTITY, 'args' => []];
        }
        if ($rules === []) {
            $errors[] = ['message' => self::MSG_NO_RULES, 'args' => []];
            return $errors;
        }

        $seen = [];
        foreach (array_values($rules) as $index => $rule) {
            $number = (string) ($index + 1);
            $valid = true;
            if (!isset($parentOptions[$rule['parent']])) {
                $errors[] = ['message' => self::MSG_PARENT, 'args' => [$number]];
                $valid = false;
            } elseif (!in_array($rule['option'], $parentOptions[$rule['parent']], true)) {
                $errors[] = ['message' => self::MSG_OPTION, 'args' => [$number]];
                $valid = false;
            }
            if (!in_array($rule['dependent'], $dependentCodes, true)) {
                $errors[] = ['message' => self::MSG_DEPENDENT, 'args' => [$number]];
                $valid = false;
            } elseif ($rule['dependent'] === $rule['parent']) {
                $errors[] = ['message' => self::MSG_SELF, 'args' => [$number]];
                $valid = false;
            }
            if ($valid) {
                $key = $rule['parent'] . "\0" . $rule['option'] . "\0" . $rule['dependent'];
                if (isset($seen[$key])) {
                    $errors[] = ['message' => self::MSG_DUPLICATE, 'args' => [$number]];
                }
                $seen[$key] = true;
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        $circle = $this->findCircle(array_merge($otherRules, $rules));
        if ($circle !== null) {
            $errors[] = ['message' => self::MSG_CIRCLE, 'args' => [implode(' → ', $circle)]];
        }
        return $errors;
    }

    /**
     * @param list<array{parent: string, option: string, dependent: string}> $rules
     * @return string[]|null the codes of a circle (first code repeated at the end), null when there is none
     */
    private function findCircle(array $rules): ?array
    {
        $edges = [];
        foreach ($rules as $rule) {
            $edges[$rule['parent']][$rule['dependent']] = true;
        }

        $state = []; // 1 = on the current path, 2 = done
        $path = [];
        $visit = function (string $code) use (&$visit, &$state, &$path, $edges): ?array {
            $state[$code] = 1;
            $path[] = $code;
            foreach (array_keys($edges[$code] ?? []) as $next) {
                if (($state[$next] ?? 0) === 1) {
                    $start = array_search($next, $path, true);
                    return array_merge(array_slice($path, (int) $start), [$next]);
                }
                if (!isset($state[$next]) && ($found = $visit($next)) !== null) {
                    return $found;
                }
            }
            array_pop($path);
            $state[$code] = 2;
            return null;
        };

        foreach (array_keys($edges) as $code) {
            if (!isset($state[$code]) && ($found = $visit($code)) !== null) {
                return $found;
            }
        }
        return null;
    }
}
