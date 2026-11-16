import type { Page } from '@playwright/test';
import {
    chooseShippingAndPayment, expect, field, fillCheckout, ITA_PRODUCT, openCheckoutAsGuest, placeOrder, placedOrderSnapshot, requireFixtures,
    test, useVariant, walkSteps,
} from './helpers';

/**
 * DEP11: the dependency rule of an address attribute in the other situations: a guest, the multi-step and the mobile variant, and the
 * second store. e2e_a_dep is shown when e2e_a_sel (radio buttons) is "North"; the order has the parent and the visible dependent only.
 * (The billing form has its own test below, the one-page checkout with a customer is DEP10.)
 */
type Idle = { idle(): Promise<void> };
const ITALY = { postcode: '00100', city: 'Roma', telephone: '0612345678' };

const parent = (page: Page, label: string) => page.locator('.field-e2e_a_sel').getByRole('radio', { name: new RegExp(`(^|\\s)${label}$`) });

/** Changes the parent several times, ends on North with a dependent value, and checks what the form shows each time. */
async function changeParent(page: Page, magewire: Idle, finalValue: string): Promise<void> {
    await expect(field(page, 'e2e_a_dep'), 'hidden until the parent is chosen').toBeHidden();
    for (const label of ['North', 'South', 'North', 'South']) {
        await parent(page, label).check();
        await magewire.idle();
        if (label === 'North') {
            await expect(field(page, 'e2e_a_dep')).toBeVisible();
            await expect(field(page, 'e2e_a_dep'), 'a value typed before a hide is gone').toHaveValue('');
            await field(page, 'e2e_a_dep').fill('discarded');
            await field(page, 'e2e_a_dep').press('Tab');
            await magewire.idle();
        } else {
            await expect(field(page, 'e2e_a_dep')).toBeHidden();
        }
    }
    await parent(page, 'North').check();
    await magewire.idle();
    await field(page, 'e2e_a_dep').fill(finalValue);
    await field(page, 'e2e_a_dep').press('Tab');
    await magewire.idle();
}

/** Walks to the last step of a step variant; on the payment step (the last one of the multi-step checkout) the method is still to choose. */
async function walkAndPlace(page: Page, magewire: Idle): Promise<void> {
    await walkSteps(page, magewire as Parameters<typeof walkSteps>[1]);
    const payment = page.locator('[name="payment-method-option"][value="checkmo"]');
    if ((await payment.count()) > 0 && (await payment.first().isVisible()) && !(await payment.first().isChecked())) {
        await payment.first().check();
        await magewire.idle();
    }
    await placeOrder(page);
}

function expectOrder(snapshot: Awaited<ReturnType<typeof placedOrderSnapshot>>['snapshot'], finalValue: string): void {
    for (const address of [snapshot.shipping, snapshot.billing]) {
        expect(address.e2e_a_sel).toMatchObject({ value_label: 'North' });
        expect(address.e2e_a_dep).toMatchObject({ value: finalValue });
    }
}

test.describe('multi-step checkout', () => {
    useVariant('default');
    test('DEP11: a guest changes the parent, the order has the parent and the dependent', { tag: ['@DEP11'] }, async ({ page, magewire }) => {
        await openCheckoutAsGuest(page);
        await requireFixtures(page, ['e2e_a_sel', 'e2e_a_dep']);
        await fillCheckout(page, magewire);
        await changeParent(page, magewire, 'multi-step value');
        await walkAndPlace(page, magewire);
        const { snapshot } = await placedOrderSnapshot(page);
        expectOrder(snapshot, 'multi-step value');
    });
});

test.describe('mobile checkout', () => {
    useVariant('mobile');
    test('DEP11: a guest changes the parent, the order has the parent and the dependent', { tag: ['@DEP11'] }, async ({ page, magewire }) => {
        await openCheckoutAsGuest(page);
        await requireFixtures(page, ['e2e_a_sel', 'e2e_a_dep']);
        await fillCheckout(page, magewire);
        await changeParent(page, magewire, 'mobile value');
        await walkAndPlace(page, magewire);
        const { snapshot } = await placedOrderSnapshot(page);
        expectOrder(snapshot, 'mobile value');
    });
});

