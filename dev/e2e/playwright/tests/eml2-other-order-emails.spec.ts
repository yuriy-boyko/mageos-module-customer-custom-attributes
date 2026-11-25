import type { Page } from '@playwright/test';
import { adminOpen, benchCommand, completeAdminOrder, customerEmail, deleteCreated, expect, openAdminOrder, setAccountValues, startAdminOrder, test } from './helpers';

/**
 * EML2: the "Additional Information" section in the invoice, shipment and credit memo emails, and the setting "Add the Section
 * Automatically" (Sales Emails, default Yes). With the setting off the variable `mageos_additional_information_html` is still filled, but
 * nothing is put into the templates. The mails are read from the bench's mail catcher.
 */
const SHOWN = 'e2e_c_eml2on';
const FORMS = ['customer_account_edit'];
const email = customerEmail();

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
    benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]);
    benchCommand('E2E_FIXTURE_CMD', ['set-email-section', 'default']);
});

test.afterEach(() => {
    benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]);
    benchCommand('E2E_FIXTURE_CMD', ['set-email-section', 'default']);
    deleteCreated(SHOWN);
});

const flat = (html: string) => html.replace(/\s+/g, ' ');
const hasSection = (html: string) => /<h3[^>]*>\s*Additional Information\s*<\/h3>/.test(flat(html));

async function sendCopy(page: Page): Promise<void> {
    const box = page.locator('[name$="[send_email]"]').first();
    if ((await box.count()) > 0) await box.check({ force: true });
}

/** Places an admin order, then invoices, ships and refunds it with the e-mail copy; returns the three mails. */
async function documentMails(page: Page): Promise<{ invoice: Mail; shipment: Mail; creditmemo: Mail }> {
    await startAdminOrder(page, email);
    const { incrementId } = await completeAdminOrder(page);
    benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]);

    await page.locator('#order_invoice').click();
    await sendCopy(page);
    await page.locator('button.submit-button:has-text("Submit Invoice")').click();
    await expect(page.locator('.message-success')).toBeVisible();
    const invoice = await waitForMail(email, /invoice/i);

    await openAdminOrder(page, incrementId);
    await page.locator('#order_ship').click();
    await sendCopy(page);
    await page.locator('button.submit-button:has-text("Submit Shipment")').click();
    await expect(page.locator('.message-success')).toBeVisible();
    const shipment = await waitForMail(email, /shipped|shipment/i);

    await openAdminOrder(page, incrementId);
    await page.locator('#order_creditmemo').click();
    await expect(page).toHaveURL(/order_creditmemo\/new/);
    await sendCopy(page);
    await page.locator('button:has-text("Refund Offline")').first().click();
    await expect(page.locator('.message-success')).toBeVisible();
    const creditmemo = await waitForMail(email, /credit memo/i);
    return { invoice, shipment, creditmemo };
}

test('EML2: the invoice, shipment and credit memo emails have the section when the setting is on (the default)', { tag: ['@EML2'] }, async ({ adminPage: page }) => {
    test.setTimeout(600_000);
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${FORMS.join(',')}`, '--show-in-email', SHOWN]);
    setAccountValues(email, { [SHOWN]: 'value for the documents' });

    const mails = await documentMails(page);
    for (const [name, mail] of Object.entries(mails)) {
        expect(hasSection(mail.html), `${name}: the section`).toBe(true);
        expect(flat(mail.html), `${name}: the value`).toContain(`ADM ${SHOWN}: value for the documents`);
        expect(mail.text, `${name}: the plain-text part has the section`).toContain(`ADM ${SHOWN}: value for the documents`);
    }
});

test('EML2: with the setting off the emails have no section', { tag: ['@EML2'] }, async ({ adminPage: page }) => {
    test.setTimeout(600_000);
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${FORMS.join(',')}`, '--show-in-email', SHOWN]);
    setAccountValues(email, { [SHOWN]: 'value for the documents' });
    benchCommand('E2E_FIXTURE_CMD', ['set-email-section', '0']);

    const mails = await documentMails(page);
    for (const [name, mail] of Object.entries(mails)) {
        expect(hasSection(mail.html), `${name}: no section`).toBe(false);
        expect(mail.html, `${name}: no value`).not.toContain('value for the documents');
    }
});

test('EML2: each attribute is in the emails it is set for', { tag: ['@EML2'] }, async ({ adminPage: page }) => {
    test.setTimeout(600_000);
    const FOR_INVOICE = 'e2e_c_eml2inv';
    const FOR_SHIPMENT = 'e2e_c_eml2shp';
    try {
        benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${FORMS.join(',')}`, '--show-in-email=invoice,creditmemo', FOR_INVOICE]);
        benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${FORMS.join(',')}`, '--show-in-email=shipment', FOR_SHIPMENT]);
        setAccountValues(email, { [FOR_INVOICE]: 'only invoice and credit memo', [FOR_SHIPMENT]: 'only shipment' });

        const { invoice, shipment, creditmemo } = await documentMails(page);

        expect(flat(invoice.html)).toContain(`ADM ${FOR_INVOICE}: only invoice and credit memo`);
        expect(invoice.html).not.toContain('only shipment');
        expect(flat(shipment.html)).toContain(`ADM ${FOR_SHIPMENT}: only shipment`);
        expect(shipment.html).not.toContain('only invoice and credit memo');
        expect(flat(creditmemo.html)).toContain(`ADM ${FOR_INVOICE}: only invoice and credit memo`);
        expect(creditmemo.html).not.toContain('only shipment');
    } finally {
        deleteCreated(FOR_INVOICE);
        deleteCreated(FOR_SHIPMENT);
    }
});

test('EML2: the setting is in Stores > Configuration > Sales > Sales Emails and is on by default', { tag: ['@EML2'] }, async ({ adminPage: page }) => {
    await adminOpen(page, 'admin/system_config');
    // the left menu lists the sections under collapsed groups: open the Sales group, then Sales Emails
    await page.locator('.config-nav-block strong', { hasText: /^Sales$/ }).click();
    await page.locator('a[href*="/section/sales_email/"]').first().click();
    // Magento remembers which groups the admin opened: open it only when it is closed
    if (!(await page.locator('#sales_email_mageos_cca_add_section').isVisible())) {
        await page.locator('#sales_email_mageos_cca-head').click();
    }
    await expect(page.locator('#sales_email_mageos_cca_add_section')).toBeVisible();
    await expect(page.locator('#sales_email_mageos_cca_add_section')).toHaveValue('1');
    await expect(page.locator('label[for="sales_email_mageos_cca_add_section"]')).toHaveText('Add the Section Automatically');
    await page.locator('#sales_email_mageos_cca_add_section').scrollIntoViewIfNeeded();
    if (process.env.E2E_SHOT) await page.screenshot({ path: process.env.E2E_SHOT, fullPage: false });
});
