import type { Page } from '@playwright/test';
import * as fs from 'fs';
import {
    benchCommand, chooseShippingAndPayment, completeAdminOrder, customerEmail, deleteSavedAddresses, expect, field, fillCheckout, openAdminOrder, openCheckoutAsGuest,
    placeOrder, placedOrderSnapshot, setAccountValues, startAdminOrder, test, uniqueEmail,
} from './helpers';
import { pdfLines } from './pdf-text';

/**
 * DSP7: the order e-mail, the invoice, shipment and credit memo e-mails and PDF files, and the credit memo page show the custom lines after
 * the address. The mails are read from the bench's mail catcher (Mailpit) with `assertions.php mail`, the PDF files are downloaded from the
 * admin Print buttons and read by pdf-text.ts. The mails of the test are deleted afterwards.
 */
const email = customerEmail();
const MARKUP = 'Tom & "Jerry" <b>bold</b>';
const TYPED = { e2e_a_text: MARKUP, e2e_a_sel: 'South', e2e_a_multi: ['Red', 'Blue'], e2e_a_date: '5/6/2026', e2e_a_bool: 'Yes' };
const LINES = ['E2E Address Select: South', 'E2E Address Multi: Red, Blue', 'E2E Address Yes No: Yes'];

type Mail = { id: string; subject: string; text: string; html: string };
const mailsTo = (address: string): Mail[] => JSON.parse(benchCommand('E2E_ASSERT_CMD', ['mail', address]));

async function waitForMail(address: string, subject: RegExp): Promise<Mail> {
    let found: Mail | undefined;
    await expect.poll(() => {
        found = mailsTo(address).find((mail) => subject.test(mail.subject));
        return found !== undefined;
    }, { timeout: 40_000, intervals: [2000], message: `a mail "${subject}" to ${address}` }).toBe(true);
    return found as Mail;
}

test.beforeEach(() => {
    setAccountValues(email, { e2e_c_text: 'seed text' });
    benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]);
});

test.afterEach(() => {
    benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]);
    deleteSavedAddresses(email);
});

/** The lines are in the mail after the standard address lines, and the markup of a value is escaped in the html part. */
function expectAddressLines(mail: Mail, where: string): void {
    fs.writeFileSync(`/tmp/dsp7-${where.replace(/\W+/g, '-')}.html`, mail.html); // looked at when a check fails; removed by the test run's owner
    for (const line of LINES) {
        // Magento sends an html mail; a text part, when the catcher shows one, has the lines too
        expect(mail.html, `${where}: ${line} (html part of "${mail.subject}": ${mail.html.replace(/\s+/g, ' ').slice(mail.html.indexOf('United States') - 200, mail.html.indexOf('United States') + 300)})`).toContain(line);
        if (mail.text.trim() !== '') expect(mail.text, `${where}: ${line} (text part)`).toContain(line);
    }
    expect(mail.html, `${where}: the standard lines come first`).toMatch(/United States[\s\S]*E2E Address Select: South/);
    expect(mail.html, `${where}: escaped markup`).toMatch(/Tom &amp; (&quot;|")Jerry(&quot;|") &lt;b&gt;bold&lt;\/b&gt;/);
    expect(mail.html, `${where}: no element made from a value`).not.toContain('<b>bold</b>');
}

/** From the order view: the tab with the documents (Invoices, Shipments, Credit Memos), then the first document of the grid. */
async function openDocument(page: Page, tab: 'Invoices' | 'Shipments' | 'Credit Memos'): Promise<void> {
    await page.locator('#sales_order_view_tabs a').filter({ hasText: new RegExp(`^\\s*${tab}`) }).first().click();
    const target = { Invoices: 'order_invoice', Shipments: 'order_shipment', 'Credit Memos': 'order_creditmemo' }[tab];
    const row = page.locator(`a[href*="${target}/view"]`).filter({ visible: true }).first();
    await row.waitFor({ state: 'visible', timeout: 20_000 });
    await row.click();
    await expect(page).toHaveURL(/order_(invoice|shipment|creditmemo)\/view/);
}

/**
 * The PDF of an admin document page. The Print button is clicked once the page scripts have run; the browser starts a download of the PDF.
 * Its address (with the secret key) is taken from the download event and the file is requested again with the admin session, because the
 * downloaded file itself is not handed to the test in a way that is stable across browsers.
 */
