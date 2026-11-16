import {
    chooseShippingAndPayment, expect, field, fillCheckout, ITA_PRODUCT, openAdminOrder, openCheckoutAsGuest, orderViewAddresses, placeOrder,
    placedOrderSnapshot, quoteValues, requireFixtures, test, useVariant, walkSteps, pickRadio,
} from './helpers';

/**
 * CHK14: a date typed in the Italy store (Italian locale). CHK16: back from the payment step to the shipping step in the multi-step
 * checkout. CHK17: two browser tabs edit the same quote.
 */
const DATE = '2026-08-08';

test('CHK14: a date typed in the Italy store is stored as typed and shown correctly', { tag: ['@CHK14'] }, async ({ page, magewire, adminPage }) => {
    await openCheckoutAsGuest(page, ITA_PRODUCT);
    await requireFixtures(page, ['e2e_a_date', 'e2e_c_date']);
    await fillCheckout(page, magewire, { postcode: '00100', city: 'Roma', telephone: '0612345678', e2e_a_date: DATE, e2e_c_date: DATE }, 'Roma');
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { incrementId, snapshot } = await placedOrderSnapshot(page);
    expect(incrementId.startsWith('2'), 'an order of the Italy store').toBe(true);
    for (const [kind, rows] of [['customer', snapshot.customer], ['shipping', snapshot.shipping], ['billing', snapshot.billing]] as const) {
        const code = kind === 'customer' ? 'e2e_c_date' : 'e2e_a_date';
        expect(rows[code], `${kind} ${code}`).toMatchObject({ value_label: DATE });
        expect(rows[code].value.startsWith(DATE), `${kind} stored as ${DATE} 00:00:00`).toBe(true);
    }
    // the admin shows the same day (any day-first or month-first format of the admin locale), not the day before or after
    await openAdminOrder(adminPage, incrementId);
    const shown = (await orderViewAddresses(adminPage)).shipping + ' ' + (await adminPage.locator('.order-account-information').innerText());
    expect(shown).toMatch(/(2026-08-08|8\/8\/26|8\/8\/2026|08\/08\/2026|08\.08\.2026|8 Aug)/);
});

test.describe('multi-step checkout', () => {
    useVariant('default');

    test('CHK16: back from the payment step to the shipping step: the values reload from the quote and can be edited', { tag: ['@CHK16', '@CHK20~'] }, async ({ page, magewire }) => {
        await openCheckoutAsGuest(page);
        await requireFixtures(page, ['e2e_c_text', 'e2e_a_text']);
        await fillCheckout(page, magewire, { e2e_a_text: 'address text one' });
        await field(page, 'e2e_c_text').fill('customer text one');
        await field(page, 'e2e_c_text').press('Tab');
        await magewire.idle();

        // forward to the payment step, choose a value there
        const steps = await walkSteps(page, magewire);
        expect(steps.map((step) => step.step)).toEqual(['first', 'payment']);
        await pickRadio(page, 'e2e_c_sel', 'Gold');
        await magewire.idle();

        // back to the shipping step: everything typed before is there again
        await page.goBack();
        await magewire.idle();
        await expect(field(page, 'e2e_c_text')).toHaveValue('customer text one');
        await expect(field(page, 'e2e_a_text')).toHaveValue('address text one');

        // edit, go forward again: the edit is kept, the payment value is still there
        await field(page, 'e2e_c_text').fill('customer text two');
        await field(page, 'e2e_c_text').press('Tab');
        await magewire.idle();
        await walkSteps(page, magewire);
        await expect(page.locator('[data-code="e2e_c_sel"]').getByRole('radio', { name: /Gold$/ })).toBeChecked();
        const payment = page.locator('[name="payment-method-option"][value="checkmo"]');
        if (!(await payment.first().isChecked())) {
            await payment.first().check();
            await magewire.idle();
        }
        await placeOrder(page);
        const { snapshot } = await placedOrderSnapshot(page);
        expect(snapshot.customer.e2e_c_text).toMatchObject({ value: 'customer text two' });
        expect(snapshot.customer.e2e_c_sel).toMatchObject({ value_label: 'Gold' });
        expect(snapshot.shipping.e2e_a_text).toMatchObject({ value: 'address text one' });
    });
});

test('CHK17: two tabs edit the same quote: the last write wins, no duplicate rows', { tag: ['@CHK17'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_c_text', 'e2e_c_below']);
    await fillCheckout(page, magewire);
    // a write is typed, then waited for until the quote holds it: the next write starts only after the one before has landed
    const stored = () => quoteValues('@latest').customer_attributes;
    const type = async (tab: typeof page, code: string, value: string) => {
        await field(tab, code).fill(value);
        await field(tab, code).press('Tab');
        await expect.poll(() => stored()[code], { timeout: 20_000, message: `${code} is on the quote` }).toBe(value);
    };

    // the second tab opens the checkout of the same session (same quote) while the first one stays open
    const second = await page.context().newPage();
    await second.goto('/checkout/');
    await expect(field(second, 'e2e_c_text')).toBeVisible();

    await type(page, 'e2e_c_text', 'from tab one');
    await type(second, 'e2e_c_text', 'from tab two');
    expect(stored().e2e_c_text, 'the last write wins').toBe('from tab two');
    // the first tab keeps working on the same quote
    await type(page, 'e2e_c_below', 'below from tab one');

    const customer = quoteValues('@latest').customer_attributes;
    expect(customer.e2e_c_below).toBe('below from tab one');
    expect(['from tab one', 'from tab two'], 'a stale tab may send its own text again, never a mix or a second row').toContain(customer.e2e_c_text);
    expect(Object.keys(customer).filter((code) => code === 'e2e_c_text')).toHaveLength(1);

    // an order from the first tab carries what the quote holds, one row per attribute
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.customer.e2e_c_text).toMatchObject({ value: customer.e2e_c_text });
    expect(snapshot.customer.e2e_c_below).toMatchObject({ value: 'below from tab one' });
    await second.close();
});
