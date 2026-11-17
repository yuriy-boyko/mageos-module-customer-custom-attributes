import type { Page } from '@playwright/test';
import {
    attributeTab, benchCommand, completeAdminOrder, createAttributes, customerEmail, deleteCreated, deleteSavedAddresses, expect, openAdminOrder,
    openAttribute, orderViewAddresses, setAccountValues, setSavedAddress, startAdminOrder, test, openCheckoutAsGuest, requireFixtures, field,
} from './helpers';

/**
 * DSP: the address text. The custom values are added after the standard lines of every address output.
 *  - DSP5, DSP6: the formats html, text, pdf and oneline of an order address (rendered by the shop's own renderer through a bench command),
 *    html escaped, dates without a time, select / multiselect / yes-no shown as labels.
 *  - DSP2: the customer's order page, the admin order, invoice and shipment, the account dashboard and the address book.
 *  - DSP4: an attribute that is not in the "Address Text" form is left out for the saved addresses.
 *  - DSP1: "Show in Forms" of an address attribute lists four forms; without the checkout form the field is not in the checkout.
 */
const email = customerEmail();
const MARKUP = 'Tom & "Jerry" <b>bold</b> <script>window.__cca_dsp=1</script>';
const TYPED = { e2e_a_text: MARKUP, e2e_a_sel: 'South', e2e_a_multi: ['Red', 'Blue'], e2e_a_date: '5/6/2026', e2e_a_bool: 'Yes' };
const LINES = ['E2E Address Select: South', 'E2E Address Multi: Red, Blue', 'E2E Address Yes No: Yes'];

test.beforeEach(() => {
    setAccountValues(email, { e2e_c_text: 'seed text' });
});

test.afterEach(() => {
    deleteSavedAddresses(email);
    deleteCreated('e2e_a_dsp4');
    deleteCreated('e2e_a_dsp1');
});

/** Opens the admin order of the customer with the typed values and returns its number. */
async function placeOrder(page: Page): Promise<string> {
    await startAdminOrder(page, email);
    const { incrementId } = await completeAdminOrder(page, TYPED);
    return incrementId;
}

test('DSP5 and DSP6: html, text, pdf and oneline formats: escaped html, labels and dates', { tag: ['@DSP5', '@DSP6'] }, async ({ adminPage: page }) => {
    const incrementId = await placeOrder(page);
    const formats = JSON.parse(benchCommand('E2E_ASSERT_CMD', ['render-address', incrementId])) as Record<'html' | 'text' | 'pdf' | 'oneline', string>;

    // html: the markup of the value is text, never an element
    expect(formats.html).toContain('Tom &amp; &quot;Jerry&quot; &lt;b&gt;bold&lt;/b&gt; &lt;script&gt;');
    expect(formats.html).not.toContain('<script>');
    expect(formats.html).not.toContain('<b>bold');
    expect(formats.html.indexOf('United States'), 'after the standard lines').toBeLessThan(formats.html.indexOf('E2E Address Select'));

    for (const format of ['html', 'text', 'pdf', 'oneline'] as const) {
        for (const line of LINES) {
            expect(formats[format], `${format}: ${line}`).toContain(line);
        }
        // a date without a time of day
        const date = formats[format].match(/E2E Address Date: ([^\n|<]*\d{4}[^\n|<]*)/);
        expect(date, `${format}: the date line`).not.toBeNull();
        expect(date![1], `${format}: no time in the date`).not.toMatch(/\d{1,2}:\d{2}/);
    }
    expect(formats.oneline, 'oneline is one line').not.toContain('\n');
    expect(formats.pdf, 'pdf lines end with |').toMatch(/E2E Address Select: South\|/);
    expect(formats.text).not.toContain('<br');
});

