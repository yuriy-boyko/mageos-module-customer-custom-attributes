import { chooseShippingAndPayment, expect, field, fillCheckout, openCheckoutAsGuest, placeOrder, placedOrderSnapshot, quoteValues, requireFixtures, test, uniqueEmail, useVariant, variantTag, walkSteps } from './helpers';

/**
 * CHK4, CHK6, CHK7: values typed in the Hyvä Checkout address form: a changed value, "0" and "No", and a multiple select with none, one and
 * several options. Each test is a guest order; the quote is read after every change and the order at the end.
 *
 * CHK20: the multi-step and mobile checkouts have one combined order each (at the end of the file).
 */
type Idle = { idle(): Promise<void> };

/** The value of a code on the address rows of the latest quote (the guest's cart), or undefined when there is none yet. */
const shippingValue = (_email: string, code: string): string | undefined => {
    try {
        return Object.values(quoteValues('@latest').addresses ?? {}).map((values) => values[code]).find((value) => value !== undefined);
    } catch {
        return undefined;
    }
};

async function prepare(page: Parameters<typeof openCheckoutAsGuest>[0], magewire: Idle, email: string): Promise<void> {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_text', 'e2e_a_bool', 'e2e_a_multi']);
    await fillCheckout(page, magewire as Parameters<typeof fillCheckout>[1], { email_address: email });
}

async function finish(page: Parameters<typeof openCheckoutAsGuest>[0], magewire: Idle) {
    const next = page.getByRole('button', { name: /^proceed to /i }).filter({ visible: true });
    if ((await next.count()) === 0) {
        await chooseShippingAndPayment(page, magewire as Parameters<typeof chooseShippingAndPayment>[1]);
    } else {
        // a step variant: the shipping method on the first step, then the steps; the payment method on the payment step
        const shipping = page.locator('[name="shipping-method-option"]').first();
        if ((await shipping.count()) > 0 && !(await shipping.isChecked())) {
            await shipping.check();
            await magewire.idle();
        }
        await walkSteps(page, magewire as Parameters<typeof walkSteps>[1]);
        const payment = page.locator('[name="payment-method-option"][value="checkmo"]');
        if ((await payment.count()) > 0 && (await payment.first().isVisible()) && !(await payment.first().isChecked())) {
            await payment.first().check();
            await magewire.idle();
        }
    }
    await placeOrder(page);
    return (await placedOrderSnapshot(page)).snapshot;
}

test.describe('one-page checkout', () => {
    useVariant('onepage');

    test(`CHK4: a changed value is saved again: the quote and the order have the last value only`, { tag: ['@quick', '@CHK4'] }, async ({ page, magewire }) => {
        const email = uniqueEmail('chk4');
        await prepare(page, magewire, email);
        for (const value of ['first value', 'second value', 'third value']) {
            await field(page, 'e2e_a_text').fill(value);
            await field(page, 'e2e_a_text').press('Tab');
            await magewire.idle();
        }
        await expect.poll(() => shippingValue(email, 'e2e_a_text'), { timeout: 20_000 }).toBe('third value');
        const rows = Object.values(quoteValues('@latest').addresses ?? {}).filter((values) => values.e2e_a_text !== undefined);
        expect(rows.every((values) => values.e2e_a_text === 'third value')).toBe(true);
        const snapshot = await finish(page, magewire);
        expect(snapshot.shipping.e2e_a_text).toMatchObject({ value: 'third value' });
        expect(snapshot.billing.e2e_a_text).toMatchObject({ value: 'third value' });
    });

    test(`CHK6: the text "0" and the yes/no "No" are values, not empty`, { tag: ['@CHK6'] }, async ({ page, magewire }) => {
        const email = uniqueEmail('chk6');
        await prepare(page, magewire, email);
        await field(page, 'e2e_a_text').fill('0');
        await field(page, 'e2e_a_text').press('Tab');
        await magewire.idle();
        await field(page, 'e2e_a_bool').selectOption({ label: 'No' });
        await magewire.idle();
        const snapshot = await finish(page, magewire);
        for (const address of [snapshot.shipping, snapshot.billing]) {
            expect(address.e2e_a_text, 'text 0').toMatchObject({ value: '0' });
            expect(address.e2e_a_bool, 'yes/no No').toMatchObject({ value: '0', value_label: 'No' });
        }
    });

    test(`CHK7: a multiple select with no, one and several options`, { tag: ['@CHK7'] }, async ({ page, magewire }) => {
        const email = uniqueEmail('chk7');
        await prepare(page, magewire, email);
        const choose = async (labels: string[]) => {
            await field(page, 'e2e_a_multi').selectOption(labels.map((label) => ({ label })));
            await magewire.idle();
        };
        // one option, then several, then back to one: the stored value follows
        await choose(['Red']);
        await expect.poll(() => shippingValue(email, 'e2e_a_multi'), { timeout: 20_000 }).toMatch(/^\d+$/);
        const one = shippingValue(email, 'e2e_a_multi') as string;
        await choose(['Red', 'Blue']);
        await expect.poll(() => shippingValue(email, 'e2e_a_multi'), { timeout: 20_000 }).toMatch(/^\d+,\d+$/);
        expect((shippingValue(email, 'e2e_a_multi') as string).split(',')).toContain(one);
        await choose(['Green', 'Red', 'Blue']);
        await expect.poll(() => (shippingValue(email, 'e2e_a_multi') ?? '').split(',').length, { timeout: 20_000 }).toBe(3);
        const snapshot = await finish(page, magewire);
        expect(snapshot.shipping.e2e_a_multi.value.split(',')).toHaveLength(3);
        expect(snapshot.shipping.e2e_a_multi.value_label.split(',').map((label) => label.trim()).sort()).toEqual(['Blue', 'Green', 'Red']);
        expect(snapshot.billing.e2e_a_multi.value).toBe(snapshot.shipping.e2e_a_multi.value);
    });

    test(`CHK7: a multiple select left without an option gives no row on the order`, { tag: ['@CHK7'] }, async ({ page, magewire }) => {
        const email = uniqueEmail('chk7none');
        await prepare(page, magewire, email);
        const snapshot = await finish(page, magewire);
        expect(snapshot.shipping.e2e_a_multi, 'no row').toBeUndefined();
        expect(snapshot.billing.e2e_a_multi).toBeUndefined();
    });
});

