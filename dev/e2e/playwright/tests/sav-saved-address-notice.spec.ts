import type { Page } from '@playwright/test';
import {
    benchCommand, chooseShippingAndPayment, customerEmail, deleteSavedAddresses, dropQuotes, expect, field, loginAs, openCheckoutAsGuest, placeOrder,
    placedOrderSnapshot, setAccountValues, setSavedAddress, test, useVariant, websiteOverride,
} from './helpers';

/**
 * SAV: a saved address that lacks an attribute that became required later. Hyvä Checkout does not edit address book entries, so the
 * module shows a notice with a link to the account address form next to the address list while such an address is selected.
 *
 * Fixture: e2e_a_warn is an optional text in every storefront form; the tests make it required after the addresses were saved and put
 * it back. e2e_a_rdep is required but shown only when e2e_a_rsel is "Show" (SAV5).
 */
const ALL_FORMS = 'customer_address_edit,customer_register_address,mageos_hyva_checkout,mageos_address_text';
const COMPLETE = { e2e_a_req: 'saved required', e2e_a_warn: 'complete address' };
const INCOMPLETE = { e2e_a_req: 'saved required' };
const NOTICE = '.mageos-cca-address-notice';

const setRequired = (required: boolean) => benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_a_warn', `required=${required ? 1 : 0}`, `forms=${ALL_FORMS}`]);

test.beforeEach(() => {
    dropQuotes(customerEmail());
    deleteSavedAddresses(customerEmail());
    setAccountValues(customerEmail(), { e2e_c_text: 'account text', e2e_c_req: 'account required' });
});

test.afterEach(() => {
    setRequired(false);
    deleteSavedAddresses(customerEmail());
});

async function openCheckout(page: Page, magewire: { idle(): Promise<void> }): Promise<void> {
    await openCheckoutAsGuest(page);
    await magewire.idle();
}

async function typeCustomerRequired(page: Page, magewire: { idle(): Promise<void> }): Promise<void> {
    const input = field(page, 'e2e_c_req');
    if ((await input.count()) > 0 && (await input.inputValue()) === '') {
        await input.fill('required customer value');
        await input.press('Tab');
        await magewire.idle();
    }
}

test('SAV1-SAV3: the notice names the field and links to the address; the order is refused; after the fix it is placed', { tag: ['@SAV1', '@SAV2', '@SAV3'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    setSavedAddress(customerEmail(), INCOMPLETE);
    setRequired(true);
    await openCheckout(page, magewire);

    // SAV1: a notice next to the address list, one field named, with an edit link
    const notice = page.locator(NOTICE).first();
    await expect(notice).toBeVisible();
    await expect(notice).toContainText('Your saved address needs the field "E2E Address Warn".');
    const link = notice.locator('a');
    await expect(link).toHaveAttribute('href', /customer\/address\/edit\/id\/\d+/);
    const href = (await link.getAttribute('href')) as string;

    // SAV2: the order is refused, the message names the address and the field
    await typeCustomerRequired(page, magewire);
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const alert = page.locator('[role="alert"]:not(.mageos-cca-address-notice)').filter({ hasText: /E2E Address Warn/ }).first();
    await expect(alert).toBeVisible({ timeout: 15_000 });
    const text = (await alert.innerText()).replace(/\s+/g, ' ');
    expect(text).toContain('Your saved shipping address needs the field "E2E Address Warn". Please edit the address in My Account > Address Book, or choose another address.');
    expect(text).toContain('Your saved billing address needs the field "E2E Address Warn".');
    await expect(page).toHaveURL(/\/checkout\/?$/);

    // SAV3: the link opens the account address form; the field is filled and saved; back in the checkout the notice is gone and the
    // order is placed
    await page.goto(href);
    await page.locator('[name="e2e_a_warn"]').fill('now filled');
    await page.getByRole('button', { name: /save address/i }).click();
    await expect(page).toHaveURL(/customer\/address(\/index)?\/?$/);
    await openCheckout(page, magewire);
    await expect(page.locator(NOTICE).first()).toBeHidden();
    await typeCustomerRequired(page, magewire);
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.shipping.e2e_a_warn).toMatchObject({ value: 'now filled' });
});

