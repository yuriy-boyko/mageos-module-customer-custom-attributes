import { chooseShippingAndPayment, expect, fillCheckout, openAdminOrder, openCheckoutAsGuest, orderViewAddresses, placeOrder, placedOrderSnapshot, test } from './helpers';

/**
 * CHK9: quotes, markup, emoji, accents, Japanese and a long text typed in the checkout are stored exactly and shown escaped.
 */
const ODD = `Quote " and ' <script>window.__cca_xss = 1</script> <b>bold</b> & é ü ñ 日本語 😀`;
const LONG = `${'0123456789 abcdé 日本 '.repeat(14)}end`.slice(0, 250);

test('CHK9: odd characters are stored exactly and shown escaped on the order', { tag: ['@CHK9'] }, async ({ page, adminPage, magewire }) => {
    expect(LONG.length).toBe(250);
    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire, { e2e_a_text: ODD, e2e_a_area: LONG });
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { incrementId, snapshot } = await placedOrderSnapshot(page);
    expect(await page.evaluate(() => (window as unknown as { __cca_xss?: number }).__cca_xss), 'no script ran on the success page').toBeUndefined();

    // stored exactly, in both addresses
    for (const address of [snapshot.shipping, snapshot.billing]) {
        expect(address.e2e_a_text).toMatchObject({ value: ODD });
        expect(address.e2e_a_area).toMatchObject({ value: LONG });
    }

    // the admin order view shows the text, escaped: no script runs, no element is made from it
    await openAdminOrder(adminPage, incrementId);
    const { billing } = await orderViewAddresses(adminPage);
    expect(billing).toContain('<script>window.__cca_xss = 1</script>');
    expect(billing).toContain('日本語 😀');
    expect(await adminPage.evaluate(() => (window as unknown as { __cca_xss?: number }).__cca_xss)).toBeUndefined();
    await expect(adminPage.locator('.order-billing-address script, .order-billing-address b')).toHaveCount(0);
});