test.describe('one-page checkout', () => {
    useVariant('onepage');

    test('DEP11: a guest changes the parent, the order has the parent and the dependent', { tag: ['@DEP11'] }, async ({ page, magewire }) => {
        await openCheckoutAsGuest(page);
        await requireFixtures(page, ['e2e_a_sel', 'e2e_a_dep']);
        await fillCheckout(page, magewire);
        await changeParent(page, magewire, 'guest value');
        await chooseShippingAndPayment(page, magewire);
        await placeOrder(page);
        const { snapshot } = await placedOrderSnapshot(page);
        expectOrder(snapshot, 'guest value');
    });

    test('DEP11: the same in the Italy store', { tag: ['@DEP11'] }, async ({ page, magewire }) => {
        await openCheckoutAsGuest(page, ITA_PRODUCT);
        await requireFixtures(page, ['e2e_a_sel', 'e2e_a_dep']);
        await fillCheckout(page, magewire, ITALY, 'Roma');
        await changeParent(page, magewire, 'italian value');
        await chooseShippingAndPayment(page, magewire);
        await placeOrder(page);
        const { incrementId, snapshot } = await placedOrderSnapshot(page);
        expect(incrementId.startsWith('2'), 'an order of the Italy store').toBe(true);
        expectOrder(snapshot, 'italian value');
    });

    test('DEP11: the billing form has its own parent and dependent, apart from the shipping form', { tag: ['@DEP11'] }, async ({ page, magewire }) => {
        await openCheckoutAsGuest(page);
        await requireFixtures(page, ['e2e_a_sel', 'e2e_a_dep']);
        await fillCheckout(page, magewire);
        // shipping: South, no dependent
        await parent(page, 'South').check();
        await magewire.idle();
        await expect(page.locator('input[name="e2e_a_dep"][data-form="shipping"]')).toBeHidden();

        // billing: its own form with the same fields
        await page.locator('#billing-as-shipping').uncheck();
        await magewire.idle();
        const billing = (name: string) => page.locator(`[data-form="billing"][name="${name}"]`).first();
        await expect(billing('firstname')).toBeVisible();
        const typeBilling = async (name: string, value: string) => {
            await billing(name).fill(value);
            await billing(name).press('Tab');
            await magewire.idle();
        };
        await typeBilling('firstname', 'Bill');
        await typeBilling('lastname', 'Payer');
        await typeBilling('street[0]', '2 Billing Street');
        await typeBilling('postcode', '75002');
        await typeBilling('city', 'Houston');
        await typeBilling('telephone', '5551239999');
        await typeBilling('e2e_a_req', 'billing required');
        const region = page.locator('select[data-form="billing"][name="region"]').first();
        if (await region.count()) {
            await region.selectOption({ label: 'Texas' });
            await magewire.idle();
        }
        const billingRadio = (label: string) => page.locator('label', { hasText: label }).filter({ has: page.locator('input[data-form="billing"][name="e2e_a_sel"]') }).first();
        const dependent = page.locator('input[name="e2e_a_dep"][data-form="billing"]');
        await expect(dependent).toBeHidden();
        await billingRadio('North').click();
        await magewire.idle();
        await expect(dependent).toBeVisible();
        await billingRadio('South').click();
        await magewire.idle();
        await expect(dependent).toBeHidden();
        await billingRadio('North').click();
        await magewire.idle();
        await dependent.fill('billing value');
        await dependent.press('Tab');
        await magewire.idle();
        // the shipping form did not follow
        await expect(page.locator('input[name="e2e_a_dep"][data-form="shipping"]')).toBeHidden();

        await chooseShippingAndPayment(page, magewire);
        await placeOrder(page);
        const { snapshot } = await placedOrderSnapshot(page);
        expect(snapshot.billing.e2e_a_sel).toMatchObject({ value_label: 'North' });
        expect(snapshot.billing.e2e_a_dep).toMatchObject({ value: 'billing value' });
        expect(snapshot.shipping.e2e_a_sel).toMatchObject({ value_label: 'South' });
        expect(snapshot.shipping.e2e_a_dep, 'no dependent on the shipping address').toBeUndefined();
    });
});
