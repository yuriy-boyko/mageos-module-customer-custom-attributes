import type { Page } from '@playwright/test';
import {
    chooseVisibleMethods, expect, field, fillCheckout, openCheckoutAsGuest, pickRadio, placeOrder, placedOrderSnapshot, requireFixtures, test, useVariant, variantTag,
} from './helpers';

/**
 * CCO19, CCO20: a rule whose parent (e2e_c_xsel, above the shipping methods) is on the first step and whose dependent (e2e_c_xdep, below
 * the payment methods) is on the payment step, in the multi-step variant (two steps) and the mobile variant (three steps). The server renders
 * the dependent with the state the parent has; the script must not hide it because the parent is not on the page.
 *
 * CCO21: the parent of an address rule is toggled quickly, without waiting between the clicks: the form ends in the state of the last click.
 */
type Idle = { idle(): Promise<void> };

/** Chooses Check / Money order when it is shown and not chosen yet. */
async function choosePayment(page: Page, magewire: Idle): Promise<void> {
    const payment = page.locator('[name="payment-method-option"][value="checkmo"]');
    if ((await payment.count()) > 0 && (await payment.first().isVisible()) && !(await payment.first().isChecked())) {
        await payment.first().check();
        await magewire.idle();
    }
}

/** One step forward ("Proceed to ..."). */
async function advance(page: Page, magewire: Idle): Promise<void> {
    await chooseVisibleMethods(page, magewire as Parameters<typeof chooseVisibleMethods>[1]);
    await page.getByRole('button', { name: /^proceed to /i }).filter({ visible: true }).first().click();
    await magewire.idle();
    await page.waitForTimeout(300);
}

/** Opens the checkout as a guest, chooses the parent (when given) on the first step and goes to the payment step. */
async function toPayment(page: Page, magewire: Idle, parent: 'Left' | 'Right' | null): Promise<void> {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_text']);
    await fillCheckout(page, magewire as Parameters<typeof fillCheckout>[1]);
    if (parent !== null) {
        await pickRadio(page, 'e2e_c_xsel', parent);
        await magewire.idle();
    }
    await advance(page, magewire);
    expect(new URL(page.url()).pathname, 'the payment step').toMatch(/step\/payment/);
    await expect(field(page, 'e2e_c_xsel'), 'the parent is not on the payment step').toHaveCount(0);
}

/** From the payment step to the place-order button: the mobile variant has one more step (the summary). */
async function toPlaceOrder(page: Page, magewire: Idle, expectedSteps: number): Promise<void> {
    if (expectedSteps === 3) {
        await advance(page, magewire);
    }
    await choosePayment(page, magewire);
}

function suite(variant: 'default' | 'mobile', expectedSteps: number): void {
    test.describe(`${variant} checkout`, variantTag(variant), () => {
        useVariant(variant);

        test(`CCO19/CCO20: parent Left on the first step: the dependent is shown on the payment step and is on the order (${variant})`, { tag: ['@CCO19', '@CCO20', '@CCO17'] }, async ({ page, magewire }) => {
            await toPayment(page, magewire, 'Left');
            await expect(field(page, 'e2e_c_xdep'), 'shown although the parent is not on this step').toBeVisible();
            await field(page, 'e2e_c_xdep').fill('cross step value');
            await field(page, 'e2e_c_xdep').press('Tab');
            await magewire.idle();
            await toPlaceOrder(page, magewire, expectedSteps);
            await placeOrder(page);
            const { snapshot } = await placedOrderSnapshot(page);
            expect(snapshot.customer.e2e_c_xsel).toMatchObject({ value_label: 'Left' });
            expect(snapshot.customer.e2e_c_xdep).toMatchObject({ value: 'cross step value' });
        });

        test(`CCO19/CCO20: parent Right: the dependent stays hidden on the payment step and is not on the order (${variant})`, { tag: ['@CCO19', '@CCO20'] }, async ({ page, magewire }) => {
            await toPayment(page, magewire, 'Right');
            await expect(field(page, 'e2e_c_xdep')).toBeHidden();
            await toPlaceOrder(page, magewire, expectedSteps);
            await placeOrder(page);
            const { snapshot } = await placedOrderSnapshot(page);
            expect(snapshot.customer.e2e_c_xsel).toMatchObject({ value_label: 'Right' });
            expect(snapshot.customer.e2e_c_xdep, 'no row for a hidden dependent').toBeUndefined();
        });

        test(`CCO19/CCO20: no parent chosen: the dependent is hidden on the payment step (${variant})`, { tag: ['@CCO19', '@CCO20'] }, async ({ page, magewire }) => {
            await toPayment(page, magewire, null);
            await expect(field(page, 'e2e_c_xdep')).toBeHidden();
        });
    });
}

suite('default', 2);
suite('mobile', 3);

test.describe('one-page checkout', () => {
    useVariant('onepage');

    test('CCO21: rapid toggling of a parent ends in the state of the last click', { tag: ['@CCO21'] }, async ({ page, magewire }) => {
        await openCheckoutAsGuest(page);
        await requireFixtures(page, ['e2e_a_sel', 'e2e_a_dep']);
        const option = (label: string) => page.locator('.field-e2e_a_sel').getByRole('radio', { name: new RegExp(`(^|\\s)${label}$`) });
        // no waiting for the server between the clicks
        for (const label of ['North', 'South', 'North', 'South', 'North', 'South', 'North']) {
            await option(label).check();
        }
        await magewire.idle();
        await expect(field(page, 'e2e_a_dep'), 'last click North: shown').toBeVisible();
        for (const label of ['South', 'North', 'South', 'North', 'South']) {
            await option(label).check();
        }
        await magewire.idle();
        await expect(field(page, 'e2e_a_dep'), 'last click South: hidden').toBeHidden();
        // the state holds after the late responses have arrived
        await page.waitForTimeout(1500);
        await magewire.idle();
        await expect(option('South')).toBeChecked();
        await expect(field(page, 'e2e_a_dep')).toBeHidden();
    });
});
