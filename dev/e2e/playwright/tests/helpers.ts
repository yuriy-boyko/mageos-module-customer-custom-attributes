import { expect, test as base, Browser, BrowserContext, Locator, Page, Request } from '@playwright/test';
import { execFileSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';

const authFile = (name: string): string => path.join(process.env.CCA_E2E_HOME || process.cwd(), '.auth', name);

type Inflight = { readonly count: number; add(request: Request): void; remove(request: Request): void; clear(): void };

function newInflight(): Inflight {
    // the requests themselves are tracked, so a missed or repeated event cannot leave a wrong count behind
    const requests = new Set<Request>();
    return {
        get count() { return requests.size; },
        add: (request) => { requests.add(request); },
        remove: (request) => { requests.delete(request); },
        clear: () => { requests.clear(); },
    };
}

/** Watches a page: CSP violations, script errors and failed Magewire calls become test failures; tracks Magewire calls in flight. */
function watch(page: Page, problems: string[], inflight: Inflight): void {
    page.on('pageerror', (error) => problems.push(`script error: ${error.message} @ ${(error.stack || '').split('\n').slice(1, 3).map((line) => line.trim()).join(' | ')}`));
    page.on('console', (message) => {
        const text = message.text();
        // Script and style violations are what inline code of the module would cause. Violations about framing (frame-ancestors, frame-src)
        // come from the payment widgets of third parties (Stripe, Google Pay, Amazon Pay) and are not the module's.
        if (message.type() === 'error' && /Content Security Policy|violates the following|Refused to/i.test(text) && !/frame-ancestors|frame-src|child-src/i.test(text)) {
            problems.push(`CSP: ${text.slice(0, 200)}`);
        }
    });
    page.on('request', (request) => {
        if (request.url().includes('/magewire/')) {
            inflight.add(request);
        }
    });
    // requests cancelled by a navigation never finish: start counting again on the new page
    page.on('framenavigated', (frame) => {
        if (frame === page.mainFrame()) {
            inflight.clear();
        }
    });
    page.on('requestfinished', (request) => inflight.remove(request));
    page.on('requestfailed', (request) => inflight.remove(request));
    page.on('response', (response) => {
        if (response.url().includes('/magewire/') && response.status() >= 400) {
            problems.push(`magewire ${response.status()}: ${response.url()}`);
        }
    });
}

/** Waits until no Magewire request is in flight and none starts for a short while (see the `magewire` fixture). */
export function magewireOf(page: Page): { idle(): Promise<void> } {
    const inflight = (page as Page & { __inflight: Inflight }).__inflight;
    // A change triggers a request, and its response can trigger more (other sections reload): wait until nothing is in flight
    // and nothing new starts for a short while, so the next step does not meet a half-updated page.
    return {
        idle: async () => {
            await expect.poll(async () => {
                if (inflight.count > 0) {
                    return 'busy';
                }
                await page.waitForTimeout(300);
                return inflight.count === 0 ? 'idle' : 'busy';
            }, { message: 'Magewire requests still in flight', timeout: 30_000 }).toBe('idle');
        },
    };
}

type Fixtures = {
    problems: string[];
    /** Waits until no Magewire request is in flight (the checkout re-renders after each one). */
    magewire: { idle(): Promise<void> };
    /** Waits until no Magewire request is in flight on the customer's page. */
    customerMagewire: { idle(): Promise<void> };
    /** A page signed in as the fixture customer (skips the test when no customer session exists). */
    customerPage: Page;
    /** A page signed in to the admin (skips the test when no admin session exists). */
    adminPage: Page;
};

/**
 * `test` is Playwright's test with extras: every test fails when a page logged a Content Security Policy violation, an uncaught script
 * error or a failed Magewire request, and the fixtures above are available.
 */
export const test = base.extend<Fixtures>({
    problems: [
        async ({}, use, testInfo) => {
            const problems: string[] = [];
            await use(problems);
            expect(problems, `page problems in "${testInfo.title}" (CSP violations, script errors, failed Magewire calls)`).toEqual([]);
        },
        { auto: true },
    ],
    page: async ({ page, problems }, use) => {
        const inflight = newInflight();
        (page as Page & { __inflight?: Inflight }).__inflight = inflight;
        watch(page, problems, inflight);
        await use(page);
    },
    magewire: async ({ page }, use) => {
        await use(magewireOf(page));
    },
    /** The same for the page of the signed-in customer. */
    customerMagewire: async ({ customerPage }, use) => {
        await use(magewireOf(customerPage));
    },
    customerPage: async ({ browser, problems }, use, testInfo) => {
        const file = authFile('customer.json');
        testInfo.skip(!fs.existsSync(file), 'no customer session (set E2E_CUSTOMER_PASSWORD and seed the fixtures)');
        const context = await newContext(browser, file, testInfo.project.use.baseURL as string);
        const page = await context.newPage();
        const inflight = newInflight();
        (page as Page & { __inflight?: Inflight }).__inflight = inflight;
        watch(page, problems, inflight);
        await use(page);
        await context.close();
    },
    adminPage: async ({ browser }, use, testInfo) => {
        const file = authFile('admin.json');
        testInfo.skip(!fs.existsSync(file), 'no admin session (set E2E_ADMIN_USER and E2E_ADMIN_PASSWORD)');
        const context = await newContext(browser, file, testInfo.project.use.baseURL as string);
        const page = await context.newPage();
        await use(page);
        await context.close();
    },
});

async function newContext(browser: Browser, storageState: string, baseURL: string): Promise<BrowserContext> {
    return browser.newContext({ storageState, baseURL, ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 } });
}

