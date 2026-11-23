<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\OptionKeyGuard;
use PHPUnit\Framework\TestCase;

class OptionKeyGuardTest extends TestCase
{
    public function testOwnIdsAndNewKeysAreAllowed(): void
    {
        $options = ['value' => ['12' => ['A'], 'option_0' => ['New']], 'order' => ['12' => 1, 'option_0' => 2], 'delete' => ['12' => '']];
        self::assertSame([], OptionKeyGuard::foreignIds($options, [12, 13]));
    }

    public function testForeignIdsAreListedOnce(): void
    {
        $options = ['value' => ['12' => ['A'], '99' => ['Other']], 'delete' => ['99' => '1', '100' => '1']];
        self::assertSame(['99', '100'], OptionKeyGuard::foreignIds($options, ['12']));
    }

    public function testANewAttributeHasNoIds(): void
    {
        self::assertSame(['5'], OptionKeyGuard::foreignIds(['value' => ['5' => ['A'], 'option_1' => ['B']]], []));
        self::assertSame([], OptionKeyGuard::foreignIds([], []));
    }
}
