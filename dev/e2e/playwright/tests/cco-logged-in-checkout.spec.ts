import {
    accountValues, chooseShippingAndPayment, customerEmail, dropQuotes, expect, field, fillCheckout, openCheckoutAsGuest, optionOf, placeOrder,
    placedOrderSnapshot, quoteValues, requireFixtures, setAccountValues, test,
} from './helpers';

/**
 * A signed-in customer in the checkout (one-page variant). The fixture customer has values on the account for several customer
 * attributes; Prefill from Customer is on for e2e_c_below, e2e_c_sel, e2e_c_date, e2e_c_multi, e2e_c_area and e2e_c_bool and off for
 * e2e_c_text, e2e_c_req and e2e_c_order.
 * CCO3: prefill on shows the saved value, prefill off leaves the field empty.
 * CCO4: typing stores the value on the quote and does not change the account.
 * CCO5: clearing a prefilled value stores it as empty, the account keeps it, and it is prefilled again only at the next page load.
 * CCO6: the order carries the typed values; attributes that are not in the checkout still come from the customer.
 */
const account = {
    e2e_c_text: 'account text',
    e2e_c_below: 'account below',
    e2e_c_sel: 'Gold',
    e2e_c_date: '2026-05-17',
    e2e_c_multi: 'Opt 2',
    e2e_c_area: 'account area',
    e2e_c_bool: '1',
    e2e_c_account: 'account only value',
};

test.beforeEach(() => {
    // a fresh start: no active cart (so the prefill runs again) and known values on the account
    dropQuotes(customerEmail());
    setAccountValues(customerEmail(), account);
});

test('prefill on shows the saved value, prefill off leaves the field empty', { tag: ['@CCO3'] }, async ({ customerPage: page }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_c_text', 'e2e_c_bool']);

    // prefill on
    await expect(optionOf(page, 'e2e_c_sel', 'radio', 'Gold')).toBeChecked();
    await expect(field(page, 'e2e_c_date')).toHaveValue('2026-05-17');
    await expect(optionOf(page, 'e2e_c_multi', 'checkbox', 'Opt 2')).toBeChecked();
    await expect(optionOf(page, 'e2e_c_multi', 'checkbox', 'Opt 1')).not.toBeChecked();
    await expect(field(page, 'e2e_c_area')).toHaveValue('account area');
    await expect(field(page, 'e2e_c_below')).toHaveValue('account below');
    await expect(optionOf(page, 'e2e_c_bool', 'radio', 'Yes')).toBeChecked();

    // prefill off: the account has a value, the field is empty
    await expect(field(page, 'e2e_c_text')).toHaveValue('');
    await expect(field(page, 'e2e_c_req')).toHaveValue('');
    await expect(field(page, 'e2e_c_order')).toHaveValue('');
});

test('typing stores the value on the quote and leaves the account unchanged', { tag: ['@CCO4'] }, async ({ customerPage: page, customerMagewire }) => {
    const before = accountValues(customerEmail()).customer_attributes;
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_c_text']);

    const typed = async (code: string, value: string) => {
        await field(page, code).fill(value);
        await field(page, code).press('Tab');
        await customerMagewire.idle();
    };
    await typed('e2e_c_text', 'typed text');
    await typed('e2e_c_area', 'typed area');
    await typed('e2e_c_date', '2027-02-02');

    const quote = quoteValues(customerEmail()).customer_attributes;
    expect(quote.e2e_c_text).toBe('typed text');
    expect(quote.e2e_c_area).toBe('typed area');
    expect(quote.e2e_c_date).toBe('2027-02-02 00:00:00');
    // values nobody touched were prefilled onto the quote, still unchanged
    expect(quote.e2e_c_below).toBe('account below');

    expect(accountValues(customerEmail()).customer_attributes).toEqual(before);
});

test('clearing a prefilled value stores it as empty; the account keeps it; it is prefilled again at the next page load', { tag: ['@CCO5', '@CHK5'] }, async ({ customerPage: page, customerMagewire }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_c_below']);
    await expect(field(page, 'e2e_c_below')).toHaveValue('account below');

    await field(page, 'e2e_c_below').fill('');
    await field(page, 'e2e_c_below').press('Tab');
    await customerMagewire.idle();

    // empty on the quote (an empty value is stored as no row), not refilled while the page stays open, account untouched
    expect(quoteValues(customerEmail()).customer_attributes.e2e_c_below ?? '').toBe('');
    await expect(field(page, 'e2e_c_below')).toHaveValue('');
    expect(accountValues(customerEmail()).customer_attributes.e2e_c_below).toBe('account below');

    // the next page load prefills the empty field again
    await page.reload();
    await expect(field(page, 'e2e_c_below')).toHaveValue('account below');
});

test('the order carries the typed values and the account-only values; the account stays unchanged', { tag: ['@CCO6', '@ORD3'] }, async ({ customerPage: page, customerMagewire }) => {
    const before = accountValues(customerEmail()).customer_attributes;
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_c_text']);
    await fillCheckout(page, customerMagewire);
    await chooseShippingAndPayment(page, customerMagewire);

    await field(page, 'e2e_c_text').fill('typed on checkout');
    await field(page, 'e2e_c_text').press('Tab');
    await customerMagewire.idle();
    await field(page, 'e2e_c_order').fill('order only value');
    await field(page, 'e2e_c_order').press('Tab');
    await customerMagewire.idle();

    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    // typed in the checkout (the account has other values or none)
    expect(snapshot.customer.e2e_c_text?.value).toBe('typed on checkout');
    expect(snapshot.customer.e2e_c_order?.value).toBe('order only value');
    // prefilled from the account and not touched
    expect(snapshot.customer.e2e_c_sel?.value_label).toBe('Gold');
    expect(snapshot.customer.e2e_c_date?.value_label).toBe('2026-05-17');
    expect(snapshot.customer.e2e_c_multi?.value_label).toBe('Opt 2');
    expect(snapshot.customer.e2e_c_below?.value).toBe('account below');
    // not in the checkout at all: read from the customer when the order is placed
    expect(snapshot.customer.e2e_c_account?.value).toBe('account only value');

    expect(accountValues(customerEmail()).customer_attributes).toEqual(before);
});