export { expect };

/**
 * Opens an admin page through its menu link. Admin URLs carry a secret key, so a typed address does not work: the link in the menu
 * has the key. `hrefPart` is a part of the wanted address, e.g. `mageos_cca/relation`.
 */
export async function adminOpen(page: Page, hrefPart: string): Promise<void> {
    await page.goto('/backend/');
    const link = page.locator(`a[href*="${hrefPart}"]`).first();
    const href = await link.getAttribute('href');
    if (!href) {
        throw new Error(`No admin menu link contains "${hrefPart}".`);
    }
    await page.goto(href);
}

/** An e-mail no earlier run used; it matches the pattern that `fixtures.php purge` deletes. */
export function uniqueEmail(prefix = 'shopper'): string {
    return `${prefix}-e2e-${Date.now()}-${Math.floor(Math.random() * 1000)}@example.com`;
}

/**
 * Switches the Hyvä Checkout variant of the store (onepage, default = multi-step, mobile). The switch is done by the command in
 * E2E_VARIANT_CMD (run-all.sh sets it); without it the test is skipped. The variant already set is remembered, so a second test
 * with the same variant does not switch again. The one-page checkout is the default of every other test, so it is set again after the group.
 */
export function useVariant(variant: 'onepage' | 'default' | 'mobile'): void {
    base.beforeAll(async ({}, testInfo) => {
        if (!process.env.E2E_VARIANT_CMD) {
            testInfo.skip(true, 'E2E_VARIANT_CMD is not set (run through dev/e2e/run-all.sh)');
            return;
        }
        switchVariant(variant);
    });
    // every other test expects the one-page checkout: put it back when this group is done
    base.afterAll(() => {
        if (process.env.E2E_VARIANT_CMD && variant !== 'onepage') {
            switchVariant('onepage');
        }
    });
}

function switchVariant(variant: string): void {
    const marker = path.join(process.env.CCA_E2E_HOME || process.cwd(), '.variant');
    if (fs.existsSync(marker) && fs.readFileSync(marker, 'utf8') === variant) {
        return;
    }
    execFileSync(process.env.E2E_VARIANT_CMD as string, [variant], { stdio: 'pipe', timeout: 120_000 });
    fs.writeFileSync(marker, variant);
}

/** Adds the test product (or the product page given, e.g. the virtual fixture product) to the cart and opens the checkout. */
export async function openCheckoutAsGuest(page: Page, productUrl?: string): Promise<void> {
    // an absolute product URL (the Italy store) puts the checkout on the same host
    const origin = productUrl && /^https?:/.test(productUrl) ? new URL(productUrl).origin : '';
    await page.goto(productUrl || process.env.E2E_PRODUCT_URL || '/strive-shoulder-pack.html');
    // wait for the add-to-cart request itself: leaving the page earlier would leave the cart empty
    await Promise.all([
        page.waitForResponse((response) => response.url().includes('/checkout/cart/add'), { timeout: 20_000 }),
        page.locator('#product-addtocart-button').click(),
    ]);
    await page.goto(`${origin}/checkout/`);
    // a guest sees the address form; a customer with a saved address sees the list of addresses, but both see the module's sections
    await expect(page.locator('[name="firstname"], [data-mageos-cca-customer]').first()).toBeAttached();
    if (!process.env.E2E_MODULE_OFF) {
        await expect(page.locator('[data-mageos-cca-customer]').first()).toBeAttached(); // (INS9 runs with the module disabled)
    }
}

/** The input (or select) of a checkout address field by attribute code. */
export function field(page: Page, code: string) {
    return page.locator(`[name="${code}"], [name="${code}[]"]`).first();
}

/**
 * Stops with a clear message when the fixtures are not seeded (or the cache was not flushed after seeding): the fields are missing
 * from the checkout, and a plain timeout would hide the reason.
 */
export async function requireFixtures(page: Page, codes: string[]): Promise<void> {
    for (const code of codes) {
        const count = await page.locator(`[name="${code}"], [name="${code}[]"]`).count();
        if (count === 0) {
            throw new Error(
                `The checkout has no field "${code}". Seed the fixtures (php dev/e2e/fixtures.php seed), run bin/magento cache:flush, `
                + 'and check that the companion package is installed and the attribute is enabled for the checkout forms.'
            );
        }
    }
}

/** Magewire handle of the `magewire` fixture. */
type Magewire = { idle(): Promise<void> };

/**
 * Fills the shipping address of the checkout (guest) with valid values, including the fixture attribute that is always required
 * (`e2e_a_req`) and the required customer attribute (`e2e_c_req`). `overrides` replaces or adds fields by input name; the region is
 * Texas. Waits for Magewire after every field.
 */
export async function fillCheckout(page: Page, magewire: Magewire, overrides: Record<string, string> = {}, region = 'Texas'): Promise<void> {
    const values: Record<string, string> = {
        email_address: uniqueEmail('guest'),
        firstname: 'Test',
        lastname: 'Shopper',
        'street[0]': '1 Test Street',
        postcode: '75001',
        city: 'Dallas',
        telephone: '5551230000',
        e2e_a_req: 'required value',
        e2e_c_req: 'required customer value',
        ...overrides,
    };
    const regionName = region;
    const regionSelect = page.locator('select[name="region"]').first();
    if (await regionSelect.count()) {
        // an exact label ("Texas") or the start of one ("Roma")
        const labels = await regionSelect.locator('option').allInnerTexts();
        const wanted = labels.find((label) => label.trim() === regionName) ?? labels.find((label) => label.trim().startsWith(regionName));
        await regionSelect.selectOption({ label: wanted });
        await magewire.idle();
    }
    for (const [name, value] of Object.entries(values)) {
        const input = field(page, name);
        if ((await input.count()) === 0) {
            continue;
        }
        await input.fill(value);
        await input.press('Tab');
        await magewire.idle();
    }
}

