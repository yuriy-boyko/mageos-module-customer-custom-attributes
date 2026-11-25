import {
    accountValues, attributeRow, attributeTab, benchCommand, chooseShippingAndPayment, createAttributes, customerEmail, deleteCreated, dropQuotes, expect, fillCheckout,
    field, openAdminOrder, openAttribute, openCheckoutAsGuest, requireFixtures, uniqueEmail, placeOrder, placedOrderSnapshot, saveAttribute, setAccountValues, test, adminMessages,
} from './helpers';

/**
 * EML1: the "Additional Information" section of the new order email (key mageos_email in the validate rules, default off), below the
 * payment and shipping method and above the items. Only the attributes set to "Show in Order Email" appear, with the values saved on the
 * order. The mails are read from the bench's mail catcher (see dsp7-emails-and-pdf.spec.ts).
 *  - the attribute screen stores the setting and refuses it with "Customer Cannot Change This Value" and with "Save on Orders" off;
 *  - a logged-in order: the email has the section with the attribute that is on and not the one that is off;
 *  - an order without such an attribute: the email has no section.
 * Uses attributes the tests create themselves (e2e_c_eml...).
 */
const SHOWN = 'e2e_c_emlon';
const HIDDEN = 'e2e_c_emloff';
const ACCOUNT_FORMS = ['customer_account_edit'];
const email = customerEmail();

type Mail = { id: string; subject: string; text: string; html: string };
const mailsTo = (address: string): Mail[] => JSON.parse(benchCommand('E2E_ASSERT_CMD', ['mail', address]));

async function orderMail(address: string): Promise<Mail> {
    let found: Mail | undefined;
    await expect.poll(() => {
        found = mailsTo(address).find((mail) => /order/i.test(mail.subject));
        return found !== undefined;
    }, { timeout: 40_000, intervals: [2000], message: `an order mail to ${address}` }).toBe(true);
    return found as Mail;
}

test.beforeEach(() => {
    benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]);
});

test.afterEach(() => {
    benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]);
    for (const code of [SHOWN, HIDDEN]) {
        deleteCreated(code);
    }
});

async function placeLoggedInOrder(page: import('@playwright/test').Page, magewire: Parameters<typeof fillCheckout>[1]) {
    dropQuotes(email);
    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire);
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    return placedOrderSnapshot(page);
}

test('EML1: the attribute screen stores "Show in Order Email" and refuses the combinations that cannot work', { tag: ['@EML1'] }, async ({ adminPage: page }) => {
    createAttributes('customer', [SHOWN], ACCOUNT_FORMS);
    expect(attributeRow('customer', SHOWN).rules).not.toHaveProperty('mageos_email');

    await openAttribute(page, 'customer', SHOWN);
    await attributeTab(page, 'Storefront Properties');
    await page.locator('#show_in_email').selectOption('1');
    await page.locator('#save_on_order').selectOption('0');
    await saveAttribute(page, true);
    expect(await adminMessages(page), 'needs Save on Orders').toContain('needs Save on Orders');

    await attributeTab(page, 'Storefront Properties');
    await page.locator('#save_on_order').selectOption('1');
    await page.locator('#admin_only').selectOption('1');
    await saveAttribute(page, true);
    expect(await adminMessages(page), 'not with admin-only').toContain('internal value');
    expect(attributeRow('customer', SHOWN).rules, 'nothing was saved').not.toHaveProperty('mageos_email');

    await attributeTab(page, 'Storefront Properties');
    await page.locator('#admin_only').selectOption('0');
    await saveAttribute(page, true);
    await expect(page.locator('.message-success')).toBeVisible();
    expect(attributeRow('customer', SHOWN).rules).toMatchObject({ mageos_email: 1 });
});