test('DSP2: customer order page, admin order, invoice and shipment show the lines after the standard ones', { tag: ['@DSP2'] }, async ({ adminPage, customerPage }) => {
    const incrementId = await placeOrder(adminPage);

    // admin order view
    const view = await orderViewAddresses(adminPage);
    for (const line of LINES) {
        expect(view.billing, line).toContain(line);
        expect(view.shipping, line).toContain(line);
    }
    expect(await adminPage.evaluate(() => (window as unknown as { __cca_dsp?: number }).__cca_dsp)).toBeUndefined();

    // the customer's order page
    await customerPage.goto('/sales/order/history/');
    await customerPage.locator('a[href*="sales/order/view"]').first().click();
    const orderText = await customerPage.locator('body').innerText();
    for (const line of LINES) {
        expect(orderText, `customer order page: ${line}`).toContain(line);
    }
    expect(orderText.indexOf('United States')).toBeLessThan(orderText.indexOf(LINES[0]));

    // invoice, then shipment
    await openAdminOrder(adminPage, incrementId);
    await adminPage.locator('#order_invoice').click();
    await adminPage.locator('button.submit-button:has-text("Submit Invoice")').click();
    await expect(adminPage.locator('.message-success')).toBeVisible();
    await adminPage.locator('#sales_invoice_view_tabs, .order-billing-address').first().waitFor();
    expect(await adminPage.locator('.order-billing-address').first().innerText(), 'invoice billing address').toContain(LINES[0]);

    await openAdminOrder(adminPage, incrementId);
    await adminPage.locator('#order_ship').click();
    await adminPage.locator('button.submit-button:has-text("Submit Shipment")').click();
    await expect(adminPage.locator('.message-success')).toBeVisible();
    expect(await adminPage.locator('.order-shipping-address').first().innerText(), 'shipment shipping address').toContain(LINES[0]);
});

test('DSP2 and DSP4: the saved address on the account pages; an attribute outside the Address Text form is left out', { tag: ['@DSP2', '@DSP4'] }, async ({ customerPage: page }) => {
    createAttributes('address', ['e2e_a_dsp4'], ['customer_address_edit']); // not in the Address Text form
    setSavedAddress(email, { e2e_a_sel: 'South', e2e_a_multi: 'Red', e2e_a_dsp4: 'not in the text', e2e_a_req: 'req' });
    for (const url of ['/customer/account/', '/customer/address/']) {
        await page.goto(url);
        const text = await page.locator('main, #maincontent, body').first().innerText();
        expect(text, `${url}: select value`).toContain('E2E Address Select: South');
        expect(text, `${url}: multiselect value`).toContain('E2E Address Multi: Red');
        expect(text, `${url}: not in the Address Text form`).not.toContain('not in the text');
    }
});

test('DSP4: an attribute outside the Address Text form is also left out of the order address text, its value stays in the order rows', { tag: ['@DSP4'] }, async ({ adminPage: page }) => {
    createAttributes('address', ['e2e_a_dsp4'], ['customer_address_edit']); // not in the Address Text form
    await startAdminOrder(page, email);
    const { incrementId } = await completeAdminOrder(page, { ...TYPED, e2e_a_dsp4: 'kept in the rows only' });
    const rows = JSON.parse(benchCommand('E2E_ASSERT_CMD', ['order', incrementId])).addresses.billing as { attribute_code: string; value: string }[];
    expect(rows.find((row) => row.attribute_code === 'e2e_a_dsp4')).toMatchObject({ value: 'kept in the rows only' });
    const formats = JSON.parse(benchCommand('E2E_ASSERT_CMD', ['render-address', incrementId])) as Record<string, string>;
    expect(formats.html).toContain('E2E Address Select: South');
    expect(formats.html, 'the line of the attribute outside the text form').not.toContain('kept in the rows only');
    expect((await orderViewAddresses(page)).billing).not.toContain('kept in the rows only');
});

test('DSP1: Show in Forms lists four forms; without the checkout form the field is not in the checkout', { tag: ['@DSP1'] }, async ({ adminPage, page }) => {
    createAttributes('address', ['e2e_a_dsp1'], ['customer_address_edit', 'mageos_hyva_checkout']);
    await openAttribute(adminPage, 'address', 'e2e_a_dsp1');
    await attributeTab(adminPage, 'Storefront Properties');
    const values = await adminPage.locator('#used_in_forms option').evaluateAll((nodes) => nodes.map((node) => (node as HTMLOptionElement).value));
    expect(values.sort()).toEqual(['customer_address_edit', 'customer_register_address', 'mageos_address_text', 'mageos_hyva_checkout']);

    await openCheckoutAsGuest(page);
    await expect(field(page, 'e2e_a_dsp1'), 'in the checkout form').toBeVisible();
    await requireFixtures(page, ['e2e_a_text']);

    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_a_dsp1', 'required=0', 'forms=customer_address_edit']);
    await page.goto('/checkout/');
    await requireFixtures(page, ['e2e_a_text']);
    await expect(field(page, 'e2e_a_dsp1'), 'removed from the checkout form').toHaveCount(0);
});
