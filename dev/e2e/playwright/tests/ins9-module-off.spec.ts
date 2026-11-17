import { expect, openAdminOrder, openCheckoutAsGuest, placeOrder, chooseShippingAndPayment, fillCheckout, test, uniqueEmail } from './helpers';

/**
 * INS9 (module disabled): with the module and its companion disabled the storefront, the checkout, the order and the admin still work.
 * It does nothing in a normal run: dev/e2e/check-modules.sh disables the modules, sets E2E_MODULE_OFF=1 and runs this file. No bench command
 * is used (they need the module).
 */
test('INS9: with the module off the checkout places an order and the admin shows it', { tag: ['@INS9'] }, async ({ page, adminPage, magewire }) => {
    test.skip(!process.env.E2E_MODULE_OFF, 'E2E_MODULE_OFF is not set (this runs from check-modules.sh)');

    const home = await page.goto('/');
    expect(home?.status(), 'storefront home').toBeLessThan(400);

    await openCheckoutAsGuest(page);
    expect(await page.locator('.mageos-cca-field, [name^="e2e_"]').count(), 'the checkout has no custom fields').toBe(0);
    await fillCheckout(page, magewire, { email_address: uniqueEmail('ins9') });
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    await expect(page).toHaveURL(/checkout\/onepage\/success/, { timeout: 30_000 });
    const number = (await page.locator('body').innerText()).match(/\b(\d{9,10})\b/)?.[1];
    expect(number, 'order number on the success page').toBeTruthy();

    // the admin order view of the new order and the order create page
    await openAdminOrder(adminPage, number as string);
    await expect(adminPage.locator('.order-billing-address').first()).toBeVisible();
    expect(await adminPage.locator('.message-error').count()).toBe(0);
    await adminPage.goto(adminPage.url().replace(/sales\/order\/view.*/, 'sales/order/'));
    await expect(adminPage.locator('tr.data-row', { hasText: number as string }).first()).toBeVisible();
});