/** Picks the first shipping method and the Check / Money order payment. */
export async function chooseShippingAndPayment(page: Page, magewire: Magewire): Promise<void> {
    // a cart with only virtual products has no shipping methods
    const shipping = page.locator('[name="shipping-method-option"]').first();
    if (await shipping.count()) {
        await shipping.check();
        await magewire.idle();
    }
    await page.locator('[name="payment-method-option"][value="checkmo"]').check();
    await magewire.idle();
}

/** Clicks Place Order. */
export async function placeOrder(page: Page): Promise<void> {
    await page.getByRole('button', { name: /^place order$/i }).filter({ visible: true }).first().click();
}

/**
 * Runs one of the bench commands the test setup provides (run-all.sh sets them): E2E_FIXTURE_CMD runs `fixtures.php`
 * (delete-option, delete-attribute), E2E_ASSERT_CMD runs `assertions.php` (order, customer, quote). Returns what it printed. A test
 * that needs one is skipped when it is not set (a run without run-all.sh).
 */
export function benchCommand(which: 'E2E_FIXTURE_CMD' | 'E2E_ASSERT_CMD', args: string[]): string {
    const command = process.env[which];
    if (!command) {
        test.skip(true, `${which} is not set (run through dev/e2e/run-all.sh)`);
        return '';
    }
    return execFileSync(command, args, { encoding: 'utf8', timeout: 120_000 });
}

export type Snapshot = {
    customer: Record<string, { value: string; value_label: string }>;
    billing: Record<string, { value: string; value_label: string }>;
    shipping: Record<string, { value: string; value_label: string }>;
};

/**
 * The order number on the success page and the module's snapshot of that order (customer attributes and both addresses, by
 * attribute code), read from the database.
 */
export async function placedOrderSnapshot(page: Page): Promise<{ incrementId: string; snapshot: Snapshot }> {
    await expect(page).toHaveURL(/checkout\/onepage\/success/, { timeout: 30_000 });
    const text = await page.locator('body').innerText();
    const match = text.match(/\b(\d{9,10})\b/);
    if (!match) {
        throw new Error('No order number found on the success page.');
    }
    const incrementId = match[1];
    return { incrementId, snapshot: snapshotOf(incrementId) };
}

/** The module's snapshot of an order (customer attributes and both addresses, by attribute code), read from the database. */
export function snapshotOf(incrementId: string): Snapshot {
    const data = JSON.parse(benchCommand('E2E_ASSERT_CMD', ['order', incrementId]));
    const byCode = (rows: { attribute_code: string; value: string; value_label: string }[] = []) =>
        Object.fromEntries(rows.map((row) => [row.attribute_code, { value: row.value, value_label: row.value_label }]));
    return {
        customer: byCode(data.customer_attributes),
        billing: byCode(data.addresses?.billing),
        shipping: byCode(data.addresses?.shipping),
    };
}

/** What the bench commands return, parsed (see assertions.php). */
export type CustomerValues = { customer_id: number; customer_attributes: Record<string, string | null>; addresses: Record<string, Record<string, string | null>> };
export type QuoteValues = { quote_id: number; active: boolean; customer_attributes: Record<string, string>; addresses: Record<string, Record<string, string>> };

/** The custom attribute values stored on a customer's account (read from the database). */
export function accountValues(email: string): CustomerValues {
    return JSON.parse(benchCommand('E2E_ASSERT_CMD', ['customer', email]));
}

/** The module's values on the latest quote of a customer or of a guest e-mail (read from the database). */
export function quoteValues(email: string): QuoteValues {
    return JSON.parse(benchCommand('E2E_ASSERT_CMD', ['quote', email]));
}

/** Sets custom attribute values on a fixture customer, e.g. `setAccountValues(email, { e2e_c_sel: 'Gold', e2e_c_text: '' })`. */
export function setAccountValues(email: string, values: Record<string, string>): void {
    benchCommand('E2E_FIXTURE_CMD', ['set-customer', email, ...Object.entries(values).map(([code, value]) => `${code}=${value}`)]);
}

/** Deletes the quotes of a customer or e-mail: afterwards the customer has no active cart. */
export function dropQuotes(email: string): void {
    benchCommand('E2E_FIXTURE_CMD', ['drop-quotes', email]);
}

/**
 * The customer attribute sections of the checkout: position -> codes of the fields that are visible (sections without a visible
 * field are left out). Reads what is on the page now; the step variants show one step at a time.
 */
export async function sections(page: Page): Promise<Record<string, string[]>> {
    return page.evaluate(() => {
        const result: Record<string, string[]> = {};
        document.querySelectorAll<HTMLElement>('[data-mageos-cca-customer]').forEach((section) => {
            const codes = [...section.querySelectorAll<HTMLElement>('.mageos-cca-field')]
                .filter((element) => element.offsetParent !== null)
                .map((element) => element.dataset.code || '');
            if (codes.length) {
                result[section.dataset.mageosCcaCustomer || ''] = codes;
            }
        });
        return result;
    });
}

/**
 * Walks through the steps of a step variant (multi-step, mobile) and returns the sections of every step in order, with the name of the
 * step from its address. The shipping address must be valid to leave the first step; Check / Money order is chosen on the payment step.
 */
