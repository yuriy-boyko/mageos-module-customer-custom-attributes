import { benchCommand, chooseShippingAndPayment, expect, fillCheckout, openCheckoutAsGuest, placeOrder, quoteValues, test, uniqueEmail } from './helpers';

/**
 * ORD5: the order is refused at "Place Order" by the server (the product sold out while the customer filled the checkout), the stand-in for a
 * declined payment or an exception while the order is placed: no order and no order rows are left, and the values stay on the quote, (the
 * item keeps its out-of-stock flag until the cart is collected again, so placing it later is not part of this test). (A card declined by the payment provider is not driven here: that is the 3D Secure / card
 * test, which needs the provider's own page.)
 */
test.afterEach(() => {
    benchCommand('E2E_FIXTURE_CMD', ['ensure-stock']);
});

test('ORD5: an order refused when it is placed leaves no order rows and keeps the values on the quote', { tag: ['@ORD5'] }, async ({ page, magewire }) => {
    const email = uniqueEmail('ord5');
    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire, { email_address: email, e2e_a_text: 'kept after the refusal' });
    await chooseShippingAndPayment(page, magewire);
    await expect.poll(() => {
        try {
            return Object.values(quoteValues('@latest').addresses ?? {}).some((values) => values.e2e_a_text === 'kept after the refusal');
        } catch {
            return false;
        }
    }, { timeout: 20_000 }).toBe(true);

    benchCommand('E2E_FIXTURE_CMD', ['set-stock', '24-MB04', '0']);
    await placeOrder(page);
    await expect(page).toHaveURL(/\/checkout\/?$/);
    await expect(page.locator('[role="alert"]').filter({ hasText: /stock|available|quantity/i }).first()).toBeVisible({ timeout: 20_000 });
    const none = JSON.parse(benchCommand('E2E_ASSERT_CMD', ['orders-of', email]));
    expect(none, 'no order and no order rows').toEqual({ orders: 0, customer_rows: 0 });
    const quote = quoteValues('@latest');
    expect(quote.active, 'the quote is still the active cart').toBe(true);
    expect(Object.values(quote.addresses ?? {}).some((values) => values.e2e_a_text === 'kept after the refusal'), 'the values stay on the quote').toBe(true);
});
