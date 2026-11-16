import * as fs from 'fs';
import { customerEmail, dropQuotes, expect, setAccountValues, startAdminOrder, test } from './helpers';

/**
 * ROB4 (measurement): request count and load time of the pages the module changes. It does nothing in a normal run: dev/e2e/compare-module-off.sh
 * runs it twice, once with the module and once with it disabled, with E2E_ROB4_OUT naming the file for the numbers, and compares them.
 *
 * Time = until the DOM is ready; requests = the shop's own, counted until 1.5 s later. Every page is loaded several times; the first load is a warm-up and is dropped. The numbers are medians (milliseconds and requests).
 */
const OUT = process.env.E2E_ROB4_OUT;
const LOADS = 7;

const median = (values: number[]): number => [...values].sort((a, b) => a - b)[Math.floor(values.length / 2)];

test('ROB4: request count and load time of the pages the module changes', { tag: ['@ROB4~'] }, async ({ page, customerPage, adminPage }) => {
    test.skip(!OUT, 'E2E_ROB4_OUT is not set (this is a measurement, see compare-module-off.sh)');
    test.setTimeout(600_000);
    if (!process.env.E2E_ROB4_SKIP_SETUP) {
        // with the module disabled the bench commands cannot run: the first measurement prepares the customer, the second reuses it
        setAccountValues(customerEmail(), { e2e_c_text: 'rob4' });
        dropQuotes(customerEmail());
    }
    const results: Record<string, { ms: number; requests: number; ttfb: number }> = {};

    const measure = async (name: string, target: typeof page, url: string) => {
        const times: number[] = [];
        const counts: number[] = [];
        const firstBytes: number[] = [];
        for (let i = 0; i < LOADS; i++) {
            let requests = 0;
            // only the shop's own requests: the checkout loads about 80 more from Stripe, PayPal, Amazon and Google, which vary from load to load
            const count = (request: { url(): string }) => { if (request.url().startsWith(new URL(url, 'https://app.magento249.test').origin)) requests++; };
            target.on('request', count);
            let start = Date.now();
            // the checkout never goes network-idle and its load event waits for the payment widgets of third parties: time to the DOM, then a
            // fixed settle time for the request count
            // the admin order page is sometimes still redirecting when the next load starts (net::ERR_ABORTED): that load is repeated
            for (let attempt = 0; ; attempt++) {
                start = Date.now();
                try {
                    await target.goto(url, { waitUntil: 'domcontentloaded' });
                    break;
                } catch (error) {
                    if (attempt >= 3 || !String(error).includes('ERR_ABORTED')) throw error;
                    await target.waitForTimeout(1000);
                }
            }
            const elapsed = Date.now() - start;
            await target.waitForTimeout(1500);
            target.off('request', count);
            // time to first byte of the page itself: the server's work (the module's queries and rendering) without the browser's
            const firstByte = await target.evaluate(() => {
                const entry = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming;
                return Math.round(entry.responseStart - entry.requestStart);
            });
            if (i > 0) {
                times.push(elapsed);
                counts.push(requests);
                firstBytes.push(firstByte);
            }
        }
        results[name] = { ms: median(times), requests: median(counts), ttfb: median(firstBytes) };
    };

    await measure('home (control)', page, '/');
    await measure('registration', page, '/customer/account/create/');
    await measure('account edit (customer)', customerPage, '/customer/account/edit/');
    await measure('new address (customer)', customerPage, '/customer/address/new/');
    // the checkout needs a cart
    await page.goto('/strive-shoulder-pack.html');
    await Promise.all([page.waitForResponse((response) => response.url().includes('/checkout/cart/add')), page.locator('#product-addtocart-button').click()]);
    await measure('checkout (guest)', page, '/checkout/');

    // a signed-in customer with a cart: the checkout also prefills the customer sections from the account
    await customerPage.goto('/strive-shoulder-pack.html');
    await Promise.all([customerPage.waitForResponse((response) => response.url().includes('/checkout/cart/add')), customerPage.locator('#product-addtocart-button').click()]);
    await measure('checkout (logged in)', customerPage, '/checkout/');

    // the admin order create page for the customer (the form holds the customer and address attributes): the page is reloaded with the
    // customer and store view kept in the admin session
    await startAdminOrder(adminPage, customerEmail());
    const orderCreate = adminPage.url();
    await measure('admin order create', adminPage, orderCreate);

    fs.writeFileSync(OUT as string, JSON.stringify(results, null, 2));
    expect(Object.keys(results)).toHaveLength(7);
});
