import { defineConfig } from '@playwright/test';
import * as path from 'path';

/**
 * Settings come from the environment (run-playwright.sh and run-all.sh set them):
 *   E2E_BASE_URL          store base URL (required), e.g. https://shop.example
 *   E2E_PRODUCT_URL       path of a simple in-stock product page, default /strive-shoulder-pack.html
 *   E2E_CUSTOMER_EMAIL, E2E_CUSTOMER_PASSWORD   the fixture customer (enables the logged-in tests)
 *   E2E_ADMIN_USER, E2E_ADMIN_PASSWORD          an admin account (enables the admin tests)
 *   E2E_VARIANT_CMD       command that switches the Hyvä Checkout variant: `<command> onepage|default|mobile`
 *   E2E_HEADED=1          show the browser
 *   E2E_TERSE=1           no console report, only the JSON file (E2E_PW_JSON) that run-all.sh summarises
 *   E2E_VARIANTS=1        also run the tests of the multi-step and mobile checkouts (tag @variants; run-all.sh --variants)
 */
const baseURL = process.env.E2E_BASE_URL;
if (!baseURL) {
    throw new Error('Set E2E_BASE_URL (see dev/e2e/README.md).');
}
const home = process.env.CCA_E2E_HOME || process.cwd();

export default defineConfig({
    testDir: './tests',
    globalSetup: './global-setup.ts',
    // The checkout holds one cart per browser session and the store state is shared: run one test at a time.
    workers: 1,
    fullyParallel: false,
    // the multi-step and mobile checkout tests run only when asked: the one-page checkout is the main target
    grepInvert: process.env.E2E_VARIANTS === '1' ? undefined : /@variants/,
    retries: 0,
    // the longest checkout tests (many Magewire round trips, two websites) take 60-100 s on the Warden bench
    timeout: 150_000,
    expect: { timeout: 10_000 },
    outputDir: path.join(home, 'test-results'),
    reporter: process.env.E2E_TERSE === '1'
        ? [['json', { outputFile: process.env.E2E_PW_JSON || path.join(home, 'pw.json') }]]
        : [['list'], ['json', { outputFile: process.env.E2E_PW_JSON || path.join(home, 'pw.json') }]],
    use: {
        baseURL,
        ignoreHTTPSErrors: true,
        headless: process.env.E2E_HEADED !== '1',
        viewport: { width: 1280, height: 900 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
});
