<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\RelationValidator;
use PHPUnit\Framework\TestCase;

class RelationValidatorTest extends TestCase
{
    private RelationValidator $validator;

    /** @var array<string, string[]> */
    private array $parents = ['type' => ['1', '2'], 'tags' => ['5', '6']];

    /** @var string[] */
    private array $dependents = ['type', 'tags', 'vat', 'note'];

    protected function setUp(): void
    {
        $this->validator = new RelationValidator();
    }

    private function rule(string $parent, string $option, string $dependent): array
    {
        return ['parent' => $parent, 'option' => $option, 'dependent' => $dependent];
    }

    /**
     * @return string[]
     */
    private function messages(string $name, string $entity, array $rules, array $others = []): array
    {
        return array_column($this->validator->validate($name, $entity, $rules, $this->parents, $this->dependents, $others), 'message');
    }

    public function testValidRelationHasNoErrors(): void
    {
        self::assertSame([], $this->messages('Business', 'address', [$this->rule('type', '2', 'vat'), $this->rule('type', '2', 'note')]));
    }

    public function testNameEntityAndRulesAreRequired(): void
    {
        self::assertSame(
            [RelationValidator::MSG_NAME, RelationValidator::MSG_ENTITY, RelationValidator::MSG_NO_RULES],
            $this->messages(' ', '', [])
        );
    }

    public function testRuleErrorsNameTheRule(): void
    {
        $errors = $this->validator->validate(
            'R',
            'customer',
            [$this->rule('vat', '1', 'note'), $this->rule('type', '9', 'note'), $this->rule('type', '1', 'ghost'), $this->rule('type', '1', 'type')],
            $this->parents,
            $this->dependents
        );
        self::assertSame(
            [RelationValidator::MSG_PARENT, RelationValidator::MSG_OPTION, RelationValidator::MSG_DEPENDENT, RelationValidator::MSG_SELF],
            array_column($errors, 'message')
        );
        self::assertSame(['1'], $errors[0]['args']);
        self::assertSame(['4'], $errors[3]['args']);
    }

    public function testDuplicateRuleIsRejected(): void
    {
        self::assertSame(
            [RelationValidator::MSG_DUPLICATE],
            $this->messages('R', 'address', [$this->rule('type', '1', 'vat'), $this->rule('type', '1', 'vat')])
        );
    }

    public function testCircleWithinTheRelationIsRejected(): void
    {
        $errors = $this->validator->validate(
            'R',
            'address',
            [$this->rule('type', '1', 'tags'), $this->rule('tags', '5', 'type')],
            $this->parents,
            $this->dependents
        );
        self::assertSame([RelationValidator::MSG_CIRCLE], array_column($errors, 'message'));
        self::assertSame(['type → tags → type'], $errors[0]['args']);
    }

    public function testCircleThroughAnotherRelationIsRejected(): void
    {
        $others = [$this->rule('vat', '1', 'type')];
        $parents = $this->parents + ['vat' => ['1']];
        $errors = $this->validator->validate('R', 'address', [$this->rule('type', '1', 'vat')], $parents, $this->dependents, $others);
        self::assertSame([RelationValidator::MSG_CIRCLE], array_column($errors, 'message'));
    }

    public function testChainWithoutACircleIsAccepted(): void
    {
        $others = [$this->rule('type', '1', 'tags')];
        self::assertSame([], $this->messages('R', 'address', [$this->rule('tags', '5', 'vat')], $others));
    }
}
