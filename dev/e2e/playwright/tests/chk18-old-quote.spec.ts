import { chooseShippingAndPayment, createAttributes, deleteCreated, expect, field, fillCheckout, openCheckoutAsGuest, placeOrder, placedOrderSnapshot, quoteValues, requireFixtures, test, uniqueEmail } from './helpers';

/**
 * CHK18: a quote that was created before an attribute existed. The shopper has a cart with a filled address; then the shop owner creates
 * a new address attribute and a new customer attribute for the checkout. The shopper reloads the checkout: no errors (the `page` fixture
 * fails the test on a script error, a CSP violation or a failed Magewire call), the new fields are there and empty, the old values are
 * still on the quote, and the order carries the old and the new values.
 */
const ADDRESS = 'e2e_chk18a';
const CUSTOMER = 'e2e_chk18c';

test.afterEach(() => {
    deleteCreated(ADDRESS);
    deleteCreated(CUSTOMER);
});

test('a quote created before the attributes existed loads without errors and takes the new values', { tag: ['@CHK18'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_text']);
    await fillCheckout(page, magewire, { email_address: uniqueEmail('guest') });
    // the old quote holds what fillCheckout typed: the required address and customer attributes
    const typed = (code: string): string | undefined => Object.values(quoteValues('@latest').addresses).map((address) => address[code]).find(Boolean);
    expect(typed('e2e_a_req'), JSON.stringify(quoteValues('@latest'))).toBe('required value');
    expect(quoteValues('@latest').customer_attributes.e2e_c_req).toBe('required customer value');

    // the new attributes appear while the shopper has the cart
    createAttributes('address', [ADDRESS], ['customer_address_edit', 'mageos_hyva_checkout', 'mageos_address_text']);
    createAttributes('customer', [CUSTOMER], ['mageos_hyva_checkout', 'adminhtml_checkout'], 'payment_above');

    await page.reload();
    await requireFixtures(page, [ADDRESS, CUSTOMER]);
    await expect(field(page, ADDRESS)).toHaveValue('');
    await expect(field(page, CUSTOMER)).toHaveValue('');
    await expect(field(page, 'e2e_a_req')).toHaveValue('required value');
    // a guest's e-mail address is not kept by the page: type it again
    await field(page, 'email_address').fill(uniqueEmail('guest'));
    await field(page, 'email_address').press('Tab');
    await magewire.idle();

    // the old quote takes values for the new attributes
    for (const [code, value] of [[ADDRESS, 'new address value'], [CUSTOMER, 'new customer value']]) {
        await field(page, code).fill(value);
        await field(page, code).press('Tab');
        await magewire.idle();
    }
    const quote = quoteValues('@latest');
    expect(typed(ADDRESS), JSON.stringify(quote)).toBe('new address value');
    expect(quote.customer_attributes[CUSTOMER]).toBe('new customer value');
    expect(typed('e2e_a_req'), 'the old value is still there').toBe('required value');
    expect(quote.customer_attributes.e2e_c_req, 'the old customer value is still there').toBe('required customer value');

    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    for (const address of ['billing', 'shipping'] as const) {
        expect(snapshot[address][ADDRESS]).toMatchObject({ value: 'new address value' });
        expect(snapshot[address].e2e_a_req).toMatchObject({ value: 'required value' });
    }
    expect(snapshot.customer[CUSTOMER]).toMatchObject({ value: 'new customer value' });
    expect(snapshot.customer.e2e_c_req).toMatchObject({ value: 'required customer value' });
});
