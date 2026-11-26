import type { APIRequestContext, Page } from '@playwright/test';
import {
    addAdminOrderProduct, adminOrderIdle, benchCommand, chooseAdminPayment, chooseAdminShipping, createWebsiteCustomer, deleteCreated, expect, fillOrderValues,
    setAccountValues, startAdminOrder, submitAdminOrder, test, uniqueEmail,
} from './helpers';

/**
 * EML4: the heading of the "Additional Information" section is in the language of the order's store, also when the email is sent from
 * a request in another language (the admin, REST, the cron jobs). The email event fires before core switches to the store for the
 * template, so the module builds the section in the order's store itself. The Italian heading comes from a database translation of the locale
 * it_IT (removed afterwards; the bench ships no Italian language pack). It is saved for all store views: in the admin area core reads
 * only those, also for the email templates, so a store view row would not reach an email sent from the admin. Theme and language pack
 * translations follow the store's theme and locale.
 */
const SHOWN = 'e2e_c_eml4lang';
const FORMS = ['customer_account_edit'];
const HEADING_IT = 'Informazioni Aggiuntive';

type Mail = { id: string; subject: string; text: string; html: string };
const mailsTo = (address: string): Mail[] => JSON.parse(benchCommand('E2E_ASSERT_CMD', ['mail', address]));

async function waitForAnyMail(address: string): Promise<Mail> {
    let found: Mail | undefined;
    await expect.poll(() => {
        found = mailsTo(address)[0];
        return found !== undefined;
    }, { timeout: 40_000, intervals: [2000], message: `a mail to ${address}` }).toBe(true);
    return found as Mail;
}

async function adminToken(request: APIRequestContext): Promise<string> {
    const response = await request.post('/rest/V1/integration/admin/token', {
        data: { username: process.env.E2E_ADMIN_USER as string, password: process.env.E2E_ADMIN_PASSWORD as string },
    });
    expect(response.ok(), 'admin token').toBe(true);
    return (await response.json()) as string;
}

const flat = (html: string) => html.replace(/\s+/g, ' ');
const heading = (html: string): string => (flat(html).match(/<h3[^>]*>\s*([^<]*?)\s*<\/h3>/g) ?? [])
    .map((h) => h.replace(/<[^>]+>/g, '').trim()).find((h) => h === HEADING_IT || h === 'Additional Information') ?? '';

/** An admin order of the ITA store view for a customer of the Italy website with a value of the attribute; returns the order id. */
async function italianOrder(page: Page, email: string): Promise<string> {
    createWebsiteCustomer(email, 2);
    setAccountValues(email, { [SHOWN]: 'valore per le email' });
    benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]); // a mail of the new account is not one of these
    await startAdminOrder(page, email, 'ITA');
    await addAdminOrderProduct(page, '24-MB04');
    const id = (field: string) => page.locator(`#order-billing_address_${field}`);
    await id('firstname').fill('Maria');
    await id('lastname').fill('Rossi');
    await id('street0').fill('Via Roma 1');
    await id('city').fill('Roma');
    await id('country_id').selectOption('IT');
    await adminOrderIdle(page);
    await id('region_id').selectOption({ label: 'Roma' });
    await id('postcode').fill('00100');
    await id('telephone').fill('0612345678');
    await adminOrderIdle(page);
    await fillOrderValues(page, 'billing_address', { e2e_a_req: 'required text', e2e_a_rdep: 'required dep' });
    await chooseAdminShipping(page);
    await chooseAdminPayment(page);
    const { incrementId, orderId } = await submitAdminOrder(page);
    expect(incrementId.startsWith('2'), 'an order of the Italy store').toBe(true);
    return orderId;
}

function expectItalian(name: string, mail: Mail): void {
    expect(heading(mail.html), `${name}: the heading in the store's language`).toBe(HEADING_IT);
    expect(flat(mail.html), `${name}: the value`).toContain('valore per le email');
    expect(mail.text, `${name}: the plain-text part`).toContain(HEADING_IT);
}

let email = '';

test.beforeEach(() => {
    email = uniqueEmail('eml4');
    benchCommand('E2E_FIXTURE_CMD', ['set-email-section', 'default']);
    benchCommand('E2E_FIXTURE_CMD', ['set-translation', 'it_IT', 'Additional Information', HEADING_IT]);
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${FORMS.join(',')}`, '--show-in-email', SHOWN]);
});

test.afterEach(() => {
    benchCommand('E2E_FIXTURE_CMD', ['set-async-email', '0']);
    benchCommand('E2E_FIXTURE_CMD', ['set-translation', 'it_IT', 'Additional Information', '--delete']);
    benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]);
    deleteCreated(SHOWN);
});

test('EML4: emails of an Italian store order sent from the admin request and through REST have the heading in Italian', { tag: ['@EML4'] }, async ({ adminPage: page, request }) => {
    test.setTimeout(600_000);
    const orderId = await italianOrder(page, email);
    // the new order email, sent by the admin request (the admin works in English)
    expectItalian('order', await waitForAnyMail(email));

    const headers = { Authorization: `Bearer ${await adminToken(request)}` };
    for (const [name, endpoint, data] of [
        ['invoice', 'invoice', { capture: false, notify: true }],
        ['shipment', 'ship', { notify: true }],
        ['creditmemo', 'refund', { notify: true }],
    ] as const) {
        benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]);
        const response = await request.post(`/rest/V1/order/${orderId}/${endpoint}`, { headers, data });
        expect(response.ok(), `${name}: ${await response.text()}`).toBe(true);
        expectItalian(name, await waitForAnyMail(email));
    }
});

test('EML4: with asynchronous sending the cron jobs send the heading in Italian', { tag: ['@EML4'] }, async ({ adminPage: page, request }) => {
    test.setTimeout(600_000);
    benchCommand('E2E_FIXTURE_CMD', ['set-async-email', '1']);
    const orderId = await italianOrder(page, email);
    const response = await request.post(`/rest/V1/order/${orderId}/invoice`, {
        headers: { Authorization: `Bearer ${await adminToken(request)}` }, data: { capture: false, notify: true },
    });
    expect(response.ok(), await response.text()).toBe(true);
    expect(mailsTo(email), 'nothing is sent before the cron jobs run').toEqual([]);

    benchCommand('E2E_FIXTURE_CMD', ['send-async-emails']);
    await expect.poll(() => mailsTo(email).length, { timeout: 40_000, intervals: [2000], message: 'the order and the invoice email' }).toBe(2);
    for (const mail of mailsTo(email)) {
        expectItalian(mail.subject, mail);
    }
});
