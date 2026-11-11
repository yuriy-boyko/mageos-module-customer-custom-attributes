import { chromium, FullConfig } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

/**
 * Signs in once and keeps the sessions for the tests (Playwright "storage state"): the fixture customer on the storefront and an admin
 * in the backend. A session is only created when its credentials are set; tests that need one are skipped otherwise (see helpers.ts).
 */
export const authDir = (): string => path.join(process.env.CCA_E2E_HOME || process.cwd(), '.auth');

export default async function globalSetup(config: FullConfig): Promise<void> {
    const baseURL = config.projects[0].use.baseURL as string;
    fs.mkdirSync(authDir(), { recursive: true });
    for (const file of ['customer.json', 'admin.json']) {
        fs.rmSync(path.join(authDir(), file), { force: true });
    }
    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true });
    const page = await context.newPage();

    const email = process.env.E2E_CUSTOMER_EMAIL || 'e2e.customer@example.com';
    if (process.env.E2E_CUSTOMER_PASSWORD) {
        await page.goto('/customer/account/login/');
        const form = page.locator('form[action*="loginPost"]');
        await form.locator('[name="login[username]"]').fill(email);
        await form.locator('[name="login[password]"]').fill(process.env.E2E_CUSTOMER_PASSWORD);
        await form.locator('button[type="submit"]').click();
        await page.waitForURL(/customer\/account/, { timeout: 20_000 }).catch(() => undefined);
        if (/customer\/account\/login/.test(page.url())) {
            console.warn('global setup: the customer could not sign in; logged-in tests will be skipped.');
        } else {
            await context.storageState({ path: path.join(authDir(), 'customer.json') });
        }
    }

    if (process.env.E2E_ADMIN_USER && process.env.E2E_ADMIN_PASSWORD) {
        const adminContext = await browser.newContext({ baseURL, ignoreHTTPSErrors: true });
        const adminPage = await adminContext.newPage();
        await adminPage.goto('/backend/');
        await adminPage.locator('#username').fill(process.env.E2E_ADMIN_USER);
        await adminPage.locator('#login').fill(process.env.E2E_ADMIN_PASSWORD);
        await adminPage.locator('.action-login').click();
        await adminPage.waitForURL(/dashboard|admin/, { timeout: 30_000 }).catch(() => undefined);
        if (await adminPage.locator('#username').count()) {
            console.warn('global setup: the admin could not sign in; admin tests will be skipped.');
        } else {
            await adminContext.storageState({ path: path.join(authDir(), 'admin.json') });
        }
    }
    await browser.close();
}
