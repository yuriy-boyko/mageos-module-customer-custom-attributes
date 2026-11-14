import {
    accountValues, chooseShippingAndPayment, customerEmail, deleteSavedAddresses, dropQuotes, expect, fillCheckout, openCheckoutAsGuest, placeOrder,
    placedOrderSnapshot, requireFixtures, setAccountValues, test,
} from './helpers';

/**
 * BOOK1: a logged-in customer enters a new address in the checkout with "Save in address book" ticked (the default): the customer
 * address holds all the values. BOOK2: the same with the box unticked: no customer address is created, the order rows exist.
 */
const TYPED = { e2e_a_text: 'Book text', e2e_a_area: 'Book area' };

test.beforeEach(() => {
    dropQuotes(customerEmail());
    deleteSavedAddresses(customerEmail());
    setAccountValues(customerEmail(), { e2e_c_text: 'account text' });
});

test.afterEach(() => {
    deleteSavedAddresses(customerEmail());
});

async function placeNewAddressOrder(page: import('@playwright/test').Page, magewire: { idle(): Promise<void> }, save: boolean) {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_text']);
    await fillCheckout(page, magewire, TYPED);
    const box = page.locator('#shipping-save');
    await expect(box, 'the box is ticked by default').toBeChecked();
    if (!save) {
        await box.uncheck();
        await magewire.idle();
    }
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    return placedOrderSnapshot(page);
}

test('BOOK1: a new address saved in the address book holds all the values', { tag: ['@BOOK1'] }, async ({ customerPage: page, customerMagewire }) => {
    const { snapshot } = await placeNewAddressOrder(page, customerMagewire, true);
    expect(snapshot.shipping.e2e_a_text).toMatchObject({ value: 'Book text' });
    const saved = Object.values(accountValues(customerEmail()).addresses);
    expect(saved).toHaveLength(1);
    expect(saved[0]).toMatchObject({ e2e_a_text: 'Book text', e2e_a_area: 'Book area' });
});

test('BOOK2: a new address not saved in the address book creates no customer address, the order rows exist', { tag: ['@BOOK2'] }, async ({ customerPage: page, customerMagewire }) => {
    const { snapshot } = await placeNewAddressOrder(page, customerMagewire, false);
    expect(snapshot.shipping.e2e_a_text).toMatchObject({ value: 'Book text' });
    expect(snapshot.billing.e2e_a_text).toMatchObject({ value: 'Book text' });
    expect(snapshot.shipping.e2e_a_area).toMatchObject({ value: 'Book area' });
    expect(Object.keys(accountValues(customerEmail()).addresses)).toEqual([]);
});
