import type { Page } from '@playwright/test';
import {
    accountValues, chooseShippingAndPayment, customerEmail, deleteSavedAddresses, dropQuotes, expect, fillCheckout, openCheckoutAsGuest, placeOrder,
    placedOrderSnapshot, requireFixtures, setAccountValues, test, useVariant, walkSteps,
} from './helpers';

/**
 * RAD8: radio buttons (e2e_a_yn, yes/no) and a checkbox group (e2e_a_chk, multiple select) of address attributes in the Hyvä Checkout:
 * hand-typed address of a customer and of a guest, the multi-step and mobile variants, and the billing form. Every group is ticked, one
 * option is unticked again, and the order carries what is ticked at the end.
 */
type Idle = { idle(): Promise<void> };
type Form = 'shipping' | 'billing';

/** The radio or checkbox of one form by its label (the first option of a group is also labelled by the group's own label). */
const option = (page: Page, form: Form, code: string, role: 'radio' | 'checkbox', label: string) =>
    page.locator(`label:has(input[data-form="${form}"][name^="${code}"])`).filter({ hasText: new RegExp(`^\\s*${label}\\s*$`) }).first().locator('input');

async function tickGroups(page: Page, magewire: Idle, form: Form, ticked: string[], radio: string): Promise<void> {
    for (const label of ['One', 'Two', 'Three']) {
        await option(page, form, 'e2e_a_chk', 'checkbox', label).setChecked(ticked.includes(label) || label === 'One');
        await magewire.idle();
    }
    // One was ticked on the way: untick it again when it should not be on the order
    await option(page, form, 'e2e_a_chk', 'checkbox', 'One').setChecked(ticked.includes('One'));
    await magewire.idle();
    await option(page, form, 'e2e_a_yn', 'radio', radio).check();
    await magewire.idle();
}

async function expectTicked(page: Page, form: Form, ticked: string[], radio: string): Promise<void> {
    for (const label of ['One', 'Two', 'Three']) {
        const box = option(page, form, 'e2e_a_chk', 'checkbox', label);
        if (ticked.includes(label)) {
            await expect(box, `${form} ${label} ticked`).toBeChecked();
        } else {
            await expect(box, `${form} ${label} not ticked`).not.toBeChecked();
        }
    }
    await expect(option(page, form, 'e2e_a_yn', 'radio', radio)).toBeChecked();
}

function expectOrder(snapshot: Awaited<ReturnType<typeof placedOrderSnapshot>>['snapshot'], area: 'shipping' | 'billing', labels: string, yesNo: string): void {
    expect(snapshot[area].e2e_a_chk, `${area} checkbox group`).toMatchObject({ value_label: labels });
    expect(snapshot[area].e2e_a_yn, `${area} radio`).toMatchObject({ value_label: yesNo });
}

async function walkAndPlace(page: Page, magewire: Idle): Promise<void> {
    await walkSteps(page, magewire as Parameters<typeof walkSteps>[1]);
    const payment = page.locator('[name="payment-method-option"][value="checkmo"]');
    if ((await payment.count()) > 0 && (await payment.first().isVisible()) && !(await payment.first().isChecked())) {
        await payment.first().check();
        await magewire.idle();
    }
    await placeOrder(page);
}