/**
 * The multi-step and mobile checkouts (CHK20, run only with run-all.sh --variants): the cases above in one order per variant. The text is
 * changed three times and ends as "0", yes/no is "No" and the multiple select goes from one to three options; the case "no option, no
 * row" is only run on the one-page checkout.
 */
for (const variant of ['default', 'mobile'] as const) {
    test.describe(`${variant} checkout`, variantTag(variant), () => {
        useVariant(variant);

        test(`CHK4, CHK6, CHK7 in one order (${variant})`, { tag: ['@CHK4~', '@CHK6~', '@CHK7~', '@CHK20~'] }, async ({ page, magewire }) => {
            const email = uniqueEmail(`chk-${variant}`);
            await prepare(page, magewire, email);
            for (const value of ['first value', 'second value', '0']) {
                await field(page, 'e2e_a_text').fill(value);
                await field(page, 'e2e_a_text').press('Tab');
                await magewire.idle();
            }
            await expect.poll(() => shippingValue(email, 'e2e_a_text'), { timeout: 20_000 }).toBe('0');
            const rows = Object.values(quoteValues('@latest').addresses ?? {}).filter((values) => values.e2e_a_text !== undefined);
            expect(rows.every((values) => values.e2e_a_text === '0'), 'only the last value on the quote').toBe(true);
            await field(page, 'e2e_a_bool').selectOption({ label: 'No' });
            await magewire.idle();
            await field(page, 'e2e_a_multi').selectOption([{ label: 'Red' }]);
            await magewire.idle();
            await expect.poll(() => shippingValue(email, 'e2e_a_multi'), { timeout: 20_000 }).toMatch(/^\d+$/);
            await field(page, 'e2e_a_multi').selectOption([{ label: 'Green' }, { label: 'Red' }, { label: 'Blue' }]);
            await magewire.idle();
            await expect.poll(() => (shippingValue(email, 'e2e_a_multi') ?? '').split(',').length, { timeout: 20_000 }).toBe(3);
            const snapshot = await finish(page, magewire);
            for (const address of [snapshot.shipping, snapshot.billing]) {
                expect(address.e2e_a_text, 'text 0, the last value').toMatchObject({ value: '0' });
                expect(address.e2e_a_bool, 'yes/no No').toMatchObject({ value: '0', value_label: 'No' });
                expect(address.e2e_a_multi.value_label.split(',').map((label) => label.trim()).sort()).toEqual(['Blue', 'Green', 'Red']);
            }
        });
    });
}
