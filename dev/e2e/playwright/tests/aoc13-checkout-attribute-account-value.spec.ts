import { benchCommand, completeAdminOrder, customerEmail, deleteCreated, deleteSavedAddresses, dropQuotes, expect, openAdminOrder, setAccountValues, snapshotOf, startAdminOrder, test } from './helpers';

/**
 * AOC13: an attribute shown in the checkout (Hyvä Checkout form) has no typed value on a quote made in the admin. The order gets the
 * value the customer has on the account, like an attribute that is not in the checkout, and the admin order view lists it. A storefront
 * order keeps the typed value (CUS9, ACC1).
 */
const CODE = 'e2e_c_aocchk';
const email = customerEmail();

test.afterEach(() => {
    deleteCreated(CODE);
    deleteSavedAddresses(email);
});

test('AOC13: an admin-created order has the account value of an attribute that is shown in the checkout', { tag: ['@AOC13'] }, async ({ adminPage: page }) => {
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', '--forms=customer_account_edit,mageos_hyva_checkout', '--position=payment_above', CODE]);
    setAccountValues(email, { [CODE]: 'value on the account', e2e_c_text: 'seed text' });
    dropQuotes(email);

    await startAdminOrder(page, email);
    const { incrementId } = await completeAdminOrder(page);

    expect(snapshotOf(incrementId).customer[CODE], 'a row with the account value').toMatchObject({ value: 'value on the account' });
    await openAdminOrder(page, incrementId);
    await expect(page.locator('body')).toContainText(`ADM ${CODE}`);
    await expect(page.locator('body')).toContainText('value on the account');
});
