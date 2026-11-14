import type { Page } from '@playwright/test';
import { adminOrderIdle, benchCommand, accountValues, addAdminOrderProduct, chooseAdminPayment, chooseAdminShipping, chosenLabels, completeAdminOrder, customerEmail, customerTab, deleteSavedAddresses, expect, fillOrderAddress, fillOrderValues, openCustomerEdit, openOrderAddressEdit, orderField, orderViewAddresses, setAccountValues, snapshotOf, startAdminOrder, startAdminOrderNewCustomer, submitAdminOrder, test, uniqueEmail } from './helpers';

/**
 * AOC (second part): "Add to address book", a new customer, reorder, required address attribute, and the form kinds in the admin.
 * Same fixtures as aoc-admin-order.spec.ts.
 */
const email = customerEmail();
const TYPED = { e2e_a_text: 'Typed text é', e2e_a_sel: 'South', e2e_a_multi: ['Red', 'Blue'], e2e_a_date: '5/6/2026', e2e_a_area: 'First line\nSecond line', e2e_a_bool: 'Yes' };

test.afterEach(() => {
    deleteSavedAddresses(email);
});

test('AOC6: "Add to address book" copies the values to the customer address', { tag: ['@AOC6'] }, async ({ adminPage: page }) => {
    setAccountValues(email, { e2e_c_text: 'seed text' });
    deleteSavedAddresses(email);
    expect(Object.keys(accountValues(email).addresses), 'the customer starts without a saved address').toEqual([]);

    // not ticked: no address is saved
    await startAdminOrder(page, email);
    await completeAdminOrder(page, TYPED);
    expect(Object.keys(accountValues(email).addresses)).toEqual([]);

    // ticked: the saved address carries the typed values
    await startAdminOrder(page, email);
    await addAdminOrderProduct(page, '24-MB04');
    await fillOrderAddress(page, 'billing_address');
    await fillOrderValues(page, 'billing_address', { e2e_a_req: 'required text', e2e_a_rdep: 'required dep', ...TYPED });
    await page.locator('#order-billing_address_save_in_address_book').check();
    await chooseAdminShipping(page);
    await chooseAdminPayment(page);
    await submitAdminOrder(page);
    const saved = Object.values(accountValues(email).addresses);
    expect(saved).toHaveLength(1);
    expect(saved[0]).toMatchObject({ e2e_a_text: 'Typed text é', e2e_a_area: 'First line\r\nSecond line', e2e_a_req: 'required text' });
    expect(saved[0].e2e_a_sel, 'select stored as an option id').toMatch(/^\d+$/);
    expect(saved[0].e2e_a_multi).toMatch(/^\d+,\d+$/);
    expect(saved[0].e2e_a_date).toContain('2026-05-06');
});

test('AOC7: an order for a new customer: no errors, the form values are on the order', { tag: ['@AOC7'] }, async ({ adminPage: page }) => {
    // e2e_c_areq is required and only in the account forms: core refuses to create a customer without it, so the admin order could never
    // be placed (the same situation as VAL6 for addresses). It is optional for this test and put back afterwards.
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_c_areq', 'required=0', 'forms=customer_account_create,customer_account_edit']);
    try {
        await newCustomerOrder(page);
    } finally {
        benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_c_areq', 'required=1', 'forms=customer_account_create,customer_account_edit']);
    }
});

async function newCustomerOrder(page: Page): Promise<void> {
    const newEmail = uniqueEmail('aocnew');
    await startAdminOrderNewCustomer(page);
    await orderField(page, 'account', 'email').or(page.locator('#email')).first().fill(newEmail);
    await addAdminOrderProduct(page, '24-MB04');
    await fillOrderAddress(page, 'billing_address');
    await fillOrderValues(page, 'billing_address', { e2e_a_req: 'required text', e2e_a_rdep: 'required dep', ...TYPED });
    await chooseAdminShipping(page);
    await chooseAdminPayment(page);
    await fillOrderValues(page, 'account', { e2e_c_text: 'New customer text', e2e_c_req: 'req', e2e_c_rdep: 'rdep', e2e_c_rmulti: ['Alpha'], e2e_c_sel: 'Gold' });
    const { incrementId } = await submitAdminOrder(page);
    await expect(page.locator('.message-error')).toHaveCount(0);
    const snapshot = snapshotOf(incrementId);
    expect(snapshot.customer.e2e_c_text).toMatchObject({ value: 'New customer text' });
    expect(snapshot.billing.e2e_a_text).toMatchObject({ value: 'Typed text é' });
    expect(snapshot.shipping.e2e_a_sel).toMatchObject({ value_label: 'South' });
    expect(await page.locator('.order-account-information').innerText()).toContain('E2E Customer Text');
    expect(await page.locator('.order-account-information').innerText()).toContain('New customer text');
    // the order created the customer account
    expect(accountValues(newEmail).customer_attributes.e2e_c_text).toBe('New customer text');
}

