import type { Locator, Page } from '@playwright/test';
import { adminOrderIdle, chosenLabels, completeAdminOrder, customerEmail, deleteSavedAddresses, expect, fillOrderValues, openOrderAddressEdit, orderField, orderViewAddresses, setAccountValues, setSavedAddress, snapshotOf, startAdminOrder, test } from './helpers';

/**
 * AOC: admin order create and Edit Order Address, with the fixture customer (Main Website, Default Store View).
 *
 * Address fixtures: e2e_a_text (text), e2e_a_sel (select North/South), e2e_a_multi (multiselect Red/Green/Blue), e2e_a_date (date),
 * e2e_a_area (textarea), e2e_a_bool (yes/no). Customer fixtures: e2e_c_text, e2e_c_sel (Gold/Silver), e2e_c_multi (Opt 1-3), e2e_c_date,
 * e2e_c_area, e2e_c_bool, and e2e_c_account (not in the admin order form). The required fixtures (e2e_a_req, e2e_a_rdep, e2e_c_req, ...)
 * are filled by the helpers. The admin shows dates as M/d/yy (3/4/26) and accepts four-digit years when typed.
 */
const email = customerEmail();

/** What a test types into the address fields of the order form (and what the saved address of AOC4 holds, in the stored format). */
const TYPED = { e2e_a_text: 'Typed text é', e2e_a_sel: 'South', e2e_a_multi: ['Red', 'Blue'], e2e_a_date: '5/6/2026', e2e_a_area: 'First line\nSecond line', e2e_a_bool: 'Yes' };
const SAVED = { e2e_a_text: 'Saved text', e2e_a_sel: 'North', e2e_a_multi: 'Green|Blue', e2e_a_date: '2026-07-08', e2e_a_area: 'Saved line one\nSaved line two', e2e_a_bool: '1', e2e_a_req: 'saved required' };

test.afterEach(() => {
    deleteSavedAddresses(email);
    // the values of AOC3 and the others must not stay on the customer: a Silver customer has two more required fields in the checkout
    setAccountValues(email, { e2e_c_sel: '', e2e_c_multi: '', e2e_c_date: '', e2e_c_area: '', e2e_c_bool: '' });
});

/** Checks the value of one custom field (any type) in an admin form whose field locators are given by `field(code)`. */
async function expectFieldValues(field: (code: string) => Locator, expected: Record<string, string | string[]>): Promise<void> {
    for (const [code, value] of Object.entries(expected)) {
        const locator = field(code);
        const tag = await locator.evaluate((node) => node.tagName.toLowerCase());
        if (tag === 'select') {
            expect(await chosenLabels(locator), code).toEqual(Array.isArray(value) ? value : [value]);
        } else {
            await expect(locator, code).toHaveValue(value as string);
        }
    }
}

/** The values of the fields on the Edit Order Address page (their ids are the attribute codes). */
const editField = (page: Page) => (code: string) => page.locator(`#${code}`);

test('AOC3: order create shows the customer attributes with the customer values in Account Information', { tag: ['@AOC3'] }, async ({ adminPage: page }) => {
    setAccountValues(email, {
        e2e_c_text: 'Customer text', e2e_c_sel: 'Silver', e2e_c_multi: 'Opt 1|Opt 3', e2e_c_date: '2026-03-04', e2e_c_area: 'Line A\nLine B', e2e_c_bool: '1',
    });
    await startAdminOrder(page, email);
    await expectFieldValues((code) => orderField(page, 'account', code), {
        e2e_c_text: 'Customer text', e2e_c_sel: 'Silver', e2e_c_multi: ['Opt 1', 'Opt 3'], e2e_c_date: '3/4/26', e2e_c_area: 'Line A\nLine B', e2e_c_bool: 'Yes',
    });
    // the attributes of the order form, and only those: one that is not ticked for it is not there
    await expect(orderField(page, 'account', 'e2e_c_order')).toBeVisible();
    await expect(orderField(page, 'account', 'e2e_c_account')).toHaveCount(0);
});

test('AOC4: picking a saved address fills the custom fields', { tag: ['@AOC4'] }, async ({ adminPage: page }) => {
    setSavedAddress(email, SAVED);
    await startAdminOrder(page, email);
    const picker = page.locator('#order-billing_address_customer_address_id');
    // the first entry is "Add New Address": the second is the saved address
    await picker.selectOption({ index: 1 });
    await adminOrderIdle(page);
    await expectFieldValues((code) => orderField(page, 'billing_address', code), {
        e2e_a_text: 'Saved text', e2e_a_sel: 'North', e2e_a_multi: ['Green', 'Blue'], e2e_a_date: '7/8/26', e2e_a_area: 'Saved line one\nSaved line two', e2e_a_bool: 'Yes', e2e_a_req: 'saved required',
    });
    // "Add New Address" empties them again
    await picker.selectOption({ index: 0 });
    await adminOrderIdle(page);
    await expect(orderField(page, 'billing_address', 'e2e_a_text')).toHaveValue('');
    expect(await chosenLabels(orderField(page, 'billing_address', 'e2e_a_multi'))).toEqual([]);
});