test.describe('one-page checkout', () => {
    useVariant('onepage');

    test.afterEach(() => {
        deleteSavedAddresses(customerEmail());
    });

    test('RAD8: a customer types a new address: the groups follow, the order and the saved address have them', { tag: ['@RAD8'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
        dropQuotes(customerEmail());
        deleteSavedAddresses(customerEmail());
        setAccountValues(customerEmail(), { e2e_c_text: 'account text' });
        await openCheckoutAsGuest(page);
        await requireFixtures(page, ['e2e_a_chk', 'e2e_a_yn']);
        await fillCheckout(page, magewire);
        await tickGroups(page, magewire, 'shipping', ['Two', 'Three'], 'Yes');
        await expectTicked(page, 'shipping', ['Two', 'Three'], 'Yes');
        await chooseShippingAndPayment(page, magewire);
        await placeOrder(page);
        const { snapshot } = await placedOrderSnapshot(page);
        expectOrder(snapshot, 'shipping', 'Two, Three', 'Yes');
        expectOrder(snapshot, 'billing', 'Two, Three', 'Yes');
        const saved = Object.values(accountValues(customerEmail()).addresses);
        expect(saved).toHaveLength(1);
        expect(saved[0].e2e_a_chk.split(',')).toHaveLength(2);
        expect(saved[0].e2e_a_yn).toBe('1');
    });

    test('RAD8: a guest unticks every option: no row for the group on the order', { tag: ['@RAD8'] }, async ({ page, magewire }) => {
        await openCheckoutAsGuest(page);
        await requireFixtures(page, ['e2e_a_chk', 'e2e_a_yn']);
        await fillCheckout(page, magewire);
        await tickGroups(page, magewire, 'shipping', [], 'No');
        await chooseShippingAndPayment(page, magewire);
        await placeOrder(page);
        const { snapshot } = await placedOrderSnapshot(page);
        expect(snapshot.shipping.e2e_a_chk, 'nothing ticked, no row').toBeUndefined();
        expect(snapshot.shipping.e2e_a_yn).toMatchObject({ value_label: 'No' });
    });

    test('RAD8: the billing form has its own groups', { tag: ['@RAD8'] }, async ({ page, magewire }) => {
        await openCheckoutAsGuest(page);
        await requireFixtures(page, ['e2e_a_chk', 'e2e_a_yn']);
        await fillCheckout(page, magewire);
        await tickGroups(page, magewire, 'shipping', ['One'], 'Yes');
        await page.locator('#billing-as-shipping').uncheck();
        await magewire.idle();
        const billing = (name: string) => page.locator(`[data-form="billing"][name="${name}"]`).first();
        for (const [name, value] of Object.entries({ firstname: 'Bill', lastname: 'Payer', 'street[0]': '2 Billing Street', postcode: '75002', city: 'Houston', telephone: '5551239999', e2e_a_req: 'billing required' })) {
            await billing(name).fill(value);
            await billing(name).press('Tab');
            await magewire.idle();
        }
        const region = page.locator('select[data-form="billing"][name="region"]').first();
        if (await region.count()) {
            await region.selectOption({ label: 'Texas' });
            await magewire.idle();
        }
        await tickGroups(page, magewire, 'billing', ['Two', 'Three'], 'No');
        // the groups of the two forms did not follow each other
        await expectTicked(page, 'shipping', ['One'], 'Yes');
        await expectTicked(page, 'billing', ['Two', 'Three'], 'No');
        await chooseShippingAndPayment(page, magewire);
        await placeOrder(page);
        const { snapshot } = await placedOrderSnapshot(page);
        expectOrder(snapshot, 'shipping', 'One', 'Yes');
        expectOrder(snapshot, 'billing', 'Two, Three', 'No');
    });
});

for (const variant of ['default', 'mobile'] as const) {
    test.describe(`${variant} checkout`, () => {
        useVariant(variant);
        test(`RAD8: a guest ticks the groups in the ${variant} checkout`, { tag: ['@RAD8'] }, async ({ page, magewire }) => {
            await openCheckoutAsGuest(page);
            await requireFixtures(page, ['e2e_a_chk', 'e2e_a_yn']);
            await fillCheckout(page, magewire);
            await tickGroups(page, magewire, 'shipping', ['Two'], 'Yes');
            await walkAndPlace(page, magewire);
            const { snapshot } = await placedOrderSnapshot(page);
            expectOrder(snapshot, 'shipping', 'Two', 'Yes');
            expectOrder(snapshot, 'billing', 'Two', 'Yes');
        });
    });
}
