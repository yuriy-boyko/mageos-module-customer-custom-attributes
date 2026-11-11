import { expect, test as base, Browser, BrowserContext, Page, Request } from '@playwright/test';
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
        if (message.type() === 'error' && /Content Security Policy|violates the following|Refused to/i.test(text)) {
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

type Fixtures = {
    problems: string[];
    /** Waits until no Magewire request is in flight (the checkout re-renders after each one). */
    magewire: { idle(): Promise<void> };
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
        const inflight = (page as Page & { __inflight: Inflight }).__inflight;
        await use({
            idle: async () => {
                await expect.poll(() => inflight.count, { message: 'Magewire requests still in flight', timeout: 30_000 }).toBe(0);
                await page.waitForTimeout(150);
            },
        });
    },
    customerPage: async ({ browser, problems }, use, testInfo) => {
        const file = authFile('customer.json');
        testInfo.skip(!fs.existsSync(file), 'no customer session (set E2E_CUSTOMER_PASSWORD and seed the fixtures)');
        const context = await newContext(browser, file, testInfo.project.use.baseURL as string);
        const page = await context.newPage();
        watch(page, problems, newInflight());
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
 * with the same variant does not switch again.
 */
export function useVariant(variant: 'onepage' | 'default' | 'mobile'): void {
    base.beforeAll(async ({}, testInfo) => {
        const command = process.env.E2E_VARIANT_CMD;
        if (!command) {
            testInfo.skip(true, 'E2E_VARIANT_CMD is not set (run through dev/e2e/run-all.sh)');
            return;
        }
        const marker = path.join(process.env.CCA_E2E_HOME || process.cwd(), '.variant');
        if (fs.existsSync(marker) && fs.readFileSync(marker, 'utf8') === variant) {
            return;
        }
        execFileSync(command, [variant], { stdio: 'pipe', timeout: 120_000 });
        fs.writeFileSync(marker, variant);
    });
}

/** Adds the test product to a fresh guest cart and opens the checkout. */
export async function openCheckoutAsGuest(page: Page): Promise<void> {
    await page.goto(process.env.E2E_PRODUCT_URL || '/strive-shoulder-pack.html');
    // wait for the add-to-cart request itself: leaving the page earlier would leave the cart empty
    await Promise.all([
        page.waitForResponse((response) => response.url().includes('/checkout/cart/add'), { timeout: 20_000 }),
        page.locator('#product-addtocart-button').click(),
    ]);
    await page.goto('/checkout/');
    await expect(page.locator('[name="firstname"]').first()).toBeVisible();
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
export async function fillCheckout(page: Page, magewire: Magewire, overrides: Record<string, string> = {}): Promise<void> {
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
    await page.locator('[name="region"]').first().selectOption({ label: 'Texas' });
    await magewire.idle();
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
    await page.locator('[name="shipping-method-option"]').first().check();
    await magewire.idle();
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
    const data = JSON.parse(benchCommand('E2E_ASSERT_CMD', ['order', incrementId]));
    const byCode = (rows: { attribute_code: string; value: string; value_label: string }[] = []) =>
        Object.fromEntries(rows.map((row) => [row.attribute_code, { value: row.value, value_label: row.value_label }]));
    return {
        incrementId,
        snapshot: {
            customer: byCode(data.customer_attributes),
            billing: byCode(data.addresses?.billing),
            shipping: byCode(data.addresses?.shipping),
        },
    };
}