test('SAV4: the notice follows the selected address', { tag: ['@SAV4'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    setSavedAddress(customerEmail(), COMPLETE);
    setSavedAddress(customerEmail(), INCOMPLETE, true);
    setRequired(true);
    await openCheckout(page, magewire);

    // the default (complete) address is selected: no notice
    await expect(page.locator(NOTICE).first()).toBeHidden();
    const radios = page.locator('input[type="radio"][name="address_shipping"]');
    const selects = page.locator('select#address-list');
    // the list is radio buttons or a dropdown, depending on the number of addresses
    const choose = async (text: string) => {
        if ((await radios.count()) > 0) {
            await page.locator('label', { hasText: text }).filter({ has: page.locator('input[name="address_shipping"]') }).first().click();
        } else {
            const select = selects.first();
            const value = await select.locator('option', { hasText: text }).first().getAttribute('value');
            await select.selectOption(value as string);
        }
        await magewire.idle();
    };
    await choose('2 Other Street');
    await expect(page.locator(NOTICE).first()).toBeVisible();
    await expect(page.locator(NOTICE).first()).toContainText('E2E Address Warn');
    await choose('1 Saved Street');
    await expect(page.locator(NOTICE).first()).toBeHidden();
});

test('SAV5: an attribute hidden by a rule gives no notice and does not block the order', { tag: ['@SAV5'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    // e2e_a_rdep is required and shown only when e2e_a_rsel is "Show": the saved address has no value for either
    setSavedAddress(customerEmail(), COMPLETE);
    await openCheckout(page, magewire);
    await expect(page.locator(NOTICE).first()).toBeHidden();
    await typeCustomerRequired(page, magewire);
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    await placedOrderSnapshot(page);
});

test.describe('SAV5 with a customer group restriction and a disabled website', () => {
    test.afterEach(() => {
        benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_a_ws', 'required=0']);
        benchCommand('E2E_FIXTURE_CMD', ['set-customer-group', customerEmail(), '1']);
        websiteOverride('e2e_a_warn', 1, { enabled: '-', required: '-', default: '-' });
    });

    test('SAV5: a required attribute the customer group cannot see gives no notice and does not block the order; the allowed group gets the notice', { tag: ['@SAV5'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
        // e2e_a_ws is limited to the group Wholesale (2); the customer is General (1). Made required after the address was saved.
        setSavedAddress(customerEmail(), COMPLETE);
        benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_a_ws', 'required=1']);
        await openCheckout(page, magewire);
        await expect(page.locator(NOTICE).first()).toBeHidden();
        await typeCustomerRequired(page, magewire);
        await chooseShippingAndPayment(page, magewire);
        await placeOrder(page);
        const { snapshot } = await placedOrderSnapshot(page);
        expect(snapshot.shipping.e2e_a_ws, 'no row for a group that cannot see the attribute').toBeUndefined();

        // Negative control: as a Wholesale customer the same saved address lacks the field, so the notice names it
        benchCommand('E2E_FIXTURE_CMD', ['set-customer-group', customerEmail(), '2']);
        dropQuotes(customerEmail());
        await page.context().clearCookies();
        await loginAs(page, customerEmail());
        await openCheckout(page, magewire);
        await expect(page.locator(NOTICE).first()).toBeVisible();
        await expect(page.locator(NOTICE).first()).toContainText('E2E Address Wholesale Only');
    });

    test('SAV5: a required attribute disabled for the website gives no notice and does not block the order', { tag: ['@SAV5'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
        // e2e_a_warn is required (SAV1 shows the notice with this very setup) but switched off for the main website
        setSavedAddress(customerEmail(), INCOMPLETE);
        setRequired(true);
        websiteOverride('e2e_a_warn', 1, { enabled: '0' });
        await openCheckout(page, magewire);
        await expect(page.locator(NOTICE).first()).toBeHidden();
        await expect(field(page, 'e2e_a_warn'), 'not offered in the address form').toHaveCount(0);
        await typeCustomerRequired(page, magewire);
        await chooseShippingAndPayment(page, magewire);
        await placeOrder(page);
        const { snapshot } = await placedOrderSnapshot(page);
        expect(snapshot.shipping.e2e_a_warn).toBeUndefined();
    });
});

for (const variant of ['default', 'mobile'] as const) {
    test.describe(`${variant} checkout`, () => {
        useVariant(variant);
        test(`SAV7: the notice is shown in the ${variant} checkout`, { tag: ['@SAV7'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
            setSavedAddress(customerEmail(), INCOMPLETE);
            setRequired(true);
            await openCheckout(page, magewire);
            const notice = page.locator(NOTICE).first();
            await expect(notice).toBeVisible();
            await expect(notice).toContainText('Your saved address needs the field "E2E Address Warn".');
            await expect(notice.locator('a')).toHaveAttribute('href', /customer\/address\/edit\/id\/\d+/);
        });
    });
}
