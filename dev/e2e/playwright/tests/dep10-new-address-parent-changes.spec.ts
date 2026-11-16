import type { Page } from '@playwright/test';
import {
    chooseShippingAndPayment, customerEmail, deleteSavedAddresses, dropQuotes, expect, field, fillCheckout, openCheckoutAsGuest, placeOrder,
    placedOrderSnapshot, requireFixtures, setAccountValues, test,
} from './helpers';

/**
 * DEP10: a logged-in customer types a new address by hand and changes the parent of a dependency rule several times before ordering.
 *
 * Fixture: e2e_a_dep (text) is shown when e2e_a_sel (radio buttons) is "North". Every change is followed by what the form shows and by what
 * the quote holds; the order has the parent and only the dependent that is visible at the end.
 */
const parent = (page: Page, label: string) => page.locator('.field-e2e_a_sel').getByRole('radio', { name: new RegExp(`(^|\\s)${label}$`) });

test.beforeEach(() => {
    dropQuotes(customerEmail());
    deleteSavedAddresses(customerEmail());
    setAccountValues(customerEmail(), { e2e_c_text: 'account text' });
});

test.afterEach(() => {
    deleteSavedAddresses(customerEmail());
});

async function typeDependent(page: Page, magewire: { idle(): Promise<void> }, value: string): Promise<void> {
    await field(page, 'e2e_a_dep').fill(value);
    await field(page, 'e2e_a_dep').press('Tab');
    await magewire.idle();
}

async function chooseParent(page: Page, magewire: { idle(): Promise<void> }, label: string): Promise<void> {
    await parent(page, label).check();
    await magewire.idle();
}

async function prepare(page: Page, magewire: { idle(): Promise<void> }): Promise<void> {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_sel', 'e2e_a_dep']);
    await fillCheckout(page, magewire, { e2e_a_text: 'typed by hand' });
}

test('DEP10: the parent changed several times, the order has the parent and the visible dependent', { tag: ['@DEP10', '@DEP9'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    await prepare(page, magewire);
    await expect(field(page, 'e2e_a_dep'), 'hidden until the parent is chosen').toBeHidden();

    // North shows the dependent, South hides it and clears it, North again shows it empty
    await chooseParent(page, magewire, 'North');
    await expect(field(page, 'e2e_a_dep')).toBeVisible();
    await typeDependent(page, magewire, 'first value');
    await chooseParent(page, magewire, 'South');
    await expect(field(page, 'e2e_a_dep')).toBeHidden();
    await chooseParent(page, magewire, 'North');
    await expect(field(page, 'e2e_a_dep')).toBeVisible();
    await expect(field(page, 'e2e_a_dep'), 'the value typed before the hide is gone').toHaveValue('');
    await chooseParent(page, magewire, 'South');
    await chooseParent(page, magewire, 'North');
    await typeDependent(page, magewire, 'final value');


    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.shipping.e2e_a_sel).toMatchObject({ value_label: 'North' });
    expect(snapshot.shipping.e2e_a_dep).toMatchObject({ value: 'final value' });
    expect(snapshot.billing.e2e_a_sel).toMatchObject({ value_label: 'North' });
    expect(snapshot.billing.e2e_a_dep).toMatchObject({ value: 'final value' });
});

test('DEP10: the order ends with the parent on another option: the dependent typed before is not on the order', { tag: ['@DEP10', '@DEP9'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    await prepare(page, magewire);
    await chooseParent(page, magewire, 'North');
    await typeDependent(page, magewire, 'will be dropped');
    await chooseParent(page, magewire, 'South');
    await expect(field(page, 'e2e_a_dep')).toBeHidden();

    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.shipping.e2e_a_sel).toMatchObject({ value_label: 'South' });
    expect(snapshot.shipping.e2e_a_dep, 'no row for a hidden dependent').toBeUndefined();
    expect(snapshot.billing.e2e_a_dep).toBeUndefined();
});
