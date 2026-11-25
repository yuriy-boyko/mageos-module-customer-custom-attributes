import {
    accountValues, attributeRow, attributeTab, benchCommand, createAttributes, customerEmail, deleteCreated, expect, openAttribute, saveAttribute,
    setAccountValues, test, uniqueEmail, adminMessages,
} from './helpers';

/**
 * SEC1: the setting "Customer Cannot Change This Value" of a customer attribute (key mageos_admin_only in the validate rules, default
 * off). Core accepts every user-defined custom attribute that a customer sends, in the account forms and in PUT /V1/customers/me.
 *  - the attribute screen stores the setting, and refuses it together with "required" in a form the customer fills in;
 *  - the account edit page does not print the attribute, and saving the form keeps its value;
 *  - the API with a customer token or without a token cannot set it, the admin token can; an ordinary attribute is saved as before.
 * Uses attributes the tests create themselves (e2e_c_sec...).
 */
const INTERNAL = 'e2e_c_secint';
const ORDINARY = 'e2e_c_secpub';
const FORMS = ['customer_account_create', 'customer_account_edit'];

test.afterEach(() => {
    for (const code of [INTERNAL, ORDINARY]) {
        deleteCreated(code);
    }
});

const customerAttributes = (email: string): Record<string, string> => accountValues(email).customer_attributes;

async function token(request: import('@playwright/test').APIRequestContext, kind: 'customer' | 'admin'): Promise<string> {
    const credentials = kind === 'customer'
        ? { username: customerEmail(), password: process.env.E2E_CUSTOMER_PASSWORD as string }
        : { username: process.env.E2E_ADMIN_USER as string, password: process.env.E2E_ADMIN_PASSWORD as string };
    const response = await request.post(`/rest/V1/integration/${kind}/token`, { data: credentials });
    expect(response.ok(), `${kind} token`).toBe(true);
    return (await response.json()) as string;
}

const codeValue = (attributes: Array<{ attribute_code: string; value: unknown }> | undefined, code: string) => attributes?.find((a) => a.attribute_code === code)?.value;

test('SEC1: the attribute screen stores the setting and refuses it together with "required" in the account forms', { tag: ['@SEC1'] }, async ({ adminPage: page }) => {
    createAttributes('customer', [INTERNAL], FORMS);
    expect(attributeRow('customer', INTERNAL).rules).not.toHaveProperty('mageos_admin_only');

    await openAttribute(page, 'customer', INTERNAL);
    await page.locator('#is_required').selectOption('1');
    await attributeTab(page, 'Storefront Properties');
    await page.locator('#admin_only').selectOption('1');
    await saveAttribute(page, true);
    expect(await adminMessages(page), 'refused').toContain('cannot be required in the registration or account forms');
    expect(attributeRow('customer', INTERNAL).rules, 'nothing was saved').not.toHaveProperty('mageos_admin_only');

    // optional: saved
    await attributeTab(page, 'Properties');
    await page.locator('#is_required').selectOption('0');
    await attributeTab(page, 'Storefront Properties');
    await page.locator('#admin_only').selectOption('1');
    await saveAttribute(page, true);
    await expect(page.locator('.message-success')).toBeVisible();
    expect(attributeRow('customer', INTERNAL).rules).toMatchObject({ mageos_admin_only: 1 });
    await attributeTab(page, 'Storefront Properties');
    await expect(page.locator('#admin_only'), 'the screen shows what was saved').toHaveValue('1');
});