test('EML1: the new order email has the section below the methods, with the attribute that is on only', { tag: ['@EML1'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${ACCOUNT_FORMS.join(',')}`, '--show-in-email', SHOWN]);
    createAttributes('customer', [HIDDEN], ACCOUNT_FORMS);
    setAccountValues(email, { [SHOWN]: 'value for the customer <b>&</b>', [HIDDEN]: 'internal value' });

    await placeLoggedInOrder(page, magewire);

    const mail = await orderMail(email);
    const html = mail.html.replace(/\s+/g, ' ');
    expect(html, 'the section and its attribute').toMatch(/<h3[^>]*>\s*Additional Information\s*<\/h3>/);
    expect(html).toContain(`ADM ${SHOWN}: value for the customer &lt;b&gt;&amp;&lt;/b&gt;`);
    expect(html, 'an attribute that is off is not in the email').not.toContain('internal value');
    expect(html, 'below the payment and shipping method, above the items').toMatch(/Payment Method[\s\S]*Shipping Method[\s\S]*Additional Information[\s\S]*(Subtotal|Grand Total)/);
    expect(mail.text === '' || mail.text.includes(`ADM ${SHOWN}`), 'the text part, when there is one').toBe(true);
});

test('EML1: without an attribute set to show in the email there is no section', { tag: ['@EML1'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    createAttributes('customer', [HIDDEN], ACCOUNT_FORMS);
    setAccountValues(email, { [HIDDEN]: 'internal value' });

    await placeLoggedInOrder(page, magewire);

    const mail = await orderMail(email);
    expect(mail.html).not.toContain('Additional Information');
    expect(mail.html).not.toContain('internal value');
});

test('EML1: an email sent again from the admin order has the section too', { tag: ['@EML1'] }, async ({ customerPage: page, customerMagewire: magewire, adminPage }) => {
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${ACCOUNT_FORMS.join(',')}`, '--show-in-email', SHOWN]);
    createAttributes('customer', [HIDDEN], ACCOUNT_FORMS);
    setAccountValues(email, { [SHOWN]: 'value for the resend', [HIDDEN]: 'internal value' });

    const { incrementId } = await placeLoggedInOrder(page, magewire);
    await orderMail(email);
    benchCommand('E2E_ASSERT_CMD', ['mail-delete', email]);

    // the order is loaded again here: the rows come from the saved snapshot
    await openAdminOrder(adminPage, incrementId);
    await adminPage.locator('#send_notification').click();
    await adminPage.locator('.modal-popup._show .action-accept').click();
    await expect(adminPage.locator('.message-success')).toBeVisible();

    const html = (await orderMail(email)).html.replace(/\s+/g, ' ');
    expect(html).toMatch(/<h3[^>]*>\s*Additional Information\s*<\/h3>/);
    expect(html).toContain(`ADM ${SHOWN}: value for the resend`);
    expect(html).not.toContain('internal value');
});

test('EML1: a guest order has the value typed in the checkout in the email, and nothing is saved to an account', { tag: ['@EML1', '@ACC1'] }, async ({ page, magewire }) => {
    const guest = uniqueEmail('guestmail');
    const forms = ['customer_account_edit', 'mageos_hyva_checkout'];
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${forms.join(',')}`, '--position=payment_above', '--show-in-email', '--save-to-account', SHOWN]);
    const before = accountValues(email).customer_attributes;
    try {
        await openCheckoutAsGuest(page);
        await requireFixtures(page, [SHOWN]);
        await fillCheckout(page, magewire, { email_address: guest });
        await field(page, SHOWN).fill('guest value <i>');
        await field(page, SHOWN).press('Tab');
        await magewire.idle();
        await chooseShippingAndPayment(page, magewire);
        await placeOrder(page);
        await placedOrderSnapshot(page);

        const html = (await orderMail(guest)).html.replace(/\s+/g, ' ');
        expect(html).toMatch(/<h3[^>]*>\s*Additional Information\s*<\/h3>/);
        expect(html).toContain(`ADM ${SHOWN}: guest value &lt;i&gt;`);
        expect(accountValues(email).customer_attributes, 'a guest order changes no account').toEqual(before);
    } finally {
        benchCommand('E2E_ASSERT_CMD', ['mail-delete', guest]);
    }
});