export async function walkSteps(page: Page, magewire: Magewire): Promise<{ step: string; sections: Record<string, string[]> }[]> {
    const steps: { step: string; sections: Record<string, string[]> }[] = [];
    for (let guard = 0; guard < 6; guard++) {
        const step = new URL(page.url()).pathname.match(/step\/(\w+)/)?.[1] ?? 'first';
        steps.push({ step, sections: await sections(page) });
        const next = page.getByRole('button', { name: /^proceed to /i }).filter({ visible: true });
        if ((await next.count()) === 0) {
            break;
        }
        const payment = page.locator('[name="payment-method-option"][value="checkmo"]');
        if ((await payment.count()) > 0 && (await payment.first().isVisible())) {
            await payment.first().check();
            await magewire.idle();
        }
        await next.first().click();
        await magewire.idle();
        await page.waitForTimeout(300);
    }
    return steps;
}

/** The first option of a group is also labelled by the group's own label (they share an id): match the end of the name. */
const endsWith = (label: string): RegExp => new RegExp(`(^|\\s)${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`);

/** A radio button or checkbox of a customer attribute section by its label. */
export function optionOf(page: Page, code: string, role: 'radio' | 'checkbox', label: string) {
    return page.locator(`[data-code="${code}"]`).getByRole(role, { name: endsWith(label) });
}

/** A radio button or checkbox of a customer attribute in the account forms (not the checkout) by its label. */
export function accountOptionOf(page: Page, code: string, role: 'radio' | 'checkbox', label: string) {
    return page.locator(`.field.${code}`).getByRole(role, { name: endsWith(label) });
}

/** Picks a radio button of a customer attribute section by its label. */
export async function pickRadio(page: Page, code: string, label: string): Promise<void> {
    await page.locator(`[data-code="${code}"]`).getByRole('radio', { name: endsWith(label) }).check();
}

/** Ticks or unticks a checkbox of a customer attribute section by its label. */
export async function tickCheckbox(page: Page, code: string, label: string, on = true): Promise<void> {
    await page.locator(`[data-code="${code}"]`).getByRole('checkbox', { name: endsWith(label) }).setChecked(on);
}

/**
 * Sends a value straight to the Magewire component of a customer attribute section, as a hand-made request would, bypassing the
 * browser's own show and hide logic. Used to prove what the server does with a value the form would never send.
 */
export async function sendMagewireValue(page: Page, position: string, code: string, value: string): Promise<void> {
    await page.evaluate(({ position, code, value }) => {
        const section = document.querySelector(`[data-mageos-cca-customer="${position}"]`);
        const root = section && section.closest('[wire\\:id]');
        const id = root && root.getAttribute('wire:id');
        const magewire = (window as unknown as { Magewire?: { find(id: string): { set(property: string, value: string): void } } }).Magewire;
        if (!id || !magewire) {
            throw new Error(`No Magewire component for the position ${position}`);
        }
        magewire.find(id).set(`values.${code}`, value);
    }, { position, code, value });
}

/** Signs the fixture customer in on this page (storefront login form). */
export async function loginAs(page: Page, email: string): Promise<void> {
    const password = process.env.E2E_CUSTOMER_PASSWORD;
    if (!password) {
        test.skip(true, 'E2E_CUSTOMER_PASSWORD is not set');
        return;
    }
    await page.goto('/customer/account/login/');
    const form = page.locator('form[action*="loginPost"]');
    await form.locator('[name="login[username]"]').fill(email);
    await form.locator('[name="login[password]"]').fill(password);
    await form.locator('button[type="submit"]').click();
    await page.waitForURL(/customer\/account(?!\/login)/, { timeout: 20_000 });
}

/** The e-mail of the fixture customer. */
export const customerEmail = (): string => process.env.E2E_CUSTOMER_EMAIL || 'e2e.customer@example.com';

// ---------------------------------------------------------------------------------------------------------------------------------
// Admin: the attribute edit page (Stores > Attributes > Customer / Customer Address Attributes). Shared by the ADM and ACL specs.
// ---------------------------------------------------------------------------------------------------------------------------------

export type AdminEntity = 'customer' | 'address';

/** How an attribute is stored (see `assertions.php attribute`); `exists` is false when there is no such attribute. */
export type AttributeRow = {
    exists: boolean;
    id: number;
    label: string;
    store_labels: Record<string, string>;
    input: string;
    backend_type: string;
    required: boolean;
    user_defined: boolean;
    default_value: string | null;
    is_visible: boolean;
    sort_order: number;
    forms: string[];
    options: { label: string; sort_order: number; is_default: boolean; store_labels: Record<string, string> }[];
    rules: Record<string, unknown>;
    groups: number[];
    websites: Record<string, { website_id: string; is_visible: string | null; is_required: string | null; default_value: string | null }>;
};

/** Reads an attribute from the database. */
export function attributeRow(entity: AdminEntity, code: string): AttributeRow {
    return JSON.parse(benchCommand('E2E_ASSERT_CMD', ['attribute', entity, code]));
}

/** Deletes an attribute a test created through the admin (only codes starting with e2e_); nothing happens when it is gone. */
/** Creates plain optional text attributes (codes starting with e2e_) without the browser; delete them with `deleteCreated`. */
export function createAttributes(entity: AdminEntity, codes: string[], forms?: string[], checkoutPosition?: string): void {
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', entity, ...(forms ? [`--forms=${forms.join(',')}`] : []), ...(checkoutPosition ? [`--position=${checkoutPosition}`] : []), ...codes]);
}