test('SEC1: the account edit page does not print the attribute and saving the form keeps its value', { tag: ['@SEC1'] }, async ({ customerPage: page }) => {
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${FORMS.join(',')}`, '--admin-only', INTERNAL]);
    createAttributes('customer', [ORDINARY], FORMS);
    setAccountValues(customerEmail(), { [INTERNAL]: 'set by admin', [ORDINARY]: 'old' });

    await page.goto('/customer/account/edit/');
    await expect(page.locator(`[name="${ORDINARY}"]`), 'an ordinary attribute is printed').toBeVisible();
    await expect(page.locator(`[name="${INTERNAL}"]`), 'an admin-only attribute is not').toHaveCount(0);

    await page.locator(`[name="${ORDINARY}"]`).fill('typed by the customer');
    // a hand-made post carries the value anyway: the field is added to the form
    await page.evaluate((code) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = code;
        input.value = 'forced by the customer';
        document.querySelector('form[action*="editPost"]')?.appendChild(input);
    }, INTERNAL);
    await page.locator('form[action*="editPost"] button[type="submit"]').click();
    await expect(page.locator('.message-success, [role="alert"]').first()).toBeVisible();

    const values = customerAttributes(customerEmail());
    expect(values[ORDINARY], 'the ordinary attribute is saved').toBe('typed by the customer');
    expect(values[INTERNAL], 'the admin-only value is kept').toBe('set by admin');
});

test('SEC1: the API with a customer token or without a token cannot set it, the admin token can', { tag: ['@SEC1'] }, async ({ request }) => {
    test.skip(!process.env.E2E_CUSTOMER_PASSWORD || !process.env.E2E_ADMIN_USER, 'the customer and admin credentials are not set');
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${FORMS.join(',')}`, '--admin-only', INTERNAL]);
    createAttributes('customer', [ORDINARY], FORMS);
    setAccountValues(customerEmail(), { [INTERNAL]: 'set by admin', [ORDINARY]: 'old' });

    // PUT /V1/customers/me with a customer token
    const customerToken = await token(request, 'customer');
    const headers = { Authorization: `Bearer ${customerToken}` };
    const me = await (await request.get('/rest/V1/customers/me', { headers })).json();
    const sent = { ...me, custom_attributes: [
        ...(me.custom_attributes ?? []).filter((a: { attribute_code: string }) => ![INTERNAL, ORDINARY].includes(a.attribute_code)),
        { attribute_code: INTERNAL, value: 'hacked' },
        { attribute_code: ORDINARY, value: 'changed by the customer' },
    ] };
    const put = await request.put('/rest/V1/customers/me', { headers, data: { customer: sent } });
    expect(put.status(), await put.text()).toBe(200);
    let values = customerAttributes(customerEmail());
    expect(values[INTERNAL], 'the customer token cannot change it').toBe('set by admin');
    expect(values[ORDINARY], 'an ordinary attribute is saved as before').toBe('changed by the customer');

    // POST /V1/customers without a token (registration through the API)
    const email = uniqueEmail('sec1api');
    const created = await request.post('/rest/V1/customers', { data: {
        customer: { email, firstname: 'Api', lastname: 'Visitor', custom_attributes: [
            { attribute_code: INTERNAL, value: 'visitor value' }, { attribute_code: ORDINARY, value: 'visitor public' },
            // the fixture attributes the account forms require
            { attribute_code: 'e2e_c_areq', value: 'required' }, { attribute_code: 'e2e_c_req', value: 'required' },
        ] },
        password: process.env.E2E_CUSTOMER_PASSWORD,
    } });
    expect(created.status(), await created.text()).toBe(200);
    const body = await created.json();
    expect(codeValue(body.custom_attributes, INTERNAL) ?? null, 'a visitor cannot set it').toBeNull();
    values = customerAttributes(email);
    expect(values[INTERNAL] ?? null).toBeNull();
    expect(values[ORDINARY]).toBe('visitor public');

    // the admin token changes it
    const adminHeaders = { Authorization: `Bearer ${await token(request, 'admin')}` };
    const stored = await (await request.get(`/rest/V1/customers/${me.id}`, { headers: adminHeaders })).json();
    const adminSent = { ...stored, custom_attributes: [
        ...(stored.custom_attributes ?? []).filter((a: { attribute_code: string }) => a.attribute_code !== INTERNAL),
        { attribute_code: INTERNAL, value: 'changed by the admin' },
    ] };
    const adminPut = await request.put(`/rest/V1/customers/${me.id}`, { headers: adminHeaders, data: { customer: adminSent } });
    expect(adminPut.status(), await adminPut.text()).toBe(200);
    values = customerAttributes(customerEmail());
    expect(values[INTERNAL], 'an admin token writes freely').toBe('changed by the admin');
});
