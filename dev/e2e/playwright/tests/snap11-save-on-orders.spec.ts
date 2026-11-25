import {
    accountValues, attributeRow, attributeTab, benchCommand, chooseShippingAndPayment, createAttributes, customerEmail, deleteCreated, dropQuotes, expect, field,
    fillCheckout, openAttribute, openCheckoutAsGuest, placeOrder, placedOrderSnapshot, saveAttribute, setAccountValues, test, uniqueEmail,
} from './helpers';

/**
 * SNAP11: the setting "Save on Orders" of a customer attribute (key mageos_order_snapshot in the validate rules, default on).
 *  - the admin screen stores it (0 when off, no key when on);
 *  - a logged-in order has a row for the attribute that is on and none for the one that is off, and the account keeps both values;
 *  - an attribute that is off but required in the checkout is still checked, and its value is not on the order either.
 * Uses attributes the tests create themselves (e2e_c_snap...).
 */
const KEPT = 'e2e_c_snapon';
const INTERNAL = 'e2e_c_snapoff';
const REQUIRED = 'e2e_c_snapreq';
const ACCOUNT_FORMS = ['customer_account_edit'];

test.afterEach(() => {
    for (const code of [KEPT, INTERNAL, REQUIRED]) {
        deleteCreated(code);
    }
});

test('SNAP11: the attribute screen stores "Save on Orders" (0 when off, nothing when on)', { tag: ['@SNAP11'] }, async ({ adminPage: page }) => {
    createAttributes('customer', [KEPT], ACCOUNT_FORMS);
    expect(attributeRow('customer', KEPT).rules, 'on by default: no key').not.toHaveProperty('mageos_order_snapshot');

    await openAttribute(page, 'customer', KEPT);
    await attributeTab(page, 'Storefront Properties');
    await expect(page.locator('#save_on_order')).toHaveValue('1');
    await page.locator('#save_on_order').selectOption('0');
    await saveAttribute(page, true);
    await expect(page.locator('.message-success')).toBeVisible();
    expect(attributeRow('customer', KEPT).rules).toMatchObject({ mageos_order_snapshot: 0 });
    await attributeTab(page, 'Storefront Properties');
    await expect(page.locator('#save_on_order'), 'the screen shows what was saved').toHaveValue('0');

    await page.locator('#save_on_order').selectOption('1');
    await saveAttribute(page, true);
    await expect(page.locator('.message-success')).toBeVisible();
    expect(attributeRow('customer', KEPT).rules, 'switched on again: no key').not.toHaveProperty('mageos_order_snapshot');
});

test('SNAP11: a logged-in order has a row for the attribute that is on and none for the one that is off', { tag: ['@SNAP11'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    createAttributes('customer', [KEPT], ACCOUNT_FORMS);
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${ACCOUNT_FORMS.join(',')}`, '--snapshot=0', INTERNAL]);
    dropQuotes(customerEmail());
    setAccountValues(customerEmail(), { [KEPT]: 'kept value', [INTERNAL]: 'internal value' });

    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire);
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);

    expect(snapshot.customer[KEPT], 'the attribute that is on').toMatchObject({ value: 'kept value' });
    expect(snapshot.customer[INTERNAL], 'the attribute that is off leaves no row').toBeUndefined();
    const account = accountValues(customerEmail()).customer_attributes;
    expect(account[KEPT]).toBe('kept value');
    expect(account[INTERNAL], 'the customer keeps the value').toBe('internal value');
});

test('SNAP11: an attribute that is off but required in the checkout is still checked, and not on the order', { tag: ['@SNAP11'] }, async ({ page, magewire }) => {
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${ACCOUNT_FORMS.join(',')}`, '--position=payment_above', '--snapshot=0', REQUIRED]);
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', REQUIRED, 'required=1', 'forms=customer_account_edit,mageos_hyva_checkout']);
    await openCheckoutAsGuest(page);
    await expect(field(page, REQUIRED)).toBeAttached();
    await fillCheckout(page, magewire, { email_address: uniqueEmail('snap11') });
    await chooseShippingAndPayment(page, magewire);

    await placeOrder(page);
    await expect(page.getByText(/"ADM e2e_c_snapreq" is a required value/).first(), 'refused without the value').toBeVisible();
    await expect(page).not.toHaveURL(/checkout\/onepage\/success/);

    await field(page, REQUIRED).fill('typed value');
    await field(page, REQUIRED).press('Tab');
    await magewire.idle();
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.customer[REQUIRED], 'checked, but not saved on the order').toBeUndefined();
});
