import type { Page } from '@playwright/test';
import {
    accountValues, benchCommand, chooseShippingAndPayment, customerEmail, deleteSavedAddresses, dropQuotes, expect, field, fillCheckout, loginAs,
    openCheckoutAsGuest, placeOrder, placedOrderSnapshot, quoteValues, setAccountValues, setSavedAddress, test, uniqueEmail,
} from './helpers';

/**
 * SAV6: a saved address missing several required fields, several incomplete addresses, billing different from shipping. SAV8: the open tab keeps the old notice.
 * CCO11: a customer attribute limited to the Wholesale group (e2e_c_ws) is hidden for a General customer and shown to a Wholesale one.
 * CCO14: changing the postcode and the shipping method keeps every typed value.
 * RAD3: a checkbox group as the parent of a rule (e2e_a_chk "Two" shows e2e_a_cdep); the radio parent is in DEP10.
 */
const email = customerEmail();
const ALL_FORMS = 'customer_address_edit,customer_register_address,mageos_hyva_checkout,mageos_address_text';
const NOTICE = '.mageos-cca-address-notice';

test.beforeEach(() => {
    dropQuotes(email);
    deleteSavedAddresses(email);
    setAccountValues(email, { e2e_c_text: 'account text', e2e_c_req: 'account required', e2e_c_sel: '', e2e_c_ws: '' });
});

test.afterEach(() => {
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_a_warn', 'required=0', `forms=${ALL_FORMS}`]);
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_a_text', 'required=0', `forms=${ALL_FORMS}`]);
    benchCommand('E2E_FIXTURE_CMD', ['set-customer-group', email, '1']);
    setAccountValues(email, { e2e_c_ws: '' });
    deleteSavedAddresses(email);
});

async function typeCustomerRequired(page: Page, magewire: { idle(): Promise<void> }): Promise<void> {
    const input = field(page, 'e2e_c_req');
    if ((await input.count()) > 0 && (await input.inputValue()) === '') {
        await input.fill('required customer value');
        await input.press('Tab');
        await magewire.idle();
    }
}

