import { chooseShippingAndPayment, expect, fillCheckout, openCheckoutAsGuest, placeOrder, placedOrderSnapshot, test, uniqueEmail } from './helpers';

/**
 * INS12 (companion disabled): the main module is on, its Hyvä Checkout companion off, so the checkout renders no customer attribute
 * sections. The fixture e2e_c_req is required and ticked for the checkout: it cannot be filled in, so it must not block the order.
 * It does nothing in a normal run: dev/e2e/check-modules.sh disables the companion, sets E2E_COMPANION_OFF=1 and runs this file.
 */
test('INS12: with the companion off a required checkout customer attribute does not block the order', { tag: ['@INS12'] }, async ({ page, magewire }) => {
    test.skip(!process.env.E2E_COMPANION_OFF, 'E2E_COMPANION_OFF is not set (this runs from check-modules.sh)');
    await openCheckoutAsGuest(page);
    expect(await page.locator('[data-mageos-cca-customer]').count(), 'no customer attribute sections').toBe(0);
    await fillCheckout(page, magewire, { email_address: uniqueEmail('ins12') });
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.customer.e2e_c_req, 'no value').toBeUndefined();
});
