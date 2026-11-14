import type { Page } from '@playwright/test';
import {
    accountValues, attributeRow, benchCommand, chooseShippingAndPayment, createWebsiteCustomer, customerEmail, deleteSavedAddresses, expect, field, fillCheckout,
    gridFilter, gridMassAction, ITA, ITA_PRODUCT, loginOn, openAdminOrder, openCheckoutAsGuest, openGrid, orderViewAddresses, placeOrder, placedOrderSnapshot,
    requireFixtures, setSavedAddress, test, uniqueEmail, websiteOverride,
} from './helpers';

/**
 * WEB: per-website scope. The Italy website (id 2, https://ita.magento249.test, EUR, Italian address forms) and the main website
 * (id 1) share the attributes; each can override Enabled, Required and the default value of an attribute. The tests set the overrides
 * through the bench (the Websites tab of the admin is covered by the attribute specs) and look at the storefront of both websites.
 * Fixture attributes: address e2e_a_text (text), e2e_a_area (text area), e2e_a_bool (yes/no); customer e2e_c_account (account forms only).
 */
const TEXT = 'e2e_a_text';
const ITALY = { postcode: '00100', city: 'Roma', telephone: '0612345678' };

const touched = new Set<string>();
function override(code: string, websiteId: number, settings: Parameters<typeof websiteOverride>[2]): void {
    touched.add(`${code}:${websiteId}`);
    websiteOverride(code, websiteId, settings);
}

test.afterEach(() => {
    for (const key of touched) {
        const [code, websiteId] = key.split(':');
        websiteOverride(code, Number(websiteId), { enabled: '-', required: '-', default: '-' });
    }
    touched.clear();
    deleteSavedAddresses(customerEmail());
});

/** A customer on the Italy website, signed in on the Italy storefront in this page. */
async function italianCustomer(page: Page): Promise<string> {
    const email = uniqueEmail('ita');
    createWebsiteCustomer(email, 2);
    await loginOn(page, ITA, email);
    return email;
}

test('WEB2: an attribute disabled for one website is absent there and present on the other', { tag: ['@WEB2'] }, async ({ page }) => {
    override(TEXT, 2, { enabled: '0' });
    await openCheckoutAsGuest(page, ITA_PRODUCT);
    await requireFixtures(page, ['e2e_a_req']);
    await expect(page.locator(`[name="${TEXT}"]`)).toHaveCount(0);

    await openCheckoutAsGuest(page);
    await requireFixtures(page, [TEXT]);
});

test('WEB3: required for one website only: blocked there when empty, placed when filled, optional on the other', { tag: ['@WEB3'] }, async ({ page, magewire }) => {
    override(TEXT, 2, { required: '1' });

    // Italy: empty is refused
    await openCheckoutAsGuest(page, ITA_PRODUCT);
    await requireFixtures(page, [TEXT]);
    await fillCheckout(page, magewire, ITALY, 'Roma');
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    await expect(page.locator(`.field-${TEXT}`)).toHaveClass(/field-error/);
    await expect(page).toHaveURL(/\/checkout\/?$/);

    // Italy: filled is placed, the order carries the value
    await field(page, TEXT).fill('required on ita');
    await field(page, TEXT).press('Tab');
    await magewire.idle();
    await placeOrder(page);
    const { incrementId, snapshot } = await placedOrderSnapshot(page);
    expect(incrementId).toMatch(/^2\d{9}$/); // order numbers of the Italy store start with the store id
    expect(snapshot.shipping[TEXT]).toMatchObject({ value: 'required on ita' });

    // main website: still optional, an empty value is placed
    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire);
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const main = await placedOrderSnapshot(page);
    expect(main.snapshot.shipping[TEXT]).toBeUndefined();
});

test('WEB4 + WEB6: default values per website fill a new address in the account form, and the form follows the customer\'s website', { tag: ['@WEB4', '@WEB6'] }, async ({ page, customerPage }) => {
    override(TEXT, 1, { default: 'Main default' });
    override(TEXT, 2, { default: 'Ita default' });
    override('e2e_a_area', 1, { default: 'Main area' });
    override('e2e_a_area', 2, { default: 'Ita area' });
    override('e2e_a_bool', 1, { default: '0' });
    override('e2e_a_bool', 2, { default: '1' });

    // main website customer
    await customerPage.goto('/customer/address/new/');
    await expect(customerPage.locator(`[name="${TEXT}"]`)).toHaveValue('Main default');
    await expect(customerPage.locator('[name="e2e_a_area"]')).toHaveValue('Main area');
    await expect(customerPage.locator('[name="e2e_a_bool"]')).toHaveValue('0');

    // Italian customer on the Italian host
    await italianCustomer(page);
    await page.goto(`${ITA}/customer/address/new/`);
    await expect(page.locator(`[name="${TEXT}"]`)).toHaveValue('Ita default');
    await expect(page.locator('[name="e2e_a_area"]')).toHaveValue('Ita area');
    await expect(page.locator('[name="e2e_a_bool"]')).toHaveValue('1');

    // the form follows the website: disabled for Italy, the Italian customer loses the field, the main one keeps it
    override(TEXT, 2, { enabled: '0' });
    await page.goto(`${ITA}/customer/address/new/`);
    await expect(page.locator('[name="e2e_a_area"]')).toBeVisible();
    await expect(page.locator(`[name="${TEXT}"]`)).toHaveCount(0);
    await customerPage.goto('/customer/address/new/');
    await expect(customerPage.locator(`[name="${TEXT}"]`)).toBeVisible();
});

