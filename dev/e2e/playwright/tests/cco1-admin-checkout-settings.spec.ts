import { attributeRow, attributeTab, createAttributes, deleteCreated, expect, openAttribute, saveAttribute, test } from './helpers';

/**
 * CCO1: the admin form of a customer attribute always lists position, sort order and prefill for the checkout; they look inactive while
 * "Hyvä Checkout" is not selected in "Show in Forms", and they are saved with the attribute.
 */
const CODE = 'e2e_c_cco1';

test.afterEach(() => {
    deleteCreated(CODE);
});

test('CCO1: checkout position, sort order and prefill are always listed, look inactive without the checkout form and are saved', { tag: ['@CCO1'] }, async ({ adminPage: page }) => {
    createAttributes('customer', [CODE], ['customer_account_edit']);
    await openAttribute(page, 'customer', CODE);
    await attributeTab(page, 'Storefront Properties');
    const opacity = (id: string) => page.locator(`#${id}`).evaluate((node) => getComputedStyle(node.closest('.field') as HTMLElement).opacity);
    for (const id of ['checkout_position', 'checkout_sort', 'checkout_prefill']) {
        await expect(page.locator(`#${id}`), `${id} is listed`).toBeVisible();
        expect(await opacity(id), `${id} looks inactive without the checkout form`).toBe('0.5');
    }

    // ticking the checkout form makes them look active; they are saved
    await page.locator('#used_in_forms').selectOption(['customer_account_edit', 'mageos_hyva_checkout']);
    for (const id of ['checkout_position', 'checkout_sort', 'checkout_prefill']) {
        expect(await opacity(id), `${id} looks active with the checkout form`).toBe('1');
    }
    await page.locator('#checkout_position').selectOption('payment_above');
    await page.locator('#checkout_sort').fill('12');
    await page.locator('#checkout_prefill').selectOption('1');
    await saveAttribute(page, true);
    await expect(page.locator('.message-success')).toBeVisible();

    const row = attributeRow('customer', CODE);
    expect(row.forms).toContain('mageos_hyva_checkout');
    expect(JSON.stringify(row.rules)).toContain('payment_above');
    expect(JSON.stringify(row.rules)).toMatch(/12/);
    await attributeTab(page, 'Storefront Properties');
    await expect(page.locator('#checkout_position')).toHaveValue('payment_above');
    await expect(page.locator('#checkout_sort')).toHaveValue('12');
});
