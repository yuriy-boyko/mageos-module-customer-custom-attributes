import { accountValues, customerEmail, deleteSavedAddresses, expect, test } from './helpers';
import type { Page } from '@playwright/test';

/**
 * DEP14: the account address form is saved although a required attribute is hidden by a rule; a required attribute that is shown and
 * empty blocks the save.
 *
 * Fixture: e2e_a_rdep is required and shown when e2e_a_rsel is "Show". A hidden field is disabled, so the browser does not validate it
 * and the form is submitted without it.
 */
async function fillAddressForm(page: Page, street: string): Promise<void> {
    await page.goto('/customer/address/new/');
    await page.locator('[name="firstname"]').fill('Account');
    await page.locator('[name="lastname"]').fill('Address');
    await page.locator('[name="telephone"]').fill('5551230000');
    await page.locator('[name="street[]"]').first().fill(street);
    await page.locator('[name="postcode"]').fill('75001');
    await page.locator('[name="city"]').fill('Dallas');
    // the store's default country is United States: selecting it again would reload the regions a second time, and the theme's debounced
    // region handler fails when the list is replaced under it
    const country = page.locator('[name="country_id"]');
    if ((await country.inputValue()) !== 'US') {
        await country.selectOption({ label: 'United States' });
    }
    await page.locator('[name="e2e_a_req"]').fill('required value');
    // the region control depends on the country and is swapped by script a moment after the country is chosen: a select once the
    // country's regions are loaded, a text input for a country without regions
    const regionSelect = page.locator('select[name="region_id"]');
    const loaded = await regionSelect.waitFor({ state: 'visible', timeout: 5_000 }).then(() => true, () => false);
    if (loaded) {
        // the theme's region script fails when a region is picked before the list of regions has been loaded: wait for the options
        await expect.poll(async () => regionSelect.locator('option').count()).toBeGreaterThan(1);
        await regionSelect.selectOption({ label: 'Texas' });
    } else {
        await page.locator('input[name="region"]').fill('Texas');
    }
}

test('an address with a required attribute hidden by a rule is saved', { tag: ['@DEP14'] }, async ({ customerPage: page }) => {
    await fillAddressForm(page, '1 Hidden Required Street');
    const dependent = page.locator('[name="e2e_a_rdep"]');
    await expect(dependent).toBeHidden();
    await expect(dependent).toBeDisabled();

    await page.getByRole('button', { name: /save address/i }).click();
    await expect(page).toHaveURL(/customer\/address(\/index)?\/?$/);
    await expect(page.getByText('1 Hidden Required Street').first()).toBeVisible();
});

test('an address with a shown required attribute left empty is not saved', { tag: ['@DEP14~'] }, async ({ customerPage: page }) => {
    await fillAddressForm(page, '2 Shown Required Street');
    await page.locator('[name="e2e_a_rsel"]').selectOption({ label: 'Show' });
    const dependent = page.locator('[name="e2e_a_rdep"]');
    await expect(dependent).toBeVisible();
    await expect(dependent).toBeEnabled();

    await page.getByRole('button', { name: /save address/i }).click();
    // blocked by the browser: still on the form, the field reports the missing value
    await expect(page).toHaveURL(/customer\/address\/(new|edit)/);
    expect(await dependent.evaluate((element: HTMLInputElement) => element.validity.valueMissing)).toBe(true);

    // positive control: with a value the address is saved
    await dependent.fill('now filled');
    await page.getByRole('button', { name: /save address/i }).click();
    await expect(page).toHaveURL(/customer\/address(\/index)?\/?$/);
    await expect(page.getByText('2 Shown Required Street').first()).toBeVisible();
});

test('DEP17: a value posted for a field hidden by a rule is not saved', { tag: ['@DEP17'] }, async ({ customerPage: page }) => {
    deleteSavedAddresses(customerEmail());
    try {
        await fillAddressForm(page, '3 Posted Anyway Street');
        const dependent = page.locator('[name="e2e_a_rdep"]');
        await expect(dependent).toBeDisabled();
        // a hand-made request: the form as the browser would send it, plus a value for the hidden field
        const status = await dependent.evaluate(async (element: HTMLInputElement) => {
            const form = element.form as HTMLFormElement;
            const data = new FormData(form);
            data.set('e2e_a_rdep', 'posted anyway');
            const response = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' });
            return response.status;
        });
        expect(status).toBeLessThan(400);
        const saved = Object.values(accountValues(customerEmail()).addresses);
        expect(saved).toHaveLength(1);
        expect(saved[0].e2e_a_req).toBe('required value');
        expect(saved[0].e2e_a_rdep ?? '', 'the hidden value is not stored').toBe('');
    } finally {
        deleteSavedAddresses(customerEmail());
    }
});
