<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\VisibilityResolver;
use PHPUnit\Framework\TestCase;

class VisibilityResolverTest extends TestCase
{
    private VisibilityResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new VisibilityResolver();
    }

    private function rule(string $parent, string $option, string $dependent): array
    {
        return ['parent' => $parent, 'option' => $option, 'dependent' => $dependent];
    }

    public function testAttributesWithoutRulesAreVisible(): void
    {
        self::assertSame(['a', 'b'], $this->resolver->resolve(['a', 'b'], [], []));
    }

    public function testDependentIsHiddenUntilTheParentHasTheOption(): void
    {
        $rules = [$this->rule('type', '10', 'vat')];
        self::assertSame(['type'], $this->resolver->resolve(['type', 'vat'], [], $rules));
        self::assertSame(['type'], $this->resolver->resolve(['type', 'vat'], ['type' => '11'], $rules));
        self::assertSame(['type', 'vat'], $this->resolver->resolve(['type', 'vat'], ['type' => '10'], $rules));
    }

    public function testMultiselectParentMatchesAnySelectedOption(): void
    {
        $rules = [$this->rule('tags', '3', 'extra')];
        self::assertSame(['tags', 'extra'], $this->resolver->resolve(['tags', 'extra'], ['tags' => '1,3'], $rules));
        self::assertSame(['tags'], $this->resolver->resolve(['tags', 'extra'], ['tags' => '1,30'], $rules));
    }

    public function testAnyMatchingRuleOfADependentIsEnough(): void
    {
        $rules = [$this->rule('p', '1', 'x'), $this->rule('q', '2', 'x')];
        self::assertSame(['p', 'q', 'x'], $this->resolver->resolve(['p', 'q', 'x'], ['q' => '2'], $rules));
        self::assertSame(['p', 'q'], $this->resolver->resolve(['p', 'q', 'x'], ['p' => '9', 'q' => '9'], $rules));
    }

    public function testRulesNestAndAHiddenParentHidesItsDependents(): void
    {
        $rules = [$this->rule('a', '1', 'b'), $this->rule('b', '1', 'c')];
        $codes = ['a', 'b', 'c'];
        // b has the option c needs, but b itself is hidden because a does not have its option.
        self::assertSame(['a'], $this->resolver->resolve($codes, ['a' => '0', 'b' => '1'], $rules));
        self::assertSame(['a', 'b'], $this->resolver->resolve($codes, ['a' => '1', 'b' => '0'], $rules));
        self::assertSame(['a', 'b', 'c'], $this->resolver->resolve($codes, ['a' => '1', 'b' => '1'], $rules));
    }

    public function testCircularRulesNeverBecomeVisible(): void
    {
        $rules = [$this->rule('a', '1', 'b'), $this->rule('b', '1', 'a')];
        self::assertSame([], $this->resolver->resolve(['a', 'b'], ['a' => '1', 'b' => '1'], $rules));
    }

    public function testRulesWithAParentOutsideTheFormAreIgnored(): void
    {
        $rules = [$this->rule('other', '1', 'x')];
        self::assertSame(['x'], $this->resolver->resolve(['x'], [], $rules));
    }

    public function testGroupRestriction(): void
    {
        $restrictions = ['tax_cert' => [2, 3]];
        self::assertSame(['name'], $this->resolver->resolve(['name', 'tax_cert'], [], [], $restrictions, 1));
        self::assertSame(['name', 'tax_cert'], $this->resolver->resolve(['name', 'tax_cert'], [], [], $restrictions, 2));
        self::assertSame(['name'], $this->resolver->resolve(['name', 'tax_cert'], [], [], $restrictions, null));
        self::assertSame(['name', 'tax_cert'], $this->resolver->resolve(['name', 'tax_cert'], [], [], ['tax_cert' => []], 1));
    }

    public function testRestrictedParentHidesItsDependents(): void
    {
        $rules = [$this->rule('p', '1', 'c')];
        self::assertSame([], $this->resolver->resolve(['p', 'c'], ['p' => '1'], $rules, ['p' => [5]], 1));
    }

    public function testDropHiddenSetsTheValuesOfHiddenAttributesToNull(): void
    {
        self::assertSame(
            ['a' => 'x', 'b' => null, 'c' => null],
            $this->resolver->dropHidden(['a' => 'x', 'b' => 'y', 'c' => null], ['a'])
        );
    }

    public function testRestrictionsForGroupTellOnlyWhatAppliesToTheGroup(): void
    {
        $restrictions = ['wholesale_id' => [2, 3], 'vip' => [4], 'open' => []];
        self::assertSame(['wholesale_id' => [], 'vip' => [-1], 'open' => []], VisibilityResolver::restrictionsForGroup($restrictions, 2));
        self::assertSame(['wholesale_id' => [-1], 'vip' => [-1], 'open' => []], VisibilityResolver::restrictionsForGroup($restrictions, null));
        // the browser rule: allowed when the list is empty or contains the group
        $groupId = 2;
        foreach (VisibilityResolver::restrictionsForGroup($restrictions, $groupId) as $code => $groups) {
            self::assertSame($code !== 'vip', $groups === [] || in_array($groupId, $groups, true), $code);
        }
    }
}
