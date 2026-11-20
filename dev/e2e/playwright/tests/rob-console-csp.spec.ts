import type { Page } from '@playwright/test';
import {
    addAdminOrderProduct, adminOpen, customerEmail, customerTab, expect, gridFilter, ITA, ITA_PRODUCT, openCheckoutAsGuest, openCustomerEdit, openGrid, setSavedAddress,
    startAdminOrder, deleteSavedAddresses, dropQuotes, setAccountValues, test,
} from './helpers';

/**
 * ROB2: no browser console errors and no failed requests of the shop itself on the pages the module touches (checkout, registration,
 * account, address book, the admin screens). ROB5: the markup the module renders has no inline `on*` or `style` attributes and no inline
 * script without a nonce, and no page logged a Content Security Policy violation.
 *
 * Third parties (Stripe, PayPal, Amazon Pay, Google) are not the module's: their messages and requests are ignored. The
 * server's raw HTML (not the DOM after scripts ran) is checked for attributes, so what Alpine sets at runtime does not count.
 */
const THIRD_PARTY = /stripe|paypal|braintree|amazon|google|gstatic|facebook|doubleclick|origin trial|frame-ancestors|frame-src|child-src|favicon/i;

class Watch {
    readonly issues: string[] = [];
    constructor(page: Page, private readonly shopHosts: string[]) {
        page.on('console', (message) => {
            const text = message.text();
            if (message.type() === 'error' && !THIRD_PARTY.test(text)) this.issues.push(`console error: ${text.slice(0, 200)}`);
            if (/Content Security Policy|violates the following/i.test(text) && !THIRD_PARTY.test(text)) this.issues.push(`CSP: ${text.slice(0, 200)}`);
        });
        page.on('pageerror', (error) => this.issues.push(`script error: ${error.message}`));
        page.on('response', (response) => {
            const url = response.url();
            if (response.status() >= 400 && this.shopHosts.some((host) => url.startsWith(host)) && !THIRD_PARTY.test(url)) {
                this.issues.push(`${response.status()} ${url.slice(0, 160)}`);
            }
        });
        page.on('requestfailed', (request) => {
            const url = request.url();
            // a request cancelled by the next navigation is not a failure
            if (this.shopHosts.some((host) => url.startsWith(host)) && !THIRD_PARTY.test(url) && !/ERR_ABORTED/.test(request.failure()?.errorText ?? '')) {
                this.issues.push(`failed ${url.slice(0, 160)}: ${request.failure()?.errorText}`);
            }
        });
    }
}

/** Inline attributes on the module's own elements and inline scripts without a nonce that mention the module, in the raw server HTML. */
function markupIssues(html: string, where: string): string[] {
    const issues: string[] = [];
    for (const tag of html.match(/<[a-z][^>]*(?:field-reserved|mageos-cca|data-mageos)[^>]*>/gi) ?? []) {
        if (/\son[a-z]+\s*=/i.test(tag) || /\sstyle\s*=/i.test(tag)) issues.push(`${where}: inline attribute on ${tag.slice(0, 120)}`);
    }
    for (const script of html.match(/<script(?![^>]*\bsrc=)[^>]*>[\s\S]*?<\/script>/gi) ?? []) {
        const open = script.slice(0, script.indexOf('>') + 1);
        if (/mageos[_-]?cca|mageosCca|CustomerCustomAttributes/i.test(script) && !/\bnonce=/i.test(open) && !/type="(?:application\/(?:ld\+)?json|text\/x-magento-init)"/i.test(open)) {
            issues.push(`${where}: inline script without nonce: ${script.slice(0, 100)}`);
        }
    }
    return issues;
}

async function visit(page: Page, url: string, watch: Watch, label: string): Promise<void> {
    const response = await page.goto(url, { waitUntil: 'load' });
    await page.waitForTimeout(1200);
    expect(response?.status(), `${label} answered`).toBeLessThan(400);
    watch.issues.push(...markupIssues((await response?.text()) ?? '', label));
}

const clean = (watch: Watch) => expect(watch.issues, 'console errors, failed requests, CSP and markup problems').toEqual([]);