test('AOC8: reorder from the admin copies the values', { tag: ['@AOC8'] }, async ({ adminPage: page }) => {
    setAccountValues(email, { e2e_c_text: 'seed text' });
    await startAdminOrder(page, email);
    const first = await completeAdminOrder(page, TYPED);
    await page.locator('#order_reorder, button:has-text("Reorder")').first().click();
    await expect(page).toHaveURL(/sales\/order_create/);
    await adminOrderIdle(page);
    await expect(page.locator('#order-items')).toContainText('24-MB04');
    await expect(orderField(page, 'billing_address', 'e2e_a_text')).toHaveValue('Typed text é');
    expect(await chosenLabels(orderField(page, 'billing_address', 'e2e_a_sel'))).toEqual(['South']);
    expect(await chosenLabels(orderField(page, 'billing_address', 'e2e_a_multi'))).toEqual(['Red', 'Blue']);
    await expect(orderField(page, 'billing_address', 'e2e_a_date')).toHaveValue('5/6/26');
    await expect(orderField(page, 'account', 'e2e_c_text')).toHaveValue('seed text');
    await chooseAdminShipping(page);
    await chooseAdminPayment(page);
    const second = await submitAdminOrder(page);
    expect(second.incrementId).not.toBe(first.incrementId);
    const a = snapshotOf(first.incrementId);
    const b = snapshotOf(second.incrementId);
    expect(b.billing).toEqual(a.billing);
    expect(b.shipping).toEqual(a.shipping);
    expect(b.customer.e2e_c_text).toEqual(a.customer.e2e_c_text);
});

test('AOC10: a required address attribute left empty gives the admin validation message', { tag: ['@AOC10'] }, async ({ adminPage: page }) => {
    setAccountValues(email, { e2e_c_text: 'seed text' });
    // order create: the form refuses to submit
    await startAdminOrder(page, email);
    await addAdminOrderProduct(page, '24-MB04');
    await fillOrderAddress(page, 'billing_address');
    await fillOrderValues(page, 'billing_address', { e2e_a_rdep: 'required dep' });
    await chooseAdminShipping(page);
    await chooseAdminPayment(page);
    await page.locator('#submit_order_top_button').click();
    await expect(page.getByText('This is a required field.').first()).toBeVisible();
    await expect(page).toHaveURL(/sales\/order_create/);

    // Edit Order Address: the form refuses, and the server refuses too when the browser check is bypassed
    await startAdminOrder(page, email);
    const { orderId } = await completeAdminOrder(page, TYPED);
    await openOrderAddressEdit(page, 'billing');
    const required = page.locator('#e2e_a_req');
    await required.fill('');
    await page.locator('button:has-text("Save Order Address")').first().click();
    await expect(page.getByText('This is a required field.').first()).toBeVisible();
    await expect(page).toHaveURL(/sales\/order\/address/);
    await page.evaluate(() => {
        const form = document.querySelector('#edit_form') as HTMLFormElement;
        form.querySelectorAll('.required-entry').forEach((node) => node.classList.remove('required-entry'));
        form.noValidate = true;
        form.submit();
    });
    await page.waitForLoadState('load');
    await expect(page.locator('.message-error, .messages .error').first()).toBeVisible();
    const order = orderId;
    expect(order).not.toBe('');
});

test('AOC11: admin forms keep the dropdown and list and show every attribute', { tag: ['@AOC11'] }, async ({ adminPage: page }) => {
    setAccountValues(email, { e2e_c_text: 'seed text' });
    // fixtures shown as radio / checkbox group in the storefront stay a select / multiple select in the admin; rule-controlled ones are shown
    const checkKinds = async (area: 'account' | 'billing_address') => {
        const field = (code: string) => orderField(page, area, code);
        const kinds = area === 'account'
            ? { e2e_c_sel: 'select', e2e_c_bool: 'select', e2e_c_multi: 'multiple', e2e_c_dep: 'input', e2e_c_rdep: 'input', e2e_c_below: 'input' }
            : { e2e_a_sel: 'select', e2e_a_bool: 'select', e2e_a_multi: 'multiple', e2e_a_dep: 'input', e2e_a_rdep: 'input', e2e_a_xdep: 'input', e2e_a_ndep: 'input', e2e_a_odep: 'input', e2e_a_mdep: 'input' };
        for (const [code, kind] of Object.entries(kinds)) {
            const locator = field(code);
            await expect(locator, `${area} ${code} is shown`).toBeVisible();
            await expect(locator, `${area} ${code} is enabled`).toBeEnabled();
            const tag = await locator.evaluate((node) => node.tagName.toLowerCase());
            if (kind === 'multiple') {
                expect(tag).toBe('select');
                expect(await locator.evaluate((node) => (node as HTMLSelectElement).multiple), code).toBe(true);
            } else {
                expect(tag, code).toBe(kind);
            }
        }
    };
    await startAdminOrder(page, email);
    await checkKinds('account');
    await checkKinds('billing_address');

    // Edit Order Address
    await addAdminOrderProduct(page, '24-MB04');
    await fillOrderAddress(page, 'billing_address');
    await fillOrderValues(page, 'billing_address', { e2e_a_req: 'required text', e2e_a_rdep: 'required dep' });
    await chooseAdminShipping(page);
    await chooseAdminPayment(page);
    await submitAdminOrder(page);
    await openOrderAddressEdit(page, 'billing');
    for (const code of ['e2e_a_sel', 'e2e_a_bool', 'e2e_a_multi', 'e2e_a_dep', 'e2e_a_rdep', 'e2e_a_xdep', 'e2e_a_ndep']) {
        await expect(page.locator(`#${code}`), code).toBeVisible();
        await expect(page.locator(`#${code}`), code).toBeEnabled();
    }
    expect(await page.locator('#e2e_a_sel').evaluate((node) => node.tagName.toLowerCase())).toBe('select');

    // admin customer form
    await openCustomerEdit(page, email);
    await customerTab(page, 'Account Information');
    for (const label of ['E2E Customer Select', 'E2E Customer Multi', 'E2E Customer Yes No', 'E2E Customer Dependent', 'E2E Customer Required Dependent']) {
        await expect(page.getByText(label, { exact: true }).first(), label).toBeVisible();
    }
});
