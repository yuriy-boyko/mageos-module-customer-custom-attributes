import {
    accountValues, benchCommand, chooseShippingAndPayment, createAttributes, customerEmail, deleteCreated, dropQuotes, expect, field, fillCheckout, openCheckoutAsGuest,
    placeOrder, placedOrderSnapshot, requireFixtures, setAccountValues, test,
} from './helpers';

/**
 * ACC1: "Also Save to Customer Account" of a customer attribute shown in the checkout (key mageos_checkout_save_to_account, default off).
 * A logged-in customer types values in the checkout sections. After the order:
 *  - the value of an attribute that has the setting is on the account; an attribute without it leaves the account as it was;
 *  - an empty field never erases the value the account has;
 *  - the order has its snapshot rows as before.
 * Uses attributes the tests create themselves (e2e_c_acc...).
 */
const SAVED = 'e2e_c_accsave';
const PLAIN = 'e2e_c_accplain';
const FORMS = ['customer_account_edit', 'mageos_hyva_checkout'];
const email = customerEmail();

test.afterEach(() => {
    for (const code of [SAVED, PLAIN]) {
        deleteCreated(code);
    }
});

async function typeAndOrder(page: import('@playwright/test').Page, magewire: Parameters<typeof fillCheckout>[1], typed: Record<string, string>) {
    dropQuotes(email);
    await openCheckoutAsGuest(page);
    await requireFixtures(page, [SAVED]);
    await fillCheckout(page, magewire, typed);
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    return placedOrderSnapshot(page);
}

test('ACC1: a typed value reaches the account when the setting is on, and an empty field keeps the stored value', { tag: ['@ACC1'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${FORMS.join(',')}`, '--position=payment_above', '--save-to-account', SAVED]);
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${FORMS.join(',')}`, '--position=payment_above', PLAIN]);
    setAccountValues(email, { [SAVED]: 'stored value', [PLAIN]: 'stored plain' });

    // 1. both typed: the one with the setting goes to the account, the other leaves it as it was
    const first = await typeAndOrder(page, magewire, { [SAVED]: 'typed in the checkout', [PLAIN]: 'typed plain' });
    expect(first.snapshot.customer[SAVED], 'the order has the typed value').toMatchObject({ value: 'typed in the checkout' });
    expect(first.snapshot.customer[PLAIN]).toMatchObject({ value: 'typed plain' });
    let account = accountValues(email).customer_attributes;
    expect(account[SAVED], 'saved to the account').toBe('typed in the checkout');
    expect(account[PLAIN], 'no setting: the account is not changed').toBe('stored plain');

    // 2. nothing typed: the account keeps what it has
    const second = await typeAndOrder(page, magewire, {});
    expect(second.snapshot.customer[SAVED], 'nothing typed, no value on the order').toBeUndefined();
    account = accountValues(email).customer_attributes;
    expect(account[SAVED], 'an empty field never erases the stored value').toBe('typed in the checkout');
});

test('ACC1: a field typed and cleared again leaves the account as it was', { tag: ['@ACC1'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${FORMS.join(',')}`, '--position=payment_above', '--save-to-account', SAVED]);
    setAccountValues(email, { [SAVED]: 'stored value' });
    dropQuotes(email);

    await openCheckoutAsGuest(page);
    await requireFixtures(page, [SAVED]);
    await fillCheckout(page, magewire);
    await field(page, SAVED).fill('typed then cleared');
    await field(page, SAVED).press('Tab');
    await magewire.idle();
    await field(page, SAVED).fill('');
    await field(page, SAVED).press('Tab');
    await magewire.idle();
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    await placedOrderSnapshot(page);

    expect(accountValues(email).customer_attributes[SAVED]).toBe('stored value');
});
