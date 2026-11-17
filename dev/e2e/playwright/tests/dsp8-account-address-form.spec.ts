import type { Page } from '@playwright/test';
import { accountValues, customerEmail, deleteSavedAddresses, expect, test } from './helpers';

/**
 * DSP8: the account address form with the module's attributes: the required block, the rules (email, length, numeric, date range) and a
 * server error that keeps the typed values. The saved values and the defaults of a new address are in book-address-book / web-scope specs.
 */
test.afterEach(() => {
    deleteSavedAddresses(customerEmail());
});

async function fillBase(page: Page): Promise<void> {
    await page.locator('[name="firstname"]').fill('Account');
    await page.locator('[name="lastname"]').fill('Rules');
    await page.locator('[name="telephone"]').fill('5551230000');
    await page.locator('[name="street[]"]').first().fill('1 Rules Street');
    await page.locator('[name="postcode"]').fill('75001');
    await page.locator('[name="city"]').fill('Dallas');
    const country = page.locator('[name="country_id"]');
    if ((await country.inputValue()) !== 'US') {
        await country.selectOption({ label: 'United States' });
    }
    const region = page.locator('select[name="region_id"]');
    await region.waitFor({ state: 'visible', timeout: 5_000 });
    await expect.poll(async () => region.locator('option').count()).toBeGreaterThan(1);
    await region.selectOption({ label: 'Texas' });
}

const save = (page: Page) => page.getByRole('button', { name: /save address/i }).click();

test('DSP8: the required attribute blocks the form; broken rules are refused by the server, and the typed values are kept', { tag: ['@DSP8'] }, async ({ customerPage: page }) => {
    deleteSavedAddresses(customerEmail());
    await page.goto('/customer/address/new/');
    await fillBase(page);
    await page.locator('[name="e2e_a_text"]').fill('kept text');

    // the required block: empty, the form is not submitted
    await save(page);
    await expect(page).toHaveURL(/customer\/address\/new/);
    expect(await page.locator('[name="e2e_a_req"]').evaluate((element: HTMLInputElement) => element.validity.valueMissing || element.classList.contains('invalid') || element.getAttribute('aria-invalid') === 'true'), 'the required field is flagged').toBe(true);
    expect(Object.keys(accountValues(customerEmail()).addresses)).toEqual([]);

    // the rules: the form is sent straight to the server (no browser check), which refuses it and names the attributes
    await page.locator('[name="e2e_a_req"]').fill('required value');
    await page.locator('[name="e2e_a_email"]').fill('not-an-email');
    await page.locator('[name="e2e_a_len"]').fill('ab');
    await page.locator('[name="e2e_a_num"]').fill('abc');
    await page.locator('[name="e2e_a_date"]').fill('2031-01-01');
    await page.evaluate(() => (document.querySelector('form[action*="formPost"]') as HTMLFormElement).submit());
    await page.waitForLoadState('load');
    const text = await page.locator('body').innerText();
    for (const label of ['E2E Address Email', 'E2E Address Length', 'E2E Address Number', 'E2E Address Date']) {
        expect(text, `the message names ${label}`).toContain(label);
    }
    expect(Object.keys(accountValues(customerEmail()).addresses), 'nothing was saved').toEqual([]);
    // the form is shown again with what was typed
    await expect(page.locator('[name="e2e_a_text"]')).toHaveValue('kept text');
    await expect(page.locator('[name="e2e_a_req"]')).toHaveValue('required value');
    await expect(page.locator('[name="e2e_a_email"]')).toHaveValue('not-an-email');

    // valid values: saved
    await page.locator('[name="e2e_a_email"]').fill('someone@example.com');
    await page.locator('[name="e2e_a_len"]').fill('abcd');
    await page.locator('[name="e2e_a_num"]').fill('42');
    await page.locator('[name="e2e_a_date"]').fill('2027-05-06');
    await save(page);
    await expect(page).toHaveURL(/customer\/address(\/index)?\/?$/);
    const [saved] = Object.values(accountValues(customerEmail()).addresses);
    expect(saved).toMatchObject({ e2e_a_email: 'someone@example.com', e2e_a_len: 'abcd', e2e_a_num: '42', e2e_a_text: 'kept text' });
});