test('SAV6: several missing fields give the general notice; two incomplete addresses each have their own link', { tag: ['@SAV6'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    setSavedAddress(email, { e2e_a_req: 'first' });
    setSavedAddress(email, { e2e_a_req: 'second' }, true);
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_a_warn', 'required=1', `forms=${ALL_FORMS}`]);
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_a_text', 'required=1', `forms=${ALL_FORMS}`]);
    await openCheckoutAsGuest(page);
    await magewire.idle();

    const notices = page.locator(NOTICE);
    await expect(notices.first()).toBeVisible();
    const text = (await notices.first().innerText()).replace(/\s+/g, ' ');
    // one missing field is named (SAV1); several give the general text with the link
    expect(text, 'several missing fields: the general text').toContain('Your saved address has some required fields missing.');
    expect(text).not.toContain('E2E Address Warn');
    const firstHref = await notices.first().locator('a').getAttribute('href');

    // the other incomplete address has its own notice with its own link
    const radios = page.locator('input[type="radio"][name="address_shipping"]');
    if ((await radios.count()) > 0) {
        await page.locator('label', { hasText: '2 Other Street' }).filter({ has: page.locator('input[name="address_shipping"]') }).first().click();
    } else {
        const select = page.locator('select#address-list').first();
        await select.selectOption(await select.locator('option', { hasText: '2 Other Street' }).first().getAttribute('value') as string);
    }
    await magewire.idle();
    await expect(notices.first()).toBeVisible();
    const secondHref = await notices.first().locator('a').getAttribute('href');
    expect(secondHref).not.toBe(firstHref);
    expect(secondHref).toMatch(/customer\/address\/edit\/id\/\d+/);
});

test('SAV6: billing differs from shipping: each address has its own notice', { tag: ['@SAV6'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    setSavedAddress(email, { e2e_a_req: 'complete', e2e_a_warn: 'filled' });
    setSavedAddress(email, { e2e_a_req: 'incomplete' }, true);
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_a_warn', 'required=1', `forms=${ALL_FORMS}`]);
    await openCheckoutAsGuest(page);
    await magewire.idle();
    const notices = page.locator(NOTICE);
    await expect(notices, 'the default address is complete').toHaveCount(0);

    // billing on its own address list: the incomplete address gives a notice for billing only
    await page.locator('#billing-as-shipping').uncheck();
    await magewire.idle();
    const billingList = page.getByRole('group', { name: 'Billing Address' }).getByRole('combobox', { name: 'Address List' });
    await expect(billingList).toBeVisible();
    await billingList.selectOption(await billingList.locator('option', { hasText: '2 Other Street' }).first().getAttribute('value') as string);
    await magewire.idle();
    await expect(notices).toHaveCount(1);
    await expect(notices.first()).toContainText('E2E Address Warn');

    // the shipping address incomplete too: one notice each
    await page.locator('label', { hasText: '2 Other Street' }).filter({ has: page.locator('input[name="address_shipping"]') }).first().click();
    await magewire.idle();
    await expect(notices).toHaveCount(2);
});

test('SAV8: the notice is not refreshed in a tab that stayed open: it goes after a reload (known limitation)', { tag: ['@SAV8'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    setSavedAddress(email, { e2e_a_req: 'incomplete' });
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_a_warn', 'required=1', `forms=${ALL_FORMS}`]);
    await openCheckoutAsGuest(page);
    await magewire.idle();
    await expect(page.locator(NOTICE).first()).toBeVisible();
    const href = (await page.locator(NOTICE).first().locator('a').getAttribute('href')) as string;

    // the address is fixed in another tab
    const other = await page.context().newPage();
    await other.goto(href);
    await other.locator('[name="e2e_a_warn"]').fill('fixed elsewhere');
    await other.getByRole('button', { name: /save address/i }).click();
    await expect(other).toHaveURL(/customer\/address(\/index)?\/?$/);
    await other.close();

    // back in the first tab: nothing refreshed it yet; a reload shows the address as it is now
    await page.bringToFront();
    await page.waitForTimeout(1500);
    await expect(page.locator(NOTICE).first(), 'the open tab still shows the old notice').toBeVisible();
    await page.reload();
    await magewire.idle();
    await expect(page.locator(NOTICE).first()).toBeHidden();
});

test('CCO11: a customer attribute limited to the Wholesale group is hidden for General and shown to Wholesale', { tag: ['@CCO11'] }, async ({ page, magewire }) => {
    // General customer: the field is not in the checkout, the value kept on the account does not reach the order
    setAccountValues(email, { e2e_c_ws: 'account wholesale value' });
    setSavedAddress(email, { e2e_a_req: 'saved required' });
    await loginAs(page, email);
    await openCheckoutAsGuest(page);
    await typeCustomerRequired(page, magewire);
    await expect(field(page, 'e2e_c_ws'), 'hidden for General').toBeHidden();
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    let { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.customer.e2e_c_ws, 'no row for a group that cannot see the attribute').toBeUndefined();
    expect(accountValues(email).customer_attributes.e2e_c_ws, 'the account keeps its value').toBe('account wholesale value');

    // Wholesale customer: the field is shown and its value is on the order
    benchCommand('E2E_FIXTURE_CMD', ['set-customer-group', email, '2']);
    await page.context().clearCookies();
    await loginAs(page, email);
    dropQuotes(email);
    await openCheckoutAsGuest(page);
    await typeCustomerRequired(page, magewire);
    await expect(field(page, 'e2e_c_ws'), 'shown for Wholesale').toBeVisible();
    await field(page, 'e2e_c_ws').fill('wholesale typed');
    await field(page, 'e2e_c_ws').press('Tab');
    await magewire.idle();
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    ({ snapshot } = await placedOrderSnapshot(page));
    expect(snapshot.customer.e2e_c_ws).toMatchObject({ value: 'wholesale typed' });
});

test('CCO14: changing the postcode and the shipping method keeps every typed value', { tag: ['@CCO14'] }, async ({ page, magewire }) => {
    const guest = uniqueEmail('cco14');
    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire, { email_address: guest, e2e_a_text: 'address text kept' });
    await field(page, 'e2e_c_text').fill('customer text kept');
    await field(page, 'e2e_c_text').press('Tab');
    await magewire.idle();

    const expectKept = async (step: string) => {
        await expect(field(page, 'e2e_a_text'), `${step}: address value in the page`).toHaveValue('address text kept');
        await expect(field(page, 'e2e_c_text'), `${step}: customer value in the page`).toHaveValue('customer text kept');
        await expect.poll(() => {
            try {
                const quote = quoteValues('@latest');
                return `${quote.customer_attributes.e2e_c_text}|${Object.values(quote.addresses ?? {}).map((values) => values.e2e_a_text).join(',')}`;
            } catch {
                return '';
            }
        }, { timeout: 20_000, message: `${step}: values on the quote` }).toContain('customer text kept|address text kept');
    };
    await expectKept('before');
    await field(page, 'postcode').fill('75002');
    await field(page, 'postcode').press('Tab');
    await magewire.idle();
    await expectKept('after the postcode');
    await chooseShippingAndPayment(page, magewire);
    await expectKept('after the shipping method');
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.customer.e2e_c_text).toMatchObject({ value: 'customer text kept' });
    expect(snapshot.shipping.e2e_a_text).toMatchObject({ value: 'address text kept' });
});

test('RAD3: ticking a checkbox of a group that is the parent of a rule shows the dependent, unticking hides it', { tag: ['@RAD3'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire, { email_address: uniqueEmail('rad3') });
    const box = (label: string) => page.locator('label:has(input[data-form="shipping"][name^="e2e_a_chk"])').filter({ hasText: new RegExp(`^\\s*${label}\\s*$`) }).first().locator('input');
    await expect(field(page, 'e2e_a_cdep')).toBeHidden();
    await box('Two').check();
    await magewire.idle();
    await expect(field(page, 'e2e_a_cdep')).toBeVisible();
    await field(page, 'e2e_a_cdep').fill('dependent of a checkbox');
    await field(page, 'e2e_a_cdep').press('Tab');
    await magewire.idle();
    await box('One').check();
    await magewire.idle();
    await expect(field(page, 'e2e_a_cdep'), 'another box does not hide it').toBeVisible();
    await box('Two').uncheck();
    await magewire.idle();
    await expect(field(page, 'e2e_a_cdep')).toBeHidden();
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.shipping.e2e_a_chk).toMatchObject({ value_label: 'One' });
    expect(snapshot.shipping.e2e_a_cdep, 'a hidden dependent is not on the order').toBeUndefined();
});
