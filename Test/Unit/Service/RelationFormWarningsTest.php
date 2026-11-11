<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\RelationFormWarnings;
use PHPUnit\Framework\TestCase;

class RelationFormWarningsTest extends TestCase
{
    private const FORMS = ['account' => 'Account', 'checkout' => 'Checkout'];
    private const RULES = [['parent' => 'p', 'option' => '1', 'dependent' => 'd']];

    private RelationFormWarnings $warnings;

    protected function setUp(): void
    {
        $this->warnings = new RelationFormWarnings();
    }

    public function testNoWarningWhenBothAreInTheSameForms(): void
    {
        self::assertSame([], $this->warnings->find(self::RULES, ['p' => ['account', 'checkout'], 'd' => ['account', 'checkout']], self::FORMS, []));
    }

    public function testWarningWhenTheParentIsMissingFromAFormOfTheDependent(): void
    {
        $result = $this->warnings->find(self::RULES, ['p' => ['account'], 'd' => ['account', 'checkout']], self::FORMS, ['p' => 'Parent', 'd' => 'Child']);
        self::assertCount(1, $result);
        self::assertSame(['1', 'Child', 'Checkout', 'Parent'], $result[0]['args']);
    }

    public function testParentOnlyFormsAndUncheckedFormsAreIgnored(): void
    {
        self::assertSame([], $this->warnings->find(self::RULES, ['p' => ['account', 'checkout'], 'd' => ['account']], self::FORMS, []));
        self::assertSame([], $this->warnings->find(self::RULES, ['p' => [], 'd' => ['admin_form']], self::FORMS, []));
    }

    public function testCodesAreUsedWithoutLabelsAndEachPairIsReportedOncePerForm(): void
    {
        $rules = [
            ['parent' => 'p', 'option' => '1', 'dependent' => 'd'],
            ['parent' => 'p', 'option' => '2', 'dependent' => 'd'],
        ];
        $result = $this->warnings->find($rules, ['d' => ['checkout']], self::FORMS, []);
        self::assertCount(1, $result);
        self::assertSame(['1', 'd', 'Checkout', 'p'], $result[0]['args']);
    }
}