export function deleteCreated(code: string): void {
    benchCommand('E2E_FIXTURE_CMD', ['delete-created', code]);
}

/** Opens the "Add New" page of the attribute grid of an entity. */
export async function openNewAttribute(page: Page, entity: AdminEntity): Promise<void> {
    await adminOpen(page, `mageos_cca/attribute/index/entity/${entity}`);
    await page.locator('.page-actions-buttons button, .page-actions a, .page-actions button').filter({ hasText: /Add New/i }).first().click();
    await expect(page).toHaveURL(/mageos_cca\/attribute\/new/);
}

/** Opens the edit page of an attribute: grid, filter by code, click the row. */
export async function openAttribute(page: Page, entity: AdminEntity, code: string): Promise<void> {
    await adminOpen(page, `mageos_cca/attribute/index/entity/${entity}`);
    const filter = page.locator('[name="attribute_code"]');
    await filter.fill(code);
    await filter.press('Enter');
    await page.locator('tbody tr', { hasText: code }).first().click();
    await expect(page).toHaveURL(/mageos_cca\/attribute\/edit/);
}

/** Switches to a tab of the edit page: Properties, Manage Labels or Storefront Properties. */
export async function attributeTab(page: Page, name: 'Properties' | 'Manage Labels' | 'Storefront Properties' | 'Input Validation' | string): Promise<void> {
    await page.locator('#mageos_cca_attribute_tabs a, .admin__page-nav-link').filter({ hasText: name }).first().click();
}

/** Fills the Properties tab of a new attribute: default label, code, input type and "Values Required". */
export async function fillAttributeMain(page: Page, main: { label?: string; code?: string; input?: string; required?: boolean }): Promise<void> {
    if (main.label !== undefined) await page.locator('#attribute_label').fill(main.label);
    if (main.code !== undefined) await page.locator('#attribute_code').fill(main.code);
    if (main.input !== undefined) await page.locator('#frontend_input').selectOption(main.input);
    if (main.required !== undefined) await page.locator('#is_required').selectOption(main.required ? '1' : '0');
}

/** The option rows of a dropdown, multiselect, radio or checkbox group attribute on the edit page. */
export function optionRows(page: Page) {
    return page.locator('tr').filter({ has: page.locator('input[name^="option[value]"]') });
}

/** Adds an option row and fills its admin label (and, optionally, store view labels by store id); returns its row. */
export async function addOption(page: Page, label: string, storeLabels: Record<number, string> = {}) {
    const before = await optionRows(page).count();
    await page.locator('#add_new_option_button').click();
    const row = optionRows(page).nth(before);
    await row.locator('input[name$="[0]"]').fill(label);
    for (const [store, text] of Object.entries(storeLabels)) {
        await row.locator(`input[name$="[${store}]"]`).fill(text);
    }
    return row;
}

/** Clicks "Save Attribute" (or "Save and Continue Edit" with `stay`) and waits for the page that follows. */
export async function saveAttribute(page: Page, stay = false): Promise<void> {
    await page.locator(stay ? '#save_and_edit_button' : '#save').click();
    await page.waitForLoadState('networkidle');
}

/** The admin messages on the page, as text. */
export async function adminMessages(page: Page): Promise<string> {
    return (await page.locator('.messages, #messages, .message').allInnerTexts()).join(' | ');
}

// ---------------------------------------------------------------------------------------------------------------------------------
// Admin: the attribute grid (filters, sorting, pager, mass actions)
// ---------------------------------------------------------------------------------------------------------------------------------

/** Opens the attribute grid of an entity with the filter reset (the grid keeps its filter in the session between visits). */
export async function openGrid(page: Page, entity: AdminEntity): Promise<void> {
    await adminOpen(page, `mageos_cca/attribute/index/entity/${entity}`);
    await page.locator('[data-action="grid-filter-reset"]').click();
    await page.waitForLoadState('networkidle');
}

/** Filters the grid by typing into the filter fields (by field name) and pressing Enter; selects are chosen by value. */
export async function gridFilter(page: Page, filters: Record<string, string>): Promise<void> {
    for (const [name, value] of Object.entries(filters)) {
        const field = page.locator(`#mageosCcaAttributeGrid_filter_${name}`);
        if ((await field.evaluate((element) => element.tagName)) === 'SELECT') {
            await field.selectOption(value);
        } else {
            await field.fill(value);
        }
    }
    await page.locator('#mageosCcaAttributeGrid_filter_attribute_code').press('Enter');
    await page.waitForLoadState('networkidle');
}

/** The text of one grid column (`attribute_code`, `frontend_label`, `frontend_input`, `is_required`, `is_visible`, `sort_order`) in row order. */
export async function gridColumn(page: Page, column: string): Promise<string[]> {
    return (await page.locator(`#mageosCcaAttributeGrid tbody td.col-${column}`).allInnerTexts()).map((text) => text.trim());
}

/**
 * Runs a mass action on the grid. `rows` ticks the rows with these codes; `'all'` uses "Select All" (every row of the current filter,
 * on every page). A delete is confirmed.
 */
export async function gridMassAction(page: Page, action: 'enable' | 'disable' | 'delete', rows: string[] | 'all'): Promise<void> {
    if (rows === 'all') {
        await page.locator('#mageosCcaAttributeGrid_massaction-mass-select').selectOption('selectAll');
    } else {
        for (const code of rows) {
            await page.locator('#mageosCcaAttributeGrid tbody tr', { has: page.locator('td.col-attribute_code', { hasText: new RegExp(`^\\s*${code}\\s*$`) }) }).locator('input[data-role="select-row"]').check();
        }
    }
    await page.locator('#mageosCcaAttributeGrid_massaction-select').selectOption(action);
    await page.locator('#mageosCcaAttributeGrid_massaction-form button[title="Submit"]').click();
    const confirm = page.locator('.modal-popup._show .action-accept');
    if (await confirm.waitFor({ state: 'visible', timeout: 3_000 }).then(() => true, () => false)) {
        await confirm.click();
    }
    await page.waitForLoadState('networkidle');
}