test('AOC5: typed values with "same as billing" go to both addresses of the placed order', { tag: ['@AOC5'] }, async ({ adminPage: page }) => {
    setAccountValues(email, { e2e_c_text: 'seed text' });
    await startAdminOrder(page, email);
    await expect(page.locator('#order-shipping_same_as_billing')).toBeChecked();
    const { incrementId } = await completeAdminOrder(page, TYPED);
    const snapshot = snapshotOf(incrementId);
    const expected = {
        e2e_a_text: { value: 'Typed text é', value_label: 'Typed text é' },
        e2e_a_sel: { value_label: 'South' },
        e2e_a_multi: { value_label: 'Red, Blue' },
        e2e_a_date: { value: '2026-05-06 00:00:00', value_label: '2026-05-06' },
        e2e_a_area: { value: 'First line\r\nSecond line' },
        e2e_a_bool: { value: '1', value_label: 'Yes' },
    };
    for (const address of ['billing', 'shipping'] as const) {
        for (const [code, parts] of Object.entries(expected)) {
            expect(snapshot[address][code], `${address} ${code}`).toMatchObject(parts);
        }
    }
    // and the order view shows them on both
    const view = await orderViewAddresses(page);
    for (const text of [view.billing, view.shipping]) {
        expect(text).toContain('E2E Address Text: Typed text é');
        expect(text).toContain('E2E Address Select: South');
        expect(text).toContain('E2E Address Multi: Red, Blue');
        expect(text).toContain('E2E Address Date: May 6, 2026');
    }
});

test('AOC1: Edit Order Address shows the values of the order', { tag: ['@AOC1'] }, async ({ adminPage: page }) => {
    setAccountValues(email, { e2e_c_text: 'seed text' });
    await startAdminOrder(page, email);
    await completeAdminOrder(page, TYPED);
    for (const which of ['billing', 'shipping'] as const) {
        await openOrderAddressEdit(page, which);
        await expectFieldValues(editField(page), {
            e2e_a_text: 'Typed text é', e2e_a_sel: 'South', e2e_a_multi: ['Red', 'Blue'], e2e_a_date: '5/6/26', e2e_a_area: 'First line\nSecond line', e2e_a_bool: 'Yes', e2e_a_req: 'required text',
        });
        await page.goBack();
    }
});

test('AOC2: saving changed, cleared and locale-date values updates the snapshot', { tag: ['@AOC2'] }, async ({ adminPage: page }) => {
    setAccountValues(email, { e2e_c_text: 'seed text' });
    await startAdminOrder(page, email);
    const { incrementId } = await completeAdminOrder(page, TYPED);
    await openOrderAddressEdit(page, 'billing');
    await editField(page)('e2e_a_text').fill('Changed text');
    await editField(page)('e2e_a_sel').selectOption({ label: 'North' });
    await editField(page)('e2e_a_multi').selectOption([{ label: 'Green' }]);
    await editField(page)('e2e_a_date').fill('12/31/2026');
    await editField(page)('e2e_a_area').fill('');
    // core requires this attribute on the edit form although its rule hid the value on the order
    await editField(page)('e2e_a_rdep').fill('required dep');
    await page.locator('button:has-text("Save Order Address")').first().click();
    await expect(page).toHaveURL(/sales\/order\/view/);

    const snapshot = snapshotOf(incrementId);
    expect(snapshot.billing.e2e_a_text).toMatchObject({ value: 'Changed text', value_label: 'Changed text' });
    expect(snapshot.billing.e2e_a_sel).toMatchObject({ value_label: 'North' });
    expect(snapshot.billing.e2e_a_multi).toMatchObject({ value_label: 'Green' });
    expect(snapshot.billing.e2e_a_date).toMatchObject({ value: '2026-12-31 00:00:00', value_label: '2026-12-31' });
    expect(snapshot.billing.e2e_a_area, 'a cleared value leaves no row').toBeUndefined();
    // the shipping address was not edited
    expect(snapshot.shipping.e2e_a_text).toMatchObject({ value: 'Typed text é' });
    expect(snapshot.shipping.e2e_a_area).toMatchObject({ value: 'First line\r\nSecond line' });

    // the order view shows the new values with the attribute labels
    const view = await orderViewAddresses(page);
    expect(view.billing).toContain('E2E Address Text: Changed text');
    expect(view.billing).toContain('E2E Address Select: North');
    expect(view.billing).toContain('E2E Address Date: Dec 31, 2026');
    expect(view.billing).not.toContain('E2E Address Area');
    expect(view.shipping).toContain('E2E Address Text: Typed text é');
});
