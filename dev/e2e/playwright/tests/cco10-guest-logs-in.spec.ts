import { customerEmail, dropQuotes, expect, field, loginAs, openCheckoutAsGuest, optionOf, setAccountValues, test } from './helpers';

/**
 * CCO10: a guest types values and then signs in during the checkout.
 *  - a customer without an active cart gets the guest cart: the typed values are kept, and the prefill only fills the fields that are empty;
 *  - a customer with an active cart keeps that cart: its values win and the guest's typed values go with the guest cart.
 * The account has e2e_c_date, e2e_c_below and e2e_c_sel (prefill on) and e2e_c_text (prefill off).
 */
const account = { e2e_c_text: 'account text', e2e_c_below: 'account below', e2e_c_sel: 'Gold', e2e_c_date: '2026-05-17' };

test.beforeEach(() => {
    dropQuotes(customerEmail());
    setAccountValues(customerEmail(), account);
});

test('a customer without an active cart keeps what the guest typed; empty fields are prefilled', { tag: ['@CCO10', '@CHK19'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await field(page, 'e2e_c_text').fill('guest typed');
    await field(page, 'e2e_c_text').press('Tab');
    await magewire.idle();
    await field(page, 'e2e_c_date').fill('2027-01-01');
    await field(page, 'e2e_c_date').press('Tab');
    await magewire.idle();

    await loginAs(page, customerEmail());
    await page.goto('/checkout/');
    await expect(page.locator('[data-mageos-cca-customer]').first()).toBeAttached();

    // typed values kept, the typed date is not replaced by the account's
    await expect(field(page, 'e2e_c_text')).toHaveValue('guest typed');
    await expect(field(page, 'e2e_c_date')).toHaveValue('2027-01-01');
    // fields the guest left empty are prefilled from the account
    await expect(field(page, 'e2e_c_below')).toHaveValue('account below');
    await expect(optionOf(page, 'e2e_c_sel', 'radio', 'Gold')).toBeChecked();
});

test('a customer with an active cart keeps that cart and its values', { tag: ['@CCO10', '@CHK19'] }, async ({ page, magewire, customerPage, customerMagewire }) => {
    // the customer's own cart, with its own values
    await openCheckoutAsGuest(customerPage);
    await field(customerPage, 'e2e_c_text').fill('customer cart text');
    await field(customerPage, 'e2e_c_text').press('Tab');
    await customerMagewire.idle();
    await field(customerPage, 'e2e_c_date').fill('2028-03-03');
    await field(customerPage, 'e2e_c_date').press('Tab');
    await customerMagewire.idle();

    // a guest cart with other values, then the guest signs in as the same customer
    await openCheckoutAsGuest(page);
    await field(page, 'e2e_c_text').fill('guest typed');
    await field(page, 'e2e_c_text').press('Tab');
    await magewire.idle();
    await field(page, 'e2e_c_date').fill('2027-01-01');
    await field(page, 'e2e_c_date').press('Tab');
    await magewire.idle();

    await loginAs(page, customerEmail());
    await page.goto('/checkout/');
    await expect(page.locator('[data-mageos-cca-customer]').first()).toBeAttached();

    await expect(field(page, 'e2e_c_text')).toHaveValue('customer cart text');
    await expect(field(page, 'e2e_c_date')).toHaveValue('2028-03-03');
});
