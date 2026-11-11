<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Model\Dependency;

use MageOS\CustomerCustomAttributes\Service\VisibilityResolver;

/**
 * Which attributes of a form are visible for the given values and customer group (dependency rules and group restrictions).
 */
class VisibilityService
{
    public function __construct(
        private readonly RuleProvider $ruleProvider,
        private readonly VisibilityResolver $resolver
    ) {
    }

    /**
     * @param string[] $codes attribute codes of the form
     * @param array<string, string|null> $values normalised values by attribute code
     * @return string[] visible codes
     */
    public function getVisibleCodes(string $entity, array $codes, array $values, ?int $groupId): array
    {
        return $this->resolver->resolve(
            $codes,
            $values,
            $this->ruleProvider->getRules($entity),
            $this->ruleProvider->getGroupRestrictions($entity),
            $groupId
        );
    }

    /**
     * The values with the ones of attributes that are not visible set to null.
     *
     * @param array<string, string|null> $values
     * @return array<string, string|null>
     */
    public function dropHidden(string $entity, array $values, ?int $groupId): array
    {
        return $this->resolver->dropHidden($values, $this->getVisibleCodes($entity, array_keys($values), $values, $groupId));
    }
}