test('WEB5: address text where the attribute is disabled has no line; old orders keep theirs', { tag: ['@WEB5'] }, async ({ page, magewire, adminPage }) => {
    // an Italian order with a value, while the attribute is enabled
    await openCheckoutAsGuest(page, ITA_PRODUCT);
    await fillCheckout(page, magewire, { ...ITALY, [TEXT]: 'old order value' }, 'Roma');
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { incrementId } = await placedOrderSnapshot(page);

    // an Italian customer with a saved address that has a value: the address book shows the line
    const email = await italianCustomer(page);
    benchCommand('E2E_FIXTURE_CMD', ['set-address', email, `${TEXT}=saved on ita`, 'e2e_a_req=required']);
    await page.goto(`${ITA}/customer/address/`);
    await expect(page.locator('main, #maincontent').first()).toContainText('saved on ita');

    // disabled for Italy: no line there, the old order still shows its value in the admin
    override(TEXT, 2, { enabled: '0' });
    await page.goto(`${ITA}/customer/address/`);
    await expect(page.locator('main, #maincontent').first()).toContainText('Austin'); // the address itself is still shown
    await expect(page.locator('main, #maincontent').first()).not.toContainText('saved on ita');
    await openAdminOrder(adminPage, incrementId);
    expect((await orderViewAddresses(adminPage)).billing).toContain('old order value');
});

test('WEB7: mass disable and enable change the global flag and keep the website overrides', { tag: ['@WEB7'] }, async ({ adminPage: page }) => {
    const code = 'e2e_a_warn';
    override(code, 2, { enabled: '1', required: '1' });
    const overrides = () => attributeRow('address', code).websites['2'];
    expect(overrides()).toMatchObject({ is_visible: '1', is_required: '1' });
    try {
        await openGrid(page, 'address');
        await gridFilter(page, { attribute_code: code });
        await gridMassAction(page, 'disable', 'all');
        await expect(page.locator('.message-success')).toContainText('1 attribute(s) have been disabled');
        expect(attributeRow('address', code).is_visible).toBe(false);
        expect(overrides(), 'the override stays after a mass disable').toMatchObject({ is_visible: '1', is_required: '1' });
    } finally {
        await openGrid(page, 'address');
        await gridFilter(page, { attribute_code: code });
        await gridMassAction(page, 'enable', 'all');
    }
    await expect(page.locator('.message-success')).toContainText('1 attribute(s) have been enabled');
    expect(attributeRow('address', code).is_visible).toBe(true);
    expect(overrides(), 'the override stays after a mass enable').toMatchObject({ is_visible: '1', is_required: '1' });
});

test('WEB8: a customer attribute with a website override follows it in the account form', { tag: ['@WEB8'] }, async ({ page, customerPage }) => {
    const code = 'e2e_c_account';
    override(code, 2, { enabled: '0' });
    await italianCustomer(page);
    await page.goto(`${ITA}/customer/account/edit/`);
    await expect(page.locator('[name="firstname"]')).toBeVisible();
    await expect(page.locator(`[name="${code}"]`)).toHaveCount(0);
    await customerPage.goto('/customer/account/edit/');
    await expect(customerPage.locator(`[name="${code}"]`)).toBeVisible();

    // enabled and required for Italy only
    override(code, 2, { enabled: '1', required: '1' });
    await page.goto(`${ITA}/customer/account/edit/`);
    const italian = page.locator(`[name="${code}"]`);
    await expect(italian).toBeVisible();
    await page.locator('form[action*="editPost"] button[type="submit"]').click();
    await expect(page).toHaveURL(/customer\/account\/edit/);
    expect(await italian.evaluate((element: HTMLInputElement) => element.validity.valueMissing)).toBe(true);
    await customerPage.goto('/customer/account/edit/');
    expect(await customerPage.locator(`[name="${code}"]`).evaluate((element: HTMLInputElement) => element.required)).toBe(false);
    expect(accountValues(customerEmail()).customer_attributes[code] ?? '').not.toBe('never');
});
