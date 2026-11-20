import type { Page } from '@playwright/test';
import {
    benchCommand, chooseShippingAndPayment, customerEmail, deleteSavedAddresses, dropQuotes, expect, field, fillCheckout, openCheckoutAsGuest, placeOrder,
    placedOrderSnapshot, quoteValues, requireFixtures, setAccountValues, setSavedAddress, test, uniqueEmail, useVariant, variantTag, walkSteps,
} from './helpers';

/**
 * CHK1, CHK2, CHK3: a guest types the address attributes; billing the same as shipping, or a different billing address with its own values.
 * ORD1: the order rows of such an order: label, input type, value and value label for billing and shipping.
 * CHK10: reload at each step of the multi-step checkout. CHK11: a logged-in customer types a new address. CHK12: choosing a saved address
 * without values shows no stale values of the one chosen before.
 */
type Idle = { idle(): Promise<void> };
const email = customerEmail();

type Row = { attribute_code: string; attribute_label: string; input_type: string; value: string; value_label: string };
const orderRows = (incrementId: string): { billing: Row[]; shipping: Row[] } => JSON.parse(benchCommand('E2E_ASSERT_CMD', ['order', incrementId])).addresses;

const quoteAddress = (kind: 'shipping' | 'billing'): Record<string, string> => {
    try {
        return (quoteValues('@latest').addresses ?? {})[kind] ?? {};
    } catch {
        return {};
    }
};

async function typeSeven(page: Page, magewire: Idle, form: 'shipping' | 'billing', values: { text: string; area: string; sel: string; multi: string[]; date: string; yn: string }): Promise<void> {
    const scope = (code: string) => page.locator(`[data-form="${form}"][name="${code}"], [data-form="${form}"][name="${code}[]"]`).first();
    const fill = async (code: string, value: string) => {
        await scope(code).fill(value);
        await scope(code).press('Tab');
        await magewire.idle();
    };
    await fill('e2e_a_text', values.text);
    await fill('e2e_a_area', values.area);
    await fill('e2e_a_date', values.date);
    await page.locator(`label:has(input[data-form="${form}"][name^="e2e_a_sel"])`).filter({ hasText: new RegExp(`^\\s*${values.sel}\\s*$`) }).first().locator('input').check();
    await magewire.idle();
    await scope('e2e_a_multi').selectOption(values.multi.map((label) => ({ label })));
    await magewire.idle();
    await scope('e2e_a_bool').selectOption({ label: values.yn });
    await magewire.idle();
}

const FIRST = { text: 'shipping text', area: 'shipping area', sel: 'North', multi: ['Red', 'Blue'], date: '2026-08-08', yn: 'Yes' };
const SECOND = { text: 'billing text', area: 'billing area', sel: 'South', multi: ['Green'], date: '2026-09-09', yn: 'No' };

