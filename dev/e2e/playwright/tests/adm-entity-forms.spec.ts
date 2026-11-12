import { customerEmail, customerTab, expect, openCustomerEdit, test } from './helpers';

/**
 * ADM17: an attribute that is saved through the module shows in the admin forms: the customer attributes in the customer edit
 * page (Account Information), the address attributes in the address form of the same page (Addresses > Add New Address).
 * Fixtures: e2e_c_text (customer), e2e_a_text (address).
 */
test('customer and address attributes show in the admin customer edit forms', { tag: ['@ADM17'] }, async ({ adminPage: page }) => {
    await openCustomerEdit(page, customerEmail());

    await customerTab(page, 'Account Information');
    await expect(page.locator('[name="customer[e2e_c_text]"]')).toBeVisible();
    await expect(page.locator('label', { hasText: 'E2E Customer Text' }).first()).toBeVisible();

    await customerTab(page, 'Addresses');
    await page.getByRole('button', { name: 'Add New Address' }).first().click();
    const modal = page.locator('.modal-slide._show, .modal-popup._show').first();
    await expect(modal.locator('[name="e2e_a_text"]')).toBeVisible();
    await expect(modal.locator('label', { hasText: 'E2E Address Text' }).first()).toBeVisible();
});
