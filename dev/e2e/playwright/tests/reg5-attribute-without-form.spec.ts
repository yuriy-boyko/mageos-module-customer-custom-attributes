import { expect, openCheckoutAsGuest, requireFixtures, test } from './helpers';

/**
 * REG5: an attribute that is assigned to no storefront form is not shown (the REST suite proves that its submitted values still work).
 * Fixture: the address attribute e2e_a_noform has no form but the admin ones.
 */
test('an attribute in no storefront form is not shown in the checkout or the account address form', { tag: ['@REG5'] }, async ({ page, customerPage }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_text']);
    await expect(page.locator('[name="e2e_a_noform"]')).toHaveCount(0);

    await customerPage.goto('/customer/address/new/');
    await expect(customerPage.locator('[name="e2e_a_text"]')).toBeVisible();
    await expect(customerPage.locator('[name="e2e_a_noform"]')).toHaveCount(0);
});
