import {
    accountValues, chooseShippingAndPayment, customerEmail, dropQuotes, expect, fillCheckout, openAdminOrder, openCheckoutAsGuest, placeOrder,
    placedOrderSnapshot, requireFixtures, setAccountValues, test,
} from './helpers';

/**
 * CUS9: a logged-in customer places an order in the Hyvä Checkout without typing anything in the customer sections. The order gets the
 * same rows as a logged-in order of any other path (CUS4): one row per value the customer has for a field of the checkout or of the
 * account, with the label for dropdown, multiple select, date and yes/no, and none for an attribute the customer has no value for.
 * The admin order view lists one line per row (CUS5) and the account is not changed.
 */
const ACCOUNT = {
    e2e_c_below: 'account below',
    e2e_c_sel: 'Gold',
    e2e_c_date: '2026-05-17',
    e2e_c_multi: 'Opt 2|Opt 3',
    e2e_c_area: 'account area',
    e2e_c_bool: '1',
    e2e_c_account: 'account only value',
    e2e_c_areq: 'account value',
};

test.beforeEach(() => {
    dropQuotes(customerEmail());
    setAccountValues(customerEmail(), { ...ACCOUNT, e2e_c_text: '', e2e_c_order: '' });
});

test('CUS9: a logged-in order has one row per value, with labels, and the admin order view lists them', { tag: ['@CUS9', '@CUS4', '@CUS5', '@ORD3'] }, async ({ customerPage: page, customerMagewire: magewire, adminPage }) => {
    const before = accountValues(customerEmail()).customer_attributes;
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_c_sel']);
    await fillCheckout(page, magewire);
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { incrementId, snapshot } = await placedOrderSnapshot(page);

    expect(Object.keys(snapshot.customer).sort()).toEqual(
        ['e2e_c_account', 'e2e_c_area', 'e2e_c_areq', 'e2e_c_below', 'e2e_c_bool', 'e2e_c_date', 'e2e_c_multi', 'e2e_c_req', 'e2e_c_sel'],
    );

    // the rows: what the customer has, with labels (e2e_c_req was typed by the checkout helper)
    expect(snapshot.customer.e2e_c_sel).toMatchObject({ value_label: 'Gold' });
    expect(snapshot.customer.e2e_c_multi).toMatchObject({ value_label: 'Opt 2, Opt 3' });
    expect(snapshot.customer.e2e_c_date).toMatchObject({ value_label: '2026-05-17' });
    expect(snapshot.customer.e2e_c_bool).toMatchObject({ value_label: 'Yes' });
    expect(snapshot.customer.e2e_c_area).toMatchObject({ value: 'account area' });
    expect(snapshot.customer.e2e_c_below).toMatchObject({ value: 'account below' });
    expect(snapshot.customer.e2e_c_account).toMatchObject({ value: 'account only value' });
    // only in the account forms, so not typed in the checkout: read from the customer, like e2e_c_account
    expect(snapshot.customer.e2e_c_areq).toMatchObject({ value: 'account value' });
    expect(snapshot.customer.e2e_c_text, 'no value, no row').toBeUndefined();
    expect(snapshot.customer.e2e_c_order, 'no value, no row').toBeUndefined();
    // the admin order view: one line per row, label and value
    await openAdminOrder(adminPage, incrementId);
    const info = (await adminPage.locator('.order-account-information').innerText()).replace(/\s+/g, ' ');
    for (const text of ['E2E Customer Select Gold', 'E2E Customer Multi Opt 2, Opt 3', 'E2E Customer Area account area', 'E2E Customer Yes No Yes', 'E2E Account Only account only value']) {
        expect(info, text).toContain(text);
    }

    expect(accountValues(customerEmail()).customer_attributes, 'the account is not changed').toEqual(before);
});
