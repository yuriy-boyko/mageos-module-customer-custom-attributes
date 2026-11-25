import { benchCommand, customerEmail, deleteCreated, createAttributes, expect, openGrid, setAccountValues, test } from './helpers';

/**
 * SEC2 (and the grid warning): can a storefront or API caller put customer_<code> data on a quote, so that the order snapshot takes it instead of the value the
 * customer has? The snapshot code (QuoteSubmitBefore) reads `customer_<code>` from the quote first, for the admin order create form.
 * Probe, with the customer token of the REST API: PUT /V1/carts/mine saves the quote the caller sends (CartRepositoryInterface::save).
 * `quote-snapshot` runs the snapshot code on the stored quote, as the order placement would, without placing an order.
 */
const ORDINARY = 'e2e_c_secq';
const COLLIDING = 'note'; // the quote has a customer_note column (a field of the cart API); the attribute is made like a data patch does

test.afterEach(() => {
    deleteCreated(ORDINARY);
    deleteCreated(COLLIDING);
});

async function cartOf(request: import('@playwright/test').APIRequestContext, quote: Record<string, unknown>): Promise<{ id: number; status: number; body: string }> {
    const login = await request.post('/rest/V1/integration/customer/token', { data: { username: customerEmail(), password: process.env.E2E_CUSTOMER_PASSWORD } });
    expect(login.ok(), 'customer token').toBe(true);
    const headers = { Authorization: `Bearer ${await login.json()}` };
    const created = await request.post('/rest/V1/carts/mine', { headers });
    expect(created.ok(), 'cart').toBe(true);
    const id = Number(await created.json());
    const put = await request.put('/rest/V1/carts/mine', { headers, data: { quote } });
    return { id, status: put.status(), body: await put.text() };
}

// the snapshot code as a cart API call places the order (the area of the REST API)
const snapshot = (quoteId: number): { values: Record<string, string>; customer_note: string | null } => JSON.parse(benchCommand('E2E_FIXTURE_CMD', ['quote-snapshot', String(quoteId), 'webapi_rest']));

test('SEC2: customer data sent with the cart does not replace the value the customer has', { tag: ['@SEC2'] }, async ({ request }) => {
    test.skip(!process.env.E2E_CUSTOMER_PASSWORD, 'E2E_CUSTOMER_PASSWORD is not set');
    createAttributes('customer', [ORDINARY], ['customer_account_edit']);
    setAccountValues(customerEmail(), { [ORDINARY]: 'account value' });

    const cart = await cartOf(request, {
        customer: { custom_attributes: [{ attribute_code: ORDINARY, value: 'injected through the cart' }] },
        [`customer_${ORDINARY}`]: 'injected as a quote field',
        extension_attributes: {},
    });
    expect(cart.status, cart.body).toBeLessThan(500);

    expect(snapshot(cart.id).values[ORDINARY], 'the snapshot takes the account value').toBe('account value');
});

// The cart API lets the customer set customer_note, and an attribute whose code is "note" (made like a data patch does: the admin form
// refuses reserved codes) collides with that quote field. The module reads customer_<code> from the quote in the admin area only.
test('SEC2: an attribute whose code is a quote column (note) does not take the value the customer typed in the cart', { tag: ['@SEC2'] }, async ({ request }) => {
    test.skip(!process.env.E2E_CUSTOMER_PASSWORD, 'E2E_CUSTOMER_PASSWORD is not set');
    benchCommand('E2E_FIXTURE_CMD', ['create-raw-attribute', 'customer', COLLIDING]);

    const cart = await cartOf(request, { customer_note: 'typed by the shopper in the cart' });
    expect(cart.status, cart.body).toBeLessThan(500);

    const result = snapshot(cart.id);
    expect(result.customer_note, 'the customer can set the cart note').toBe('typed by the shopper in the cart');
    expect(result.values[COLLIDING], 'no row takes the shopper\'s note').toBeUndefined();
});

test('SEC2: the customer grid warns about an attribute with a reserved code', { tag: ['@SEC2'] }, async ({ adminPage: page }) => {
    const warning = page.locator('.message-warning', { hasText: /code of these attributes is reserved/i });
    await openGrid(page, 'customer');
    await expect(warning, 'no warning without such an attribute').toHaveCount(0);

    benchCommand('E2E_FIXTURE_CMD', ['create-raw-attribute', 'customer', COLLIDING]);
    await openGrid(page, 'customer');
    await expect(warning).toContainText(/: note\./);
});
