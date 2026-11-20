import {
    accountValues, benchCommand, chooseShippingAndPayment, createAttributes, customerEmail, deleteCreated, deleteSavedAddresses, dropQuotes, expect, field,
    fillCheckout, openCheckoutAsGuest, placeOrder, placedOrderSnapshot, quoteValues, setAccountValues, setSavedAddress, snapshotOf, test, uniqueEmail,
} from './helpers';

/**
 * REG1: every custom attribute switched off: the checkout and the order behave like core.
 * REG2, REG3: a created attribute is in the providers and on the quote; a deleted one is gone and its quote rows go too.
 * CUS1, CUS2: the account information form shows the stored values and saves new ones (emoji, date).
 * BOOK3, BOOK5: a saved address in the account address form and in a later order.
 */
const email = customerEmail();

test('REG1: with every custom attribute off the checkout has no custom fields and the order has no module rows', { tag: ['@REG1', '@ORD6'] }, async ({ page, magewire }) => {
    benchCommand('E2E_FIXTURE_CMD', ['set-visible-all', '0']);
    try {
        await openCheckoutAsGuest(page);
        const found = await page.locator('.mageos-cca-field, [name^="e2e_"], [name^="cca_"]').evaluateAll((nodes) => nodes.map((node) => node.outerHTML.slice(0, 120)));
        expect(found, 'no custom fields in the checkout').toEqual([]);
        await fillCheckout(page, magewire, { email_address: uniqueEmail('reg1') });
        await chooseShippingAndPlaceOrder(page, magewire);
        await expect(page.locator('.message-error')).toHaveCount(0);
        const { incrementId, snapshot } = await placedOrderSnapshot(page);
        expect(snapshot).toEqual({ customer: {}, billing: {}, shipping: {} });
        expect(snapshotOf(incrementId).billing).toEqual({});
    } finally {
        benchCommand('E2E_FIXTURE_CMD', ['set-visible-all', '1']);
    }
});

async function chooseShippingAndPlaceOrder(page: Parameters<typeof chooseShippingAndPayment>[0], magewire: Parameters<typeof chooseShippingAndPayment>[1]): Promise<void> {
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
}

test.describe('REG2 and REG3', () => {
    test.afterEach(() => {
        deleteCreated('e2e_a_reg3');
    });

    test('REG2/REG3: a created attribute is in the providers and on the quote; deleted, it is gone and its quote rows cascade away', { tag: ['@REG2', '@REG3', '@SNAP1'] }, async ({ page, magewire }) => {
        const providers = () => JSON.parse(benchCommand('E2E_ASSERT_CMD', ['providers'])) as { address: string[]; eav_user_defined: { customer_address: string[] } };
        createAttributes('address', ['e2e_a_reg3'], ['customer_address_edit', 'mageos_hyva_checkout']);
        expect(providers().address, 'in the provider').toContain('e2e_a_reg3');
        expect(providers().eav_user_defined.customer_address).toContain('e2e_a_reg3');

        await openCheckoutAsGuest(page);
        await fillCheckout(page, magewire, { email_address: uniqueEmail('reg3'), e2e_a_reg3: 'on the quote' });
        await expect.poll(() => {
            try {
                return Object.values(quoteValues('@latest').addresses ?? {}).some((values) => values.e2e_a_reg3 === 'on the quote');
            } catch {
                return false;
            }
        }, { timeout: 20_000 }).toBe(true);
        const quoteId = quoteValues('@latest').quote_id;
        const before = JSON.parse(benchCommand('E2E_ASSERT_CMD', ['quote-rows', String(quoteId)]));

        deleteCreated('e2e_a_reg3');
        expect(providers().address, 'gone from the provider').not.toContain('e2e_a_reg3');
        const after = JSON.parse(benchCommand('E2E_ASSERT_CMD', ['quote-rows', `${quoteId}:${before.address_ids.join(',')}`]));
        expect(after.address_values, 'the quote rows of the attribute are gone, the others stay').toBe(before.address_values - 2);
        expect(Object.values(quoteValues('@latest').addresses ?? {}).every((values) => values.e2e_a_reg3 === undefined)).toBe(true);
    });
});

