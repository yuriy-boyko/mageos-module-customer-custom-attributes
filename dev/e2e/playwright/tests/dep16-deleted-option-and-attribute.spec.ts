import { benchCommand, expect, field, openCheckoutAsGuest, requireFixtures, test } from './helpers';

/**
 * DEP16: a parent option deleted later, and an attribute deleted later.
 *
 * The deletions are done on the store with the same code the admin uses (`fixtures.php delete-option` / `delete-attribute`, through
 * the bench command E2E_FIXTURE_CMD). Each test has its own pair of fixture attributes, so the other tests are not affected.
 * - e2e_a_osel / e2e_a_odep: odep shows when osel is "Alpha". After "Alpha" is deleted the rule can never match.
 * - e2e_a_xsel / e2e_a_xdep: xdep shows when xsel is "Alpha" and is limited to customer groups. After xdep is deleted its rule and
 *   its group restrictions are gone.
 */
test('after a parent option is deleted the rule never matches', { tag: ['@DEP16'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_osel', 'e2e_a_odep']);

    // before: the rule works
    await field(page, 'e2e_a_osel').selectOption({ label: 'Alpha' });
    await magewire.idle();
    await expect(field(page, 'e2e_a_odep')).toBeVisible();

    const output = benchCommand('E2E_FIXTURE_CMD', ['delete-option', 'e2e_a_osel', 'Alpha']);
    expect(output).toContain('deleted  option "Alpha" of e2e_a_osel');

    // after: the option is gone from the form and the dependent can no longer be shown
    await page.goto('/checkout/');
    await expect(field(page, 'e2e_a_osel')).toBeVisible();
    const options = await field(page, 'e2e_a_osel').locator('option').allInnerTexts();
    expect(options.map((text) => text.trim())).not.toContain('Alpha');
    expect(options.map((text) => text.trim())).toContain('Beta');
    await field(page, 'e2e_a_osel').selectOption({ label: 'Beta' });
    await magewire.idle();
    await expect(field(page, 'e2e_a_odep')).toBeHidden();
});

test('after an attribute is deleted its rules and group restrictions are gone', { tag: ['@DEP16'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_xsel', 'e2e_a_xdep']);
    await field(page, 'e2e_a_xsel').selectOption({ label: 'Alpha' });
    await magewire.idle();
    await expect(field(page, 'e2e_a_xdep')).toBeVisible();

    const output = benchCommand('E2E_FIXTURE_CMD', ['delete-attribute', 'e2e_a_xdep']);
    expect(output).toContain('before   e2e_a_xdep rules=1 groups=4');
    expect(output).toContain('after    e2e_a_xdep rules=0 groups=0');

    // the checkout still works: the parent is there, the deleted dependent is not, nothing breaks (the test also fails on page errors)
    await page.goto('/checkout/');
    await expect(field(page, 'e2e_a_xsel')).toBeVisible();
    await expect(page.locator('[name="e2e_a_xdep"]')).toHaveCount(0);
    await field(page, 'e2e_a_xsel').selectOption({ label: 'Alpha' });
    await magewire.idle();
    await expect(page.locator('[name="e2e_a_xdep"]')).toHaveCount(0);
});