async function printedLines(page: Page): Promise<string[]> {
    await page.waitForLoadState('networkidle');
    const buttons = page.locator('button:has-text("Print"), a:has-text("Print")').filter({ visible: true });
    await buttons.first().waitFor({ state: 'visible', timeout: 15_000 });
    const [download] = await Promise.all([
        page.waitForEvent('download', { timeout: 30_000 }),
        buttons.first().click({ noWaitAfter: true, timeout: 15_000 }),
    ]);
    const response = await page.context().request.get(download.url());
    expect(response.status(), 'the print address answers').toBe(200);
    const body = await response.body();
    expect(body.subarray(0, 5).toString('latin1'), 'a PDF file').toBe('%PDF-');
    return pdfLines(body);
}

function expectPdf(lines: string[], where: string): void {
    expect(lines.length, `${where}: the PDF has text`).toBeGreaterThan(5);
    const text = lines.join('\n');
    for (const line of LINES) {
        expect(text, `${where}: ${line}`).toContain(line);
    }
    expect(text.indexOf('United States'), `${where}: after the standard lines`).toBeLessThan(text.indexOf(LINES[0]));
}

test('DSP7: the order confirmation e-mail of a checkout order shows the lines after the address', { tag: ['@DSP7'] }, async ({ page, magewire }) => {
    const guest = uniqueEmail('dsp7');
    try {
        await openCheckoutAsGuest(page);
        await fillCheckout(page, magewire, { email_address: guest, e2e_a_text: MARKUP });
        await page.locator('label:has(input[data-form="shipping"][name^="e2e_a_sel"])').filter({ hasText: /^\s*South\s*$/ }).first().locator('input').check();
        await magewire.idle();
        await field(page, 'e2e_a_bool').selectOption({ label: 'Yes' });
        await magewire.idle();
        await field(page, 'e2e_a_multi').selectOption([{ label: 'Red' }, { label: 'Blue' }]);
        await magewire.idle();
        await chooseShippingAndPayment(page, magewire);
        await placeOrder(page);
        await placedOrderSnapshot(page);
        const mail = await waitForMail(guest, /order/i);
        expectAddressLines(mail, 'order e-mail');
    } finally {
        benchCommand('E2E_ASSERT_CMD', ['mail-delete', guest]);
    }
});

test('DSP7: invoice, shipment and credit memo: e-mails, PDF files and pages show the lines after the address', { tag: ['@DSP7'] }, async ({ adminPage: page }) => {
    test.setTimeout(600_000);
    await startAdminOrder(page, email);
    const { incrementId } = await completeAdminOrder(page, TYPED);
    benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]); // the order e-mail of this order is not the subject here

    const sendCopy = async () => {
        const box = page.locator('[name$="[send_email]"]').first();
        if ((await box.count()) > 0) await box.check({ force: true });
    };

    // invoice
    await page.locator('#order_invoice').click();
    await sendCopy();
    await page.locator('button.submit-button:has-text("Submit Invoice")').click();
    await expect(page.locator('.message-success')).toBeVisible();
    await openDocument(page, 'Invoices');
    expect(await page.locator('.order-billing-address').first().innerText(), 'invoice page').toContain(LINES[0]);
    expectPdf(await printedLines(page), 'invoice PDF');
    expectAddressLines(await waitForMail(email, /invoice/i), 'invoice e-mail');

    // shipment
    await openAdminOrder(page, incrementId);
    await page.locator('#order_ship').click();
    await sendCopy();
    await page.locator('button.submit-button:has-text("Submit Shipment")').click();
    await expect(page.locator('.message-success')).toBeVisible();
    await openDocument(page, 'Shipments');
    expect(await page.locator('.order-shipping-address').first().innerText(), 'shipment page').toContain(LINES[0]);
    expectPdf(await printedLines(page), 'shipment PDF');
    expectAddressLines(await waitForMail(email, /shipped|shipment/i), 'shipment e-mail');

    // credit memo: the new credit memo page, then the created one
    await openAdminOrder(page, incrementId);
    await page.locator('#order_creditmemo').click();
    await expect(page).toHaveURL(/order_creditmemo\/new/);
    expect(await page.locator('.order-billing-address').first().innerText(), 'new credit memo page').toContain(LINES[0]);
    await sendCopy();
    await page.locator('button:has-text("Refund Offline")').first().click();
    await expect(page.locator('.message-success')).toBeVisible();
    await openDocument(page, 'Credit Memos');
    expect(await page.locator('.order-billing-address').first().innerText(), 'credit memo page').toContain(LINES[0]);
    expectPdf(await printedLines(page), 'credit memo PDF');
    expectAddressLines(await waitForMail(email, /credit memo/i), 'credit memo e-mail');
});
