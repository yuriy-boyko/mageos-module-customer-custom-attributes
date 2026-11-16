import type { Page } from '@playwright/test';
import { accountOptionOf, accountValues, customerEmail, deleteSavedAddresses, expect, test } from './helpers';

/**
 * RAD2: radio buttons (e2e_a_yn, optional yes/no) and a checkbox group (e2e_a_chk, multiple select) in the account address form: a new
 * address saves what is ticked, the edit form shows the saved values as checked, "None" is there because the attribute is optional, and
 * choosing it with nothing ticked clears both values.
 */
test.afterEach(() => {
    deleteSavedAddresses(customerEmail());
});

async function fillAddressForm(page: Page): Promise<void> {
    await page.locator('[name="firstname"]').fill('Account');
    await page.locator('[name="lastname"]').fill('Groups');
    await page.locator('[name="telephone"]').fill('5551230000');
    await page.locator('[name="street[]"]').first().fill('1 Groups Street');
    await page.locator('[name="postcode"]').fill('75001');
    await page.locator('[name="city"]').fill('Dallas');
    const country = page.locator('[name="country_id"]');
    if ((await country.inputValue()) !== 'US') {
        await country.selectOption({ label: 'United States' });
    }
    await page.locator('[name="e2e_a_req"]').fill('required value');
    const regionSelect = page.locator('select[name="region_id"]');
    await regionSelect.waitFor({ state: 'visible', timeout: 5_000 });
    await expect.poll(async () => regionSelect.locator('option').count()).toBeGreaterThan(1);
    await regionSelect.selectOption({ label: 'Texas' });
}

const save = (page: Page) => page.getByRole('button', { name: /save address/i }).click();

test('RAD2: the account address form saves the groups and shows the saved values as checked', { tag: ['@RAD2'] }, async ({ customerPage: page }) => {
    deleteSavedAddresses(customerEmail());
    await page.goto('/customer/address/new/');
    await fillAddressForm(page);

    // optional: the radio group has "None", checked at the start; nothing is ticked in the checkbox group
    await expect(accountOptionOf(page, 'e2e_a_yn', 'radio', 'None')).toBeChecked();
    for (const label of ['One', 'Two', 'Three']) {
        await expect(accountOptionOf(page, 'e2e_a_chk', 'checkbox', label)).not.toBeChecked();
    }
    await accountOptionOf(page, 'e2e_a_chk', 'checkbox', 'Two').check();
    await accountOptionOf(page, 'e2e_a_chk', 'checkbox', 'Three').check();
    await accountOptionOf(page, 'e2e_a_yn', 'radio', 'Yes').check();
    await save(page);
    await expect(page).toHaveURL(/customer\/address(\/index)?\/?$/);

    const [id, saved] = Object.entries(accountValues(customerEmail()).addresses)[0];
    expect(saved.e2e_a_chk.split(',')).toHaveLength(2);
    expect(saved.e2e_a_yn).toBe('1');

    // the edit form shows them
    await page.goto(`/customer/address/edit/id/${id}/`);
    await expect(accountOptionOf(page, 'e2e_a_chk', 'checkbox', 'One')).not.toBeChecked();
    await expect(accountOptionOf(page, 'e2e_a_chk', 'checkbox', 'Two')).toBeChecked();
    await expect(accountOptionOf(page, 'e2e_a_chk', 'checkbox', 'Three')).toBeChecked();
    await expect(accountOptionOf(page, 'e2e_a_yn', 'radio', 'Yes')).toBeChecked();

    // one box unticked: the other stays
    await accountOptionOf(page, 'e2e_a_chk', 'checkbox', 'Two').uncheck();
    await save(page);
    await expect(page).toHaveURL(/customer\/address(\/index)?\/?$/);
    expect(accountValues(customerEmail()).addresses[id].e2e_a_chk.split(',')).toHaveLength(1);

    // everything unticked and "None": both values are cleared
    await page.goto(`/customer/address/edit/id/${id}/`);
    await accountOptionOf(page, 'e2e_a_chk', 'checkbox', 'Three').uncheck();
    await accountOptionOf(page, 'e2e_a_yn', 'radio', 'None').check();
    await save(page);
    await expect(page).toHaveURL(/customer\/address(\/index)?\/?$/);
    const cleared = accountValues(customerEmail()).addresses[id];
    expect(cleared.e2e_a_chk ?? '').toBe('');
    expect(cleared.e2e_a_yn ?? '').toBe('');
});
