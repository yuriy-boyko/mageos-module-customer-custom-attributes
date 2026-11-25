<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\AccountSaveDecider;
use MageOS\CustomerCustomAttributes\Service\AdminOnlyAccess;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use PHPUnit\Framework\TestCase;

class AccountSaveDeciderTest extends TestCase
{
    private const ON = [ValidateRulesBuilder::SAVE_TO_ACCOUNT_KEY => 1];

    private function decide(array $rules, array $typed, array $account): array
    {
        return (new AccountSaveDecider())->valuesToSave($rules, $typed, $account, new ValidateRulesBuilder(), new AdminOnlyAccess());
    }

    public function testATypedValueThatDiffersIsWritten(): void
    {
        self::assertSame(
            ['tax' => 'IT123', 'sdi' => 'ABC'],
            $this->decide(['tax' => self::ON, 'sdi' => self::ON], ['tax' => 'IT123', 'sdi' => 'ABC'], ['tax' => 'old', 'sdi' => null])
        );
    }

    public function testNothingIsWrittenForTheAttributesWithoutTheSetting(): void
    {
        self::assertSame([], $this->decide(['tax' => [], 'sdi' => [ValidateRulesBuilder::SAVE_TO_ACCOUNT_KEY => 0]], ['tax' => 'x', 'sdi' => 'y'], []));
    }

    public function testAnEmptyValueNeverErasesTheStoredOne(): void
    {
        self::assertSame([], $this->decide(['tax' => self::ON], ['tax' => ''], ['tax' => 'stored']));
        self::assertSame([], $this->decide(['tax' => self::ON], ['tax' => null], ['tax' => 'stored']));
        self::assertSame([], $this->decide(['tax' => self::ON], [], ['tax' => 'stored']), 'not typed at all (hidden by a rule)');
    }

    public function testAValueThatIsTheSameIsNotWrittenAgain(): void
    {
        self::assertSame([], $this->decide(['tax' => self::ON], ['tax' => 'same'], ['tax' => 'same']));
    }

    public function testTheValueZeroIsAValue(): void
    {
        self::assertSame(['flag' => '0'], $this->decide(['flag' => self::ON], ['flag' => '0'], ['flag' => '1']));
    }

    public function testAnAdminOnlyAttributeIsNeverWritten(): void
    {
        $rules = ['internal' => self::ON + [ValidateRulesBuilder::ADMIN_ONLY_KEY => 1]];

        self::assertSame([], $this->decide($rules, ['internal' => 'forced'], ['internal' => 'by admin']));
    }
}