test('CUS1/CUS2: the account information form shows the stored values and saves emoji and a date', { tag: ['@quick', '@CUS1', '@CUS2'] }, async ({ customerPage: page }) => {
    setAccountValues(email, { e2e_c_areq: 'account required', e2e_c_text: 'stored text', e2e_c_sel: 'Silver', e2e_c_multi: 'Opt 1|Opt 3' });
    await page.goto('/customer/account/edit/');
    await expect(page.locator('[name="e2e_c_text"]')).toHaveValue('stored text');
    await expect(page.locator('[name="e2e_c_areq"]')).toHaveValue('account required');
    await expect(page.locator('.field.e2e_c_sel').getByRole('radio', { name: /Silver$/ })).toBeChecked();
    await expect(page.locator('.field.e2e_c_multi').getByRole('checkbox', { name: /Opt 3$/ })).toBeChecked();

    const text = 'Emoji 😀 é 日本語 "quoted" <b>x</b>';
    await page.locator('[name="e2e_c_text"]').fill(text);
    const date = page.locator('[name="e2e_c_date"]');
    await expect(date, 'the date field is in the form').toHaveCount(1);
    await date.fill('2026-05-06');
    await page.locator('form[action*="editPost"] button[type="submit"]').click();
    await expect(page.getByText(/You saved the account information/i).first()).toBeVisible();
    const stored = accountValues(email).customer_attributes;
    expect(stored.e2e_c_text).toBe(text);
    expect(stored.e2e_c_date, 'a date with a time of day').toMatch(/^2026-05-06 00:00:00$/);

    await page.goto('/customer/account/edit/');
    await expect(page.locator('[name="e2e_c_text"]')).toHaveValue(text);
    setAccountValues(email, { e2e_c_sel: '', e2e_c_multi: '', e2e_c_date: '' });
});

test.describe('saved address', () => {
    test.beforeEach(() => {
        dropQuotes(email);
        deleteSavedAddresses(email);
        // the group fields of CUS1 (Silver makes two more customer fields required)
        setAccountValues(email, { e2e_c_text: 'account text', e2e_c_req: 'account required', e2e_c_sel: '', e2e_c_multi: '' });
    });
    test.afterEach(() => {
        deleteSavedAddresses(email);
    });

    test('BOOK5: the account address form shows the saved values and saves changes', { tag: ['@quick', '@BOOK5'] }, async ({ customerPage: page }) => {
        setSavedAddress(email, { e2e_a_req: 'saved required', e2e_a_text: 'saved text', e2e_a_sel: 'North', e2e_a_multi: 'Red|Blue', e2e_a_area: 'one' });
        const [id] = Object.keys(accountValues(email).addresses);
        await page.goto(`/customer/address/edit/id/${id}/`);
        await expect(page.locator('[name="e2e_a_text"]')).toHaveValue('saved text');
        await expect(page.locator('[name="e2e_a_area"]')).toHaveValue('one');
        await page.locator('[name="e2e_a_text"]').fill('changed text é');
        await page.getByRole('button', { name: /save address/i }).click();
        await expect(page).toHaveURL(/customer\/address(\/index)?\/?$/);
        const saved = accountValues(email).addresses[id];
        expect(saved.e2e_a_text).toBe('changed text é');
        expect(saved.e2e_a_area, 'untouched values stay').toBe('one');
    });

    test('BOOK3: a later order with the saved address has its values on the quote and the order', { tag: ['@quick', '@BOOK3'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
        setSavedAddress(email, { e2e_a_req: 'saved required', e2e_a_text: 'saved text', e2e_a_sel: 'South', e2e_a_multi: 'Green' });
        await openCheckoutAsGuest(page);
        await magewire.idle();
        const typed = field(page, 'e2e_c_req');
        if ((await typed.count()) > 0 && (await typed.inputValue()) === '') {
            await typed.fill('required customer value');
            await typed.press('Tab');
            await magewire.idle();
        }
        await chooseShippingAndPlaceOrder(page, magewire);
        const { snapshot } = await placedOrderSnapshot(page);
        for (const address of [snapshot.shipping, snapshot.billing]) {
            expect(address.e2e_a_text).toMatchObject({ value: 'saved text' });
            expect(address.e2e_a_sel).toMatchObject({ value_label: 'South' });
            expect(address.e2e_a_multi).toMatchObject({ value_label: 'Green' });
        }
        const quote = Object.values(quoteValues(email).addresses ?? {});
        expect(quote.some((values) => values.e2e_a_text === 'saved text'), 'on the quote').toBe(true);
    });
});