test('ROB2 + ROB5: guest pages of the main website', { tag: ['@quick', '@ROB2', '@ROB5', '@CCO18'] }, async ({ page }) => {
    const watch = new Watch(page, ['https://app.magento249.test']);
    await visit(page, '/customer/account/create/', watch, 'registration');
    await visit(page, '/customer/account/login/', watch, 'login');
    await openCheckoutAsGuest(page);
    // the checkout's own server HTML
    const checkout = await page.goto('/checkout/');
    watch.issues.push(...markupIssues((await checkout?.text()) ?? '', 'checkout'));
    await page.waitForTimeout(1500);
    clean(watch);
});

test('ROB2 + ROB5: logged-in customer pages', { tag: ['@ROB2', '@ROB5'] }, async ({ customerPage: page }) => {
    const watch = new Watch(page, ['https://app.magento249.test']);
    setAccountValues(customerEmail(), { e2e_c_text: 'rob' }); // also fills the required customer attributes
    deleteSavedAddresses(customerEmail());
    // with a saved address: the address book lists it with the module's lines, the edit form shows the values
    setSavedAddress(customerEmail(), { e2e_a_text: 'rob', e2e_a_req: 'rob' });
    try {
        await visit(page, '/customer/account/', watch, 'account');
        await visit(page, '/customer/account/edit/', watch, 'account edit');
        await visit(page, '/customer/address/', watch, 'address book');
        await visit(page, '/customer/address/new/', watch, 'new address');
        await visit(page, '/sales/order/history/', watch, 'orders');
    } finally {
        deleteSavedAddresses(customerEmail());
    }
    // without one: the checkout shows the new-address form with the module's sections (a fresh cart, like the CCO specs)
    dropQuotes(customerEmail());
    await openCheckoutAsGuest(page);
    const checkout = await page.goto('/checkout/');
    watch.issues.push(...markupIssues((await checkout?.text()) ?? '', 'checkout (customer)'));
    await page.waitForTimeout(1500);
    clean(watch);
});

test('ROB2 + ROB5: Italy website pages', { tag: ['@ROB2', '@ROB5'] }, async ({ page }) => {
    const watch = new Watch(page, [ITA]);
    await visit(page, `${ITA}/customer/account/create/`, watch, 'registration (Italy)');
    await openCheckoutAsGuest(page, ITA_PRODUCT);
    const checkout = await page.goto(`${ITA}/checkout/`);
    watch.issues.push(...markupIssues((await checkout?.text()) ?? '', 'checkout (Italy)'));
    await page.waitForTimeout(1500);
    clean(watch);
});

test('ROB2 + ROB5: admin screens of the module', { tag: ['@ROB2', '@ROB5'] }, async ({ adminPage: page }) => {
    const watch = new Watch(page, ['https://app.magento249.test']);
    await openGrid(page, 'customer');
    await openGrid(page, 'address');
    await gridFilter(page, { attribute_code: 'e2e_a_sel' });
    // an attribute edit page, its tabs
    await page.locator('#mageosCcaAttributeGrid tbody tr', { hasText: 'e2e_a_sel' }).first().click();
    await expect(page).toHaveURL(/mageos_cca\/attribute\/edit/);
    for (const tab of ['Manage Labels', 'Storefront Properties', 'Websites']) {
        await page.locator('.admin__page-nav-link, [role=tab]').filter({ hasText: tab }).first().click();
    }
    // relations
    await adminOpen(page, 'mageos_cca/relation');
    await page.locator('tr.mageos-cca-relation-row').first().click();
    await expect(page).toHaveURL(/mageos_cca\/relation\/edit/);
    await page.locator('#mageos-cca-relation-add').click();
    // customer edit with the address modal
    await openCustomerEdit(page, customerEmail());
    await customerTab(page, 'Account Information');
    await customerTab(page, 'Addresses');
    await page.getByRole('button', { name: 'Add New Address' }).first().click();
    await expect(page.locator('.modal-slide._show, .modal-popup._show').first()).toBeVisible();
    // order create
    await startAdminOrder(page, customerEmail());
    await addAdminOrderProduct(page, '24-MB04');
    await page.waitForTimeout(1000);
    expect(watch.issues.filter((issue) => /CSP/.test(issue)), 'no CSP violation in the admin').toEqual([]);
    clean(watch);
});
