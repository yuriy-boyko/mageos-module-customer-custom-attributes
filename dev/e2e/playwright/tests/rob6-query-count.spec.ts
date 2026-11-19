import * as fs from 'fs';
import type { APIRequestContext } from '@playwright/test';
import { benchCommand, customerEmail, dropQuotes, expect, openCheckoutAsGuest, setAccountValues, test } from './helpers';

/**
 * ROB6 (measurement): the SQL statements the module causes on the pages it changes. It does nothing in a normal run:
 * dev/e2e/count-queries.sh switches Magento's query log on (with call stacks), sets E2E_QUERY_LOG and runs it.
 *
 * Every page is fetched twice through the browser's own session (cookies, cart, login): the first fetch warms the caches, the
 * second one is counted. Only the HTML of the page is fetched, not the requests the browser makes after it, so the count is the
 * same on every run. A statement is the module's when module code is in its call stack (assertions.php query-log count).
 *
 * The limits are above the numbers measured when this test was written, with room for small changes. A failure means a change made
 * the module ask the database more often: read the statements in the output (or in E2E_QUERY_OUT) and find the loop.
 */
// measured with the test fixtures (2026-11): home 0, registration 6, account edit 6, new address 13 (one options read per dropdown),
// guest checkout 15, logged-in checkout 16
const LIMITS: Record<string, number> = {
    'home page': 3,
    'registration form': 10,
    'account edit form': 10,
    'account new address form': 20,
    'guest checkout': 22,
    'logged-in checkout': 24,
};

type Count = { all: number; module: number; statements: Record<string, number> };

async function count(request: APIRequestContext, url: string): Promise<Count> {
    const fetch = async () => {
        const response = await request.get(url, { maxRedirects: 0 });
        expect(response.status(), `${url} answers without a redirect`).toBe(200);
    };
    await fetch();
    benchCommand('E2E_ASSERT_CMD', ['query-log', 'reset']);
    await fetch();
    return JSON.parse(benchCommand('E2E_ASSERT_CMD', ['query-log', 'count'])) as Count;
}

test('ROB6: database statements of the module on the pages it changes', { tag: ['@ROB6'] }, async ({ page, customerPage }) => {
    test.skip(!process.env.E2E_QUERY_LOG, 'E2E_QUERY_LOG is not set (this is a measurement, see count-queries.sh)');
    test.setTimeout(300_000);
    // a customer with the required account values (the checkout sends a customer without them to the account form first)
    setAccountValues(customerEmail(), { e2e_c_text: 'rob6', e2e_c_req: 'account required' });
    dropQuotes(customerEmail());

    const results: Record<string, Count> = {};
    results['home page'] = await count(page.request, '/');
    results['registration form'] = await count(page.request, '/customer/account/create/');
    await openCheckoutAsGuest(page);
    results['guest checkout'] = await count(page.request, '/checkout/');
    results['account edit form'] = await count(customerPage.request, '/customer/account/edit/');
    results['account new address form'] = await count(customerPage.request, '/customer/address/new/');
    await openCheckoutAsGuest(customerPage);
    results['logged-in checkout'] = await count(customerPage.request, '/checkout/');

    if (process.env.E2E_QUERY_OUT) {
        fs.writeFileSync(process.env.E2E_QUERY_OUT, JSON.stringify(results, null, 2));
    }
    for (const [name, result] of Object.entries(results)) {
        console.log(`${name}: ${result.module} of ${result.all} statements from the module (limit ${LIMITS[name]})`);
    }
    for (const [name, result] of Object.entries(results)) {
        expect.soft(result.module, `${name}: ${JSON.stringify(result.statements, null, 1)}`).toBeLessThanOrEqual(LIMITS[name]);
    }
});