// ---------------------------------------------------------------------------------------------------------------------------------
// Admin: customers
// ---------------------------------------------------------------------------------------------------------------------------------

/** Opens the admin edit page of a customer: Customers > All Customers, search by e-mail, open the row. */
export async function openCustomerEdit(page: Page, email: string): Promise<void> {
    await adminOpen(page, 'customer/index');
    const search = page.locator('input[placeholder*="Search by keyword"]').first();
    await search.fill(email);
    await search.press('Enter');
    // The grid (schedule-mode indexer) can still list customers that earlier runs deleted, and its Edit links carry the secret key of the
    // action, which does not depend on the id: take any link and put the id of the customer that exists now into it.
    const id = String(accountValues(email).customer_id);
    const link = page.locator('tr.data-row a[href*="customer/index/edit"]').first();
    await link.waitFor({ state: 'attached' });
    const href = (await link.getAttribute('href')) as string;
    await page.goto(href.replace(/\/id\/\d+\//, `/id/${id}/`));
    await expect(page).toHaveURL(/customer\/index\/edit/);
}

/** Opens a tab of the admin customer edit page, e.g. "Account Information" or "Addresses". */
export async function customerTab(page: Page, name: string): Promise<void> {
    await page.locator('.admin__page-nav-link, [role=tab]').filter({ hasText: name }).first().click();
}

// ---------------------------------------------------------------------------------------------------------------------------------
// Admin: a restricted test admin (ACL specs)
// ---------------------------------------------------------------------------------------------------------------------------------

export const ACL_ADMIN = 'e2e_acl_user';
export const ACL_BASE = ['Magento_Backend::stores', 'Magento_Backend::stores_attributes'];
export const ACL = {
    customer: 'MageOS_CustomerCustomAttributes::customer_attributes',
    address: 'MageOS_CustomerCustomAttributes::customer_address_attributes',
    relations: 'MageOS_CustomerCustomAttributes::relations',
};

/** Creates the test admin (or changes its role) so that its role allows exactly these resources besides the Stores menu path. */
export function setAclAdmin(resources: string[]): void {
    benchCommand('E2E_FIXTURE_CMD', ['set-admin', ACL_ADMIN, [...ACL_BASE, ...resources].join(',')]);
}

export function deleteAclAdmin(): void {
    benchCommand('E2E_FIXTURE_CMD', ['delete-admin', ACL_ADMIN]);
}

/** A page signed in to the admin as the restricted test admin; close its context when done. */
export async function signInAclAdmin(browser: Browser, baseURL: string): Promise<{ page: Page; context: BrowserContext }> {
    const password = process.env.E2E_CUSTOMER_PASSWORD;
    if (!password) {
        test.skip(true, 'E2E_CUSTOMER_PASSWORD is not set');
    }
    const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    await page.goto('/backend/');
    await page.locator('#username').fill(ACL_ADMIN);
    await page.locator('#login').fill(password as string);
    await page.locator('.action-login').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#username')).toHaveCount(0);
    return { page, context };
}

/** The admin menu links of the module's screens: which of customer, address, relations the signed-in admin can see. */
export async function aclMenu(page: Page): Promise<{ customer: number; address: number; relations: number }> {
    await page.goto('/backend/');
    const count = (part: string) => page.locator(`a[href*="${part}"]`).count();
    return {
        customer: await count('mageos_cca/attribute/index/entity/customer'),
        address: await count('mageos_cca/attribute/index/entity/address'),
        relations: await count('mageos_cca/relation'),
    };
}

/** Opens an address with the secret key of an earlier link and expects Magento's "no permission" answer (403). */
export async function expectForbidden(page: Page, url: string): Promise<void> {
    const response = await page.goto(url);
    expect(response?.status(), url).toBe(403);
    await expect(page.locator('body')).toContainText(/need permissions|permission/i);
}

// ---------------------------------------------------------------------------------------------------------------------------------
// Admin: order create and order address edit (AOC specs)
// ---------------------------------------------------------------------------------------------------------------------------------

/** The three parts of the admin order form that hold custom attribute fields. */
export type OrderArea = 'account' | 'billing_address' | 'shipping_address';

/** A field of the admin order form by area and attribute code (ids: `e2e_c_text`, `order-billing_address_e2e_a_text`). */
export function orderField(page: Page, area: OrderArea, code: string) {
    return area === 'account' ? page.locator(`#${code}`) : page.locator(`#order-${area}_${code}`);
}

/** Waits until the admin order form finished its Ajax update (the page shows a loading mask meanwhile). */
export async function adminOrderIdle(page: Page): Promise<void> {
    await page.waitForTimeout(300);
    await expect.poll(async () => {
        try {
            return await page.locator('.loading-mask, #loading-mask').evaluateAll((nodes) =>
                nodes.some((node) => (node as HTMLElement).offsetParent !== null || getComputedStyle(node as HTMLElement).display === 'block'));
        } catch {
            return true; // the page is navigating: look again
        }
    },
    { message: 'the admin order form is still loading', timeout: 30_000 }).toBe(false);
}

/** Chooses the store view of a new admin order and waits for the order form; the choice is repeated when the page did not react to it. */
async function chooseStoreView(page: Page, storeView: string): Promise<void> {
    await expect(async () => {
        const radio = page.locator('#order-store-selector').getByLabel(storeView, { exact: true });
        if ((await radio.count()) > 0) {
            await radio.check({ timeout: 10_000 });
        }
        await expect(page.locator('#order-items')).toBeVisible({ timeout: 15_000 });
    }).toPass({ timeout: 75_000 });
}

/** Starts a new admin order for a customer: Sales > Orders > Create New Order, pick the customer and the store view. */
export async function startAdminOrder(page: Page, email: string, storeView = 'Default Store View'): Promise<void> {
    await adminOpen(page, 'sales/order/');
    await page.locator('#add').click();
    const filter = page.locator('#sales_order_create_customer_grid_filter_email');
    await filter.fill(email);
    await filter.press('Enter');
    await adminOrderIdle(page);
    await page.locator('#sales_order_create_customer_grid_table tbody tr', { hasText: email }).first().click();
    await chooseStoreView(page, storeView);
    await adminOrderIdle(page);
}

/** Adds a product to the admin order by SKU through the "Add Products" grid. */
export async function addAdminOrderProduct(page: Page, sku: string): Promise<void> {
    await page.locator('#add_products, button:has-text("Add Products")').first().click();
    const filter = page.locator('#sales_order_create_search_grid_filter_sku');
    await filter.fill(sku);
    await filter.press('Enter');
    await adminOrderIdle(page);
    await page.locator('#sales_order_create_search_grid_table tbody tr', { hasText: sku }).first().locator('input[type=checkbox]').check();
    await page.locator('button:has-text("Add Selected Product(s) to Order")').first().click();
    await adminOrderIdle(page);
    await expect(page.locator('#order-items').first()).toContainText(sku, { timeout: 15_000 });
}

/** Gives the fixture customer one saved address with custom values (`code: 'option label' | 'a|b' | 'YYYY-MM-DD' | text`). */
export function setSavedAddress(email: string, values: Record<string, string> = {}, add = false): void {
    benchCommand('E2E_FIXTURE_CMD', ['set-address', email, ...(add ? ['--add'] : []), ...Object.entries(values).map(([code, value]) => `${code}=${value}`)]);
}

/** Removes every saved address of a customer. */
export function deleteSavedAddresses(email: string): void {
    benchCommand('E2E_FIXTURE_CMD', ['set-address', email, '--delete']);
}

/** The labels of the chosen options of a select or multiselect field. */
export async function chosenLabels(field: Locator): Promise<string[]> {
    return (await field.locator('option:checked').allInnerTexts()).map((text) => text.trim()).filter((text) => text !== '');
}

/**
 * Fills the standard fields of an address block of the admin order form (billing or shipping) and the custom attributes that are
 * required there. Values for the other custom attributes go through `fillOrderValues`.
 */
export async function fillOrderAddress(page: Page, area: 'billing_address' | 'shipping_address', name = 'Ada Admin'): Promise<void> {
    const [first, last] = name.split(' ');
    const id = (field: string) => page.locator(`#order-${area}_${field}`);
    await id('firstname').fill(first);
    await id('lastname').fill(last);
    await id('street0').fill('1 Admin Road');
    await id('city').fill('Austin');
    await id('country_id').selectOption('US');
    await adminOrderIdle(page);
    await id('region_id').selectOption({ label: 'Texas' });
    await id('postcode').fill('78701');
    await id('telephone').fill('5125550100');
    await adminOrderIdle(page);
}

/** A value for a field of the admin order form: text, an option label, several option labels, or `{ checked }`. */
export type OrderValue = string | string[];

/** Types or chooses values in the custom attribute fields of one area of the admin order form (by attribute code). */
export async function fillOrderValues(page: Page, area: OrderArea, values: Record<string, OrderValue>): Promise<void> {
    for (const [code, value] of Object.entries(values)) {
        const field = orderField(page, area, code);
        const tag = await field.evaluate((node) => node.tagName.toLowerCase());
        if (tag === 'select') {
            await field.selectOption((Array.isArray(value) ? value : [value]).map((label) => ({ label })));
        } else {
            await field.fill(value as string);
        }
    }
}

/** Loads the shipping rates (the first click only ends the editing of the field before, so it retries) and picks the first method. */
export async function chooseAdminShipping(page: Page): Promise<void> {
    const getRates = page.locator('#order-shipping-method-summary').getByText('Get shipping methods and rates');
    // chosen: the "Get shipping methods" link is gone, the block shows the chosen method with "Click to change shipping method" (after a
    // reorder this is so from the start, until another part of the form reloads the block and brings the link back)
    const done = async (): Promise<boolean> => (await getRates.count()) === 0;
    if (await done()) {
        return;
    }
    await expect(async () => {
        await adminOrderIdle(page);
        if (await done()) {
            return;
        }
        // the first click only ends the editing of the field before, so it may need a second one
        await getRates.first().click({ timeout: 3000 }).catch(() => undefined);
        const method = page.locator('input[name="order[shipping_method]"]').first();
        await expect(method).toBeVisible({ timeout: 6000 });
        await method.click();
        await adminOrderIdle(page);
        expect(await done()).toBe(true);
    }).toPass({ timeout: 70_000 });
}

/** Picks Check / Money order as the payment method of the admin order. */
export async function chooseAdminPayment(page: Page): Promise<void> {
    const payment = page.locator('#p_method_checkmo');
    if (await payment.isChecked().catch(() => false)) {
        return;
    }
    // The payment block is reloaded when other parts of the form change (after a reorder, after the shipping method): load the methods when
    // only the link is there, click the radio, wait for the form to settle and look again.
    await expect(async () => {
        const link = page.locator('#order-billing_method_summary a').first();
        if (await link.count()) {
            await link.click({ timeout: 2000 }).catch(() => undefined);
        }
        await payment.click({ timeout: 4000 });
        await adminOrderIdle(page);
        await expect(page.locator('#p_method_checkmo')).toBeChecked({ timeout: 3000 });
    }).toPass({ timeout: 40_000 });
}

/** Submits the admin order; returns the increment id (from the order view's title) and the entity id. */
export async function submitAdminOrder(page: Page): Promise<{ incrementId: string; orderId: string }> {
    await page.locator('#submit_order_top_button').click();
    await page.waitForURL(/sales\/order\/view\/order_id\/\d+/, { timeout: 60_000 });
    const incrementId = ((await page.locator('.page-title').first().innerText()).match(/\d{9,10}/) ?? [''])[0];
    const orderId = (page.url().match(/order_id\/(\d+)/) ?? ['', ''])[1];
    expect(incrementId, 'order number on the admin order view').not.toBe('');
    return { incrementId, orderId };
}

/** Everything the admin order needs besides the values under test; fills only what core and the fixtures require. */
export async function completeAdminOrder(page: Page, billing: Record<string, OrderValue> = {}): Promise<{ incrementId: string; orderId: string }> {
    await addAdminOrderProduct(page, '24-MB04');
    await fillOrderAddress(page, 'billing_address');
    // required address attributes of the fixtures (a value given by the test wins)
    await fillOrderValues(page, 'billing_address', { e2e_a_req: 'required text', e2e_a_rdep: 'required dep', ...billing });
    await chooseAdminShipping(page);
    await chooseAdminPayment(page);
    return submitAdminOrder(page);
}

/** Opens the Edit Order Address page of an order's billing or shipping address from the admin order view. */
export async function openOrderAddressEdit(page: Page, which: 'billing' | 'shipping'): Promise<void> {
    await page.locator(`.order-${which}-address a[href*="sales/order/address"]`).first().click();
    await expect(page).toHaveURL(/sales\/order\/address\/address_id/);
}

/** The text of the address blocks on the admin order view (billing and shipping, one string per block). */
export async function orderViewAddresses(page: Page): Promise<{ billing: string; shipping: string }> {
    return {
        billing: await page.locator('.order-billing-address').first().innerText(),
        shipping: await page.locator('.order-shipping-address').first().innerText(),
    };
}

/** Starts an admin order for a customer who does not exist yet: Orders > Create New Order > Create New Customer, then the store view. */
export async function startAdminOrderNewCustomer(page: Page, storeView = 'Default Store View'): Promise<void> {
    await adminOpen(page, 'sales/order/');
    await page.locator('#add').click();
    await page.locator('button:has-text("Create New Customer")').first().click();
    await chooseStoreView(page, storeView);
    await adminOrderIdle(page);
}


// ---------------------------------------------------------------------------------------------------------------------------------
// Websites: the Italy store (WEB specs)
// ---------------------------------------------------------------------------------------------------------------------------------

/** The Italy website's storefront (website id 2, EUR, Italian address forms); the main website is the project's base URL. */
export const ITA = 'https://ita.magento249.test';
export const ITA_PRODUCT = `${ITA}/strive-shoulder-pack.html`;

/** Sets or clears (`'-'`) the per-website override of a fixture attribute: enabled, required, default value. */
export function websiteOverride(code: string, websiteId: number, settings: { enabled?: '0' | '1' | '-'; required?: '0' | '1' | '-'; default?: string }): void {
    const pairs = Object.entries(settings).map(([key, value]) => `${key}=${value}`);
    benchCommand('E2E_FIXTURE_CMD', ['set-website', code, String(websiteId), ...pairs]);
}

/** Removes every override of the attributes on a website (what the WEB specs call after each test). */
export function clearWebsiteOverrides(codes: string[], websiteIds = [1, 2]): void {
    for (const code of codes) {
        for (const websiteId of websiteIds) {
            websiteOverride(code, websiteId, { enabled: '-', required: '-', default: '-' });
        }
    }
}

/** A customer on a website (the password is the fixture customer's). */
export function createWebsiteCustomer(email: string, websiteId: number): void {
    benchCommand('E2E_FIXTURE_CMD', ['create-customer', email, String(websiteId)]);
}

/** Signs a customer in on a storefront host (`origin` empty = the base URL). */
export async function loginOn(page: Page, origin: string, email: string): Promise<void> {
    const password = process.env.E2E_CUSTOMER_PASSWORD as string;
    await page.goto(`${origin}/customer/account/login/`);
    const form = page.locator('form[action*="loginPost"]');
    await form.locator('[name="login[username]"]').fill(email);
    await form.locator('[name="login[password]"]').fill(password);
    await form.locator('button[type="submit"]').click();
    await page.waitForURL(/customer\/account(?!\/login)/, { timeout: 20_000 });
}

/** Opens an order in the admin from the orders grid (search by order number). */
export async function openAdminOrder(page: Page, incrementId: string): Promise<void> {
    await adminOpen(page, 'sales/order/');
    const search = page.locator('input[placeholder*="Search by keyword"]').first();
    await search.fill(incrementId);
    await search.press('Enter');
    await page.locator('tr.data-row', { hasText: incrementId }).first().click();
    await expect(page).toHaveURL(/sales\/order\/view/);
}
