import type { Page } from '@playwright/test';
import {
    accountValues, chooseShippingAndPayment, customerEmail, deleteSavedAddresses, dropQuotes, expect, field, openCheckoutAsGuest, placeOrder,
    placedOrderSnapshot, requireFixtures, setAccountValues, setSavedAddress, test,
} from './helpers';

/**
 * A logged-in customer with a saved address that has a checkbox group (e2e_a_chk) and radio buttons (e2e_a_yn) of address attributes:
 * the checkout shows the saved values on the address card, the order carries them, and a new address typed in the "New Address" dialog
 * starts empty and has its own values (the saved address is not changed).
 */
const SAVED = { e2e_a_chk: 'Two|Three', e2e_a_yn: '1', e2e_a_text: 'saved text', e2e_a_req: 'saved required' };

test.beforeEach(() => {
    dropQuotes(customerEmail());
    deleteSavedAddresses(customerEmail());
    setAccountValues(customerEmail(), { e2e_c_text: 'account text', e2e_c_req: 'account required' });
    setSavedAddress(customerEmail(), SAVED);
});

test.afterEach(() => {
    deleteSavedAddresses(customerEmail());
});

async function openCheckout(page: Page, magewire: { idle(): Promise<void> }): Promise<void> {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_chk', 'e2e_a_yn']);
    await magewire.idle();
}

/** The required customer attribute of the checkout (a customer section, not part of the address). */
async function fillCustomerRequired(page: Page, magewire: { idle(): Promise<void> }): Promise<void> {
    await field(page, 'e2e_c_req').fill('required customer value');
    await field(page, 'e2e_c_req').press('Tab');
    await magewire.idle();
}

test('the saved address card shows the groups and the order carries them', { tag: ['@RAD4', '@DSP2'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    await openCheckout(page, magewire);
    const card = page.locator('body');
    await expect(card).toContainText('E2E Address Check: Two, Three');
    await expect(card).toContainText('E2E Address Radio Yes No: Yes');

    await fillCustomerRequired(page, magewire);
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    for (const address of [snapshot.shipping, snapshot.billing]) {
        expect(address.e2e_a_chk).toMatchObject({ value_label: 'Two, Three' });
        expect(address.e2e_a_yn).toMatchObject({ value_label: 'Yes' });
        expect(address.e2e_a_text).toMatchObject({ value: 'saved text' });
    }
});

test('a new address in the dialog starts empty, keeps its own groups and leaves the saved address alone', { tag: ['@RAD4'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    await openCheckout(page, magewire);
    await page.getByRole('button', { name: /new address/i }).first().click();
    const dialog = page.locator('#checkout-shipping-address-dialog');
    await expect(dialog.locator('[name="firstname"]')).toBeVisible();

    // nothing of the saved address is in the new form
    const box = (label: string) => dialog.locator('label', { hasText: new RegExp(`^\\s*${label}\\s*$`) }).filter({ has: page.locator('input[name^="e2e_a_chk"]') }).first().locator('input');
    for (const label of ['One', 'Two', 'Three']) {
        await expect(box(label), `${label} not ticked in a new address`).not.toBeChecked();
    }

    // the dialog's own fields (the checkout page behind it has fields of the same names)
    const region = dialog.locator('select[name="region"]').first();
    if (await region.count()) {
        await region.selectOption({ label: 'Texas' });
        await magewire.idle();
    }
    for (const [name, value] of Object.entries({ firstname: 'New', lastname: 'Dialog', 'street[0]': '9 New Road', postcode: '75003', city: 'Plano', telephone: '5551230001', e2e_a_req: 'new required', e2e_a_text: 'new text' })) {
        await dialog.locator(`[name="${name}"]`).first().fill(value);
        await dialog.locator(`[name="${name}"]`).first().press('Tab');
        await magewire.idle();
    }
    await box('Three').check();
    await magewire.idle();
    await dialog.locator('label', { hasText: /^\s*No\s*$/ }).filter({ has: page.locator('input[name="e2e_a_yn"]') }).first().locator('input').check();
    await magewire.idle();
    await dialog.getByRole('button', { name: /save|ship here|use this/i }).first().click();
    await magewire.idle();

    await fillCustomerRequired(page, magewire);
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.shipping.e2e_a_chk).toMatchObject({ value_label: 'Three' });
    expect(snapshot.shipping.e2e_a_yn).toMatchObject({ value_label: 'No' });
    expect(snapshot.shipping.e2e_a_text).toMatchObject({ value: 'new text' });

    // the saved address still has its own values; the new one was added with its own
    const saved = Object.values(accountValues(customerEmail()).addresses);
    expect(saved).toHaveLength(2);
    const old = saved.find((address) => address.e2e_a_text === 'saved text');
    expect(old?.e2e_a_chk.split(',')).toHaveLength(2);
    expect(old?.e2e_a_yn).toBe('1');
    const added = saved.find((address) => address.e2e_a_text === 'new text');
    expect(added?.e2e_a_chk.split(',')).toHaveLength(1);
});
