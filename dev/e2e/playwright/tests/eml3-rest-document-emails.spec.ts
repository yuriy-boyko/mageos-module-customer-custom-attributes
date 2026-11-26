import type { APIRequestContext } from '@playwright/test';
import { benchCommand, completeAdminOrder, customerEmail, deleteCreated, expect, setAccountValues, startAdminOrder, test } from './helpers';

/**
 * EML3: the invoice, shipment and credit memo created through REST (POST /V1/order/:id/invoice, /ship and /refund with "notify") have
 * the "Additional Information" section too. Core sends these emails with other classes than the admin screens (the service-contract
 * senders Order\Invoice\Sender\EmailSender and the like), so EML2 does not cover them. With core's "Asynchronous sending" on, the emails
 * wait for the cron jobs, which load the order from a collection and send with the Order\Email\Sender classes: the section is there too.
 * The mails are read from the bench's mail catcher.
 */
const SHOWN = 'e2e_c_eml3rest';
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

async function adminToken(request: APIRequestContext): Promise<string> {
    const response = await request.post('/rest/V1/integration/admin/token', {
        data: { username: process.env.E2E_ADMIN_USER as string, password: process.env.E2E_ADMIN_PASSWORD as string },
    });
    expect(response.ok(), 'admin token').toBe(true);
    return (await response.json()) as string;
}

const flat = (html: string) => html.replace(/\s+/g, ' ');
const hasSection = (html: string) => /<h3[^>]*>\s*Additional Information\s*<\/h3>/.test(flat(html));

test.beforeEach(() => {
    benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]);
    benchCommand('E2E_FIXTURE_CMD', ['set-email-section', 'default']);
});

test.afterEach(() => {
    benchCommand('E2E_FIXTURE_CMD', ['set-async-email', '0']);
    benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]);
    benchCommand('E2E_FIXTURE_CMD', ['set-email-section', 'default']);
    deleteCreated(SHOWN);
});

type Step = [name: string, endpoint: string, data: Record<string, unknown>, subject: RegExp];
const STEPS: Step[] = [
    ['invoice', 'invoice', { capture: false, notify: true }, /invoice/i],
    ['shipment', 'ship', { notify: true }, /shipped|shipment/i],
    ['creditmemo', 'refund', { notify: true }, /credit memo/i],
];

function expectSection(name: string, mail: Mail, value: string): void {
    expect(hasSection(mail.html), `${name}: the section`).toBe(true);
    expect(flat(mail.html), `${name}: the value`).toContain(`ADM ${SHOWN}: ${value}`);
    expect(mail.text, `${name}: the plain-text part`).toContain(`ADM ${SHOWN}: ${value}`);
}

test('EML3: the invoice, shipment and credit memo emails sent through REST have the section', { tag: ['@EML3'] }, async ({ adminPage: page, request }) => {
    test.setTimeout(600_000);
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${FORMS.join(',')}`, '--show-in-email', SHOWN]);
    setAccountValues(email, { [SHOWN]: 'value for the API' });

    await startAdminOrder(page, email);
    const { orderId } = await completeAdminOrder(page);
    benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]);

    const headers = { Authorization: `Bearer ${await adminToken(request)}` };
    for (const [name, endpoint, data, subject] of STEPS) {
        const response = await request.post(`/rest/V1/order/${orderId}/${endpoint}`, { headers, data });
        expect(response.ok(), `${name}: ${await response.text()}`).toBe(true);
        expectSection(name, await waitForMail(email, subject), 'value for the API');
    }
});

test('EML3: with asynchronous sending the emails sent by the cron jobs have the section', { tag: ['@EML3'] }, async ({ adminPage: page, request }) => {
    test.setTimeout(600_000);
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${FORMS.join(',')}`, '--show-in-email', SHOWN]);
    setAccountValues(email, { [SHOWN]: 'value for the cron' });
    benchCommand('E2E_FIXTURE_CMD', ['set-async-email', '1']);

    await startAdminOrder(page, email);
    const { orderId } = await completeAdminOrder(page);
    const headers = { Authorization: `Bearer ${await adminToken(request)}` };
    for (const [name, endpoint, data] of STEPS) {
        const response = await request.post(`/rest/V1/order/${orderId}/${endpoint}`, { headers, data });
        expect(response.ok(), `${name}: ${await response.text()}`).toBe(true);
    }
    expect(mailsTo(email), 'nothing is sent before the cron jobs run').toEqual([]);

    benchCommand('E2E_FIXTURE_CMD', ['send-async-emails']);
    expectSection('order', await waitForMail(email, /order confirmation/i), 'value for the cron');
    for (const [name, , , subject] of STEPS) {
        expectSection(name, await waitForMail(email, subject), 'value for the cron');
    }
});
