import { benchCommand, createAttributes, deleteCreated, expect, gridFilter, openGrid, test } from './helpers';

/**
 * ADM20: the "Shown in" column of the attribute grid lists the storefront forms of every attribute: the account forms (where the customer
 * types the value) in bold, the other storefront forms in plain text, "+N other" for forms of other modules, "Admin only" when there
 * is none. The admin forms are not listed. Uses attributes the test creates itself (e2e_c_adm20_*, e2e_a_adm20_*).
 */
const CUSTOMER = ['e2e_c_adm20_acc', 'e2e_c_adm20_chk', 'e2e_c_adm20_adm', 'e2e_c_adm20_oth'];
const ADDRESS = ['e2e_a_adm20_book', 'e2e_a_adm20_text'];

test.afterEach(() => {
    [...CUSTOMER, ...ADDRESS].forEach(deleteCreated);
});

/** The text and the bold text of the "Shown in" cell of the row with this code. */
async function cell(page: import('@playwright/test').Page, code: string): Promise<{ text: string; bold: string[] }> {
    const row = page.locator('#mageosCcaAttributeGrid tbody tr', { has: page.locator('td.col-attribute_code', { hasText: new RegExp(`^\\s*${code}\\s*$`) }) });
    const forms = row.locator('td.col-mageos_forms');
    return { text: (await forms.innerText()).trim(), bold: await forms.locator('strong').allInnerTexts() };
}

test('the customer grid shows the storefront forms of every attribute', { tag: ['@ADM20'] }, async ({ adminPage: page }) => {
    createAttributes('customer', [CUSTOMER[0]], ['customer_account_create', 'customer_account_edit']);
    createAttributes('customer', [CUSTOMER[1]], ['customer_account_edit', 'mageos_hyva_checkout'], 'payment_above');
    createAttributes('customer', [CUSTOMER[2]], []);
    createAttributes('customer', [CUSTOMER[3]], ['customer_account_edit']);
    benchCommand('E2E_FIXTURE_CMD', ['add-form', CUSTOMER[3], 'other_module_form']);
    benchCommand('E2E_FIXTURE_CMD', ['add-form', CUSTOMER[3], 'another_module_form']);

    await openGrid(page, 'customer');
    await gridFilter(page, { attribute_code: 'e2e_c_adm20_' });

    const account = await cell(page, CUSTOMER[0]);
    expect(account.text).toBe('Customer Registration, Customer Account Edit');
    expect(account.bold, 'the account forms are bold').toEqual(['Customer Registration', 'Customer Account Edit']);

    const checkout = await cell(page, CUSTOMER[1]);
    expect(checkout.text).toMatch(/^Customer Account Edit, Hyvä Checkout/);
    expect(checkout.bold, 'only the account form is bold').toEqual(['Customer Account Edit']);

    expect((await cell(page, CUSTOMER[2])).text, 'no storefront form').toBe('Admin only');

    const other = await cell(page, CUSTOMER[3]);
    expect(other.text, 'forms of other modules are counted').toBe('Customer Account Edit, +2 other');
});

test('the address grid shows the address forms and the address text', { tag: ['@ADM20'] }, async ({ adminPage: page }) => {
    createAttributes('address', [ADDRESS[0]], ['customer_address_edit', 'mageos_address_text']);
    createAttributes('address', [ADDRESS[1]], ['mageos_address_text']);

    await openGrid(page, 'address');
    await gridFilter(page, { attribute_code: 'e2e_a_adm20_' });

    const book = await cell(page, ADDRESS[0]);
    expect(book.text).toMatch(/^Address Book \(Customer Account\), Address Text/);
    expect(book.bold).toEqual([expect.stringMatching(/^Address Book/)]);

    const text = await cell(page, ADDRESS[1]);
    expect(text.text).toMatch(/^Address Text/);
    expect(text.bold, 'the address text is not a form the customer edits').toEqual([]);
});