test('CHK1, CHK2, ORD1: a guest fills the seven address fields; billing the same as shipping has the same values; the order rows are complete', { tag: ['@quick', '@CHK1', '@CHK2', '@ORD1'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_text', 'e2e_a_area', 'e2e_a_date', 'e2e_a_sel', 'e2e_a_multi', 'e2e_a_bool']);
    await fillCheckout(page, magewire, { email_address: uniqueEmail('chk1') });
    await typeSeven(page, magewire, 'shipping', FIRST);

    await expect.poll(() => quoteAddress('shipping').e2e_a_text, { timeout: 20_000 }).toBe('shipping text');
    const shipping = quoteAddress('shipping');
    expect(shipping).toMatchObject({ e2e_a_req: 'required value', e2e_a_text: 'shipping text', e2e_a_area: 'shipping area', e2e_a_bool: '1' });
    expect(shipping.e2e_a_date).toContain('2026-08-08');
    expect(shipping.e2e_a_multi.split(',')).toHaveLength(2);
    // CHK2: billing the same as shipping
    await expect.poll(() => quoteAddress('billing').e2e_a_text, { timeout: 20_000 }).toBe('shipping text');
    expect(quoteAddress('billing')).toMatchObject({ e2e_a_req: 'required value', e2e_a_area: 'shipping area', e2e_a_bool: '1' });

    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { incrementId } = await placedOrderSnapshot(page);
    // ORD1: one row per filled attribute for billing and shipping, with label, input type, value and value label
    for (const kind of ['billing', 'shipping'] as const) {
        const rows = Object.fromEntries(orderRows(incrementId)[kind].map((row) => [row.attribute_code, row]));
        expect(rows.e2e_a_text, `${kind} text`).toMatchObject({ attribute_label: 'E2E Address Text', input_type: 'text', value: 'shipping text', value_label: 'shipping text' });
        expect(rows.e2e_a_area, `${kind} area`).toMatchObject({ attribute_label: 'E2E Address Area', input_type: 'textarea', value: 'shipping area' });
        expect(rows.e2e_a_sel, `${kind} select`).toMatchObject({ attribute_label: 'E2E Address Select', input_type: 'select', value_label: 'North' });
        expect(rows.e2e_a_multi, `${kind} multiselect`).toMatchObject({ attribute_label: 'E2E Address Multi', input_type: 'multiselect', value_label: 'Red, Blue' });
        expect(rows.e2e_a_bool, `${kind} yes/no`).toMatchObject({ attribute_label: 'E2E Address Yes No', input_type: 'boolean', value: '1', value_label: 'Yes' });
        expect(rows.e2e_a_date, `${kind} date`).toMatchObject({ attribute_label: 'E2E Address Date', input_type: 'date' });
        expect(rows.e2e_a_date.value).toContain('2026-08-08');
        expect(rows.e2e_a_req, `${kind} required`).toMatchObject({ value: 'required value' });
    }
});

test('CHK3: a different billing address keeps its own values; each address has its own order rows', { tag: ['@CHK3'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire, { email_address: uniqueEmail('chk3') });
    await typeSeven(page, magewire, 'shipping', FIRST);
    await page.locator('#billing-as-shipping').uncheck();
    await magewire.idle();
    // the billing address of its own: required values and the attributes with other values
    const billing = page.getByRole('group', { name: 'Billing Address' });
    for (const [name, value] of Object.entries({ firstname: 'Bill', lastname: 'Payer', 'street[0]': '9 Billing Road', postcode: '75002', city: 'Plano', telephone: '5559990000', e2e_a_req: 'billing required' })) {
        const input = billing.locator(`[name="${name}"]`).first();
        if ((await input.count()) > 0) {
            await input.fill(value);
            await input.press('Tab');
            await magewire.idle();
        }
    }
    const region = billing.locator('select[name="region"]').first();
    if ((await region.count()) > 0) {
        await region.selectOption({ label: 'Texas' });
        await magewire.idle();
    }
    await typeSeven(page, magewire, 'billing', SECOND);

    await expect.poll(() => quoteAddress('billing').e2e_a_text, { timeout: 20_000 }).toBe('billing text');
    expect(quoteAddress('shipping').e2e_a_text, 'shipping keeps its own').toBe('shipping text');
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.shipping.e2e_a_text).toMatchObject({ value: 'shipping text' });
    expect(snapshot.shipping.e2e_a_sel).toMatchObject({ value_label: 'North' });
    expect(snapshot.billing.e2e_a_text).toMatchObject({ value: 'billing text' });
    expect(snapshot.billing.e2e_a_sel).toMatchObject({ value_label: 'South' });
    expect(snapshot.billing.e2e_a_bool).toMatchObject({ value_label: 'No' });
    expect(snapshot.billing.e2e_a_multi).toMatchObject({ value_label: 'Green' });
});

