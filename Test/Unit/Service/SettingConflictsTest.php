<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Unit\Service;

use MageOS\CustomerCustomAttributes\Service\SettingConflicts;
use PHPUnit\Framework\TestCase;

class SettingConflictsTest extends TestCase
{
    private function messages(bool $adminOnly, bool $saveToAccount, bool $showInEmail, bool $saveOnOrder, array $forms): array
    {
        return (new SettingConflicts())->messages($adminOnly, $saveToAccount, $showInEmail, $saveOnOrder, $forms);
    }

    public function testNoSettingsNoConflict(): void
    {
        self::assertSame([], $this->messages(false, false, false, true, ['adminhtml_customer']));
        self::assertSame([], $this->messages(false, false, false, false, []));
    }

    public function testSavingToTheAccountNeedsTheCheckoutAndNoAdminOnly(): void
    {
        self::assertSame([], $this->messages(false, true, false, true, ['mageos_hyva_checkout']));
        self::assertCount(1, $this->messages(false, true, false, true, ['customer_account_edit']), 'not in the checkout');
        $both = $this->messages(true, true, false, true, ['mageos_hyva_checkout']);
        self::assertCount(1, $both);
        self::assertStringContainsString('cannot be saved to the customer account', $both[0]);
    }

    public function testTheEmailNeedsTheOrderSnapshotAndNoAdminOnly(): void
    {
        self::assertSame([], $this->messages(false, false, true, true, []));
        $off = $this->messages(false, false, true, false, []);
        self::assertCount(1, $off);
        self::assertStringContainsString('Save on Orders', $off[0]);
        $internal = $this->messages(true, false, true, true, []);
        self::assertCount(1, $internal);
        self::assertStringContainsString('internal value', $internal[0]);
    }

    public function testSeveralConflictsAreAllReported(): void
    {
        self::assertCount(4, $this->messages(true, true, true, false, []));
    }
}