test.describe('multi-step checkout', variantTag('default'), () => {
    useVariant('default');

    test('CHK10: reload at each step: the values are still there', { tag: ['@CHK10'] }, async ({ page, magewire }) => {
        await openCheckoutAsGuest(page);
        await requireFixtures(page, ['e2e_c_text', 'e2e_a_text']);
        await fillCheckout(page, magewire, { email_address: uniqueEmail('chk10'), e2e_a_text: 'kept over reloads' });
        await field(page, 'e2e_c_text').fill('customer kept');
        await field(page, 'e2e_c_text').press('Tab');
        await magewire.idle();
        await expect.poll(() => quoteAddress('shipping').e2e_a_text, { timeout: 20_000 }).toBe('kept over reloads');

        // payment step: a value typed there, then reload
        const steps = await walkSteps(page, magewire);
        expect(steps.map((step) => step.step)).toEqual(['first', 'payment']);
        await field(page, 'e2e_c_area').fill('typed on payment');
        await field(page, 'e2e_c_area').press('Tab');
        await magewire.idle();
        await page.reload();
        await magewire.idle();
        await expect(field(page, 'e2e_c_area')).toHaveValue('typed on payment');

        // back to the first step and reload there: everything typed before is still in the form
        await page.goBack();
        await magewire.idle();
        await page.reload();
        await magewire.idle();
        await expect(field(page, 'e2e_a_text')).toHaveValue('kept over reloads');
        await expect(field(page, 'e2e_c_text')).toHaveValue('customer kept');
    });
});

test.describe('logged-in customer', () => {
    test.beforeEach(() => {
        dropQuotes(email);
        deleteSavedAddresses(email);
        setAccountValues(email, { e2e_c_text: 'account text', e2e_c_req: 'account required', e2e_c_sel: '', e2e_c_multi: '' });
    });
    test.afterEach(() => {
        deleteSavedAddresses(email);
    });

    test('CHK11: a new address typed by a logged-in customer has the same quote and order rows as a guest address', { tag: ['@CHK11'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
        await openCheckoutAsGuest(page);
        await fillCheckout(page, magewire);
        await typeSeven(page, magewire, 'shipping', FIRST);
        await expect.poll(() => quoteAddress('shipping').e2e_a_text, { timeout: 20_000 }).toBe('shipping text');
        await chooseShippingAndPayment(page, magewire);
        await placeOrder(page);
        const { snapshot } = await placedOrderSnapshot(page);
        expect(snapshot.shipping.e2e_a_text).toMatchObject({ value: 'shipping text' });
        expect(snapshot.shipping.e2e_a_multi).toMatchObject({ value_label: 'Red, Blue' });
        expect(snapshot.billing.e2e_a_sel).toMatchObject({ value_label: 'North' });
    });

    test('CHK12: choosing a saved address without values shows no stale values of the address chosen before', { tag: ['@CHK12'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
        setSavedAddress(email, { e2e_a_req: 'with values', e2e_a_text: 'only on the first', e2e_a_sel: 'North' });
        setSavedAddress(email, { e2e_a_req: 'without values' }, true);
        await openCheckoutAsGuest(page);
        await magewire.idle();
        await expect.poll(() => quoteAddress('shipping').e2e_a_text, { timeout: 20_000 }).toBe('only on the first');

        const radios = page.locator('input[type="radio"][name="address_shipping"]');
        if ((await radios.count()) > 0) {
            await page.locator('label', { hasText: '2 Other Street' }).filter({ has: page.locator('input[name="address_shipping"]') }).first().click();
        } else {
            const select = page.locator('select#address-list').first();
            await select.selectOption(await select.locator('option', { hasText: '2 Other Street' }).first().getAttribute('value') as string);
        }
        await magewire.idle();
        await expect.poll(() => quoteAddress('shipping').e2e_a_req, { timeout: 20_000 }).toBe('without values');
        expect(quoteAddress('shipping').e2e_a_text, 'no stale value on the shipping address').toBeUndefined();
        expect(quoteAddress('shipping').e2e_a_sel).toBeUndefined();
    });
});
