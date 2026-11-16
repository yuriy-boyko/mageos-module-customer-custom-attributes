import { accountOptionOf, accountValues, benchCommand, expect, test, uniqueEmail } from './helpers';

/**
 * CUS12 (registration form): customer attributes shown as radio buttons and checkboxes render, save, and follow the dependency rules.
 * The account edit form is in cus8-account-forms.spec.ts.
 *
 * Fixtures: e2e_c_sel (radio Gold/Silver) shows e2e_c_dep for Gold and the required e2e_c_rdep for Silver; e2e_c_multi is a checkbox
 * group; e2e_c_areq is required and in the account forms.
 */
// e2e_c_req and e2e_c_rmulti are required but only in the checkout forms: core then refuses every registration ("is a required value"),
// the same situation as VAL6 for addresses. They are optional for these tests and put back afterwards.
const CHECKOUT_ONLY = ['e2e_c_req', 'e2e_c_rmulti'];
test.beforeEach(() => {
    for (const code of CHECKOUT_ONLY) benchCommand('E2E_FIXTURE_CMD', ['set-attribute', code, 'required=0', 'forms=mageos_hyva_checkout,adminhtml_checkout']);
});
test.afterEach(() => {
    for (const code of CHECKOUT_ONLY) benchCommand('E2E_FIXTURE_CMD', ['set-attribute', code, 'required=1', 'forms=mageos_hyva_checkout,adminhtml_checkout']);
});

async function openRegistration(page: import('@playwright/test').Page): Promise<string> {
    const email = uniqueEmail('reg');
    await page.goto('/customer/account/create/');
    const form = page.locator('form[action*="createpost"]');
    await form.locator('[name="firstname"]').fill('Reg');
    await form.locator('[name="lastname"]').fill('Istration');
    await form.locator('[name="email"]').fill(email);
    const password = process.env.E2E_CUSTOMER_PASSWORD as string;
    await form.locator('[name="password"]').fill(password);
    await form.locator('[name="password_confirmation"]').fill(password);
    await form.locator('[name="e2e_c_areq"]').fill('registered required');
    return email;
}

const submit = (page: import('@playwright/test').Page) => page.locator('form[action*="createpost"] button[type="submit"]').click();

test('registration: radio and checkbox groups render and save with the rule for Gold', { tag: ['@CUS12', '@CUS3'] }, async ({ page }) => {
    test.skip(!process.env.E2E_CUSTOMER_PASSWORD, 'E2E_CUSTOMER_PASSWORD is not set');
    const email = await openRegistration(page);

    // rendered: radio group with None (optional), checkbox group, nothing chosen, both rule targets hidden
    await expect(accountOptionOf(page, 'e2e_c_sel', 'radio', 'Gold')).toBeVisible();
    await expect(accountOptionOf(page, 'e2e_c_sel', 'radio', 'Silver')).not.toBeChecked();
    await expect(accountOptionOf(page, 'e2e_c_multi', 'checkbox', 'Opt 1')).not.toBeChecked();
    const dependent = page.locator('[name="e2e_c_dep"]');
    const requiredDependent = page.locator('[name="e2e_c_rdep"]');
    await expect(dependent).toBeHidden();
    await expect(requiredDependent).toBeHidden();

    // Gold shows the text field; the required dependent stays hidden and does not block the form
    await accountOptionOf(page, 'e2e_c_sel', 'radio', 'Gold').check();
    await expect(dependent).toBeVisible();
    await expect(requiredDependent).toBeHidden();
    await dependent.fill('gold dependent');
    await accountOptionOf(page, 'e2e_c_multi', 'checkbox', 'Opt 1').check();
    await accountOptionOf(page, 'e2e_c_multi', 'checkbox', 'Opt 3').check();
    await submit(page);
    await expect(page).toHaveURL(/customer\/account\/?$/, { timeout: 30_000 });

    const saved = accountValues(email).customer_attributes;
    expect(saved.e2e_c_sel).toMatch(/^\d+$/);
    expect(saved.e2e_c_dep).toBe('gold dependent');
    expect(saved.e2e_c_multi).toMatch(/^\d+,\d+$/);
    expect(saved.e2e_c_areq).toBe('registered required');
    expect(saved.e2e_c_rdep ?? '').toBe('');
});

test('registration: Silver shows the required dependent, a value typed under Gold is dropped', { tag: ['@CUS12'] }, async ({ page }) => {
    test.skip(!process.env.E2E_CUSTOMER_PASSWORD, 'E2E_CUSTOMER_PASSWORD is not set');
    const email = await openRegistration(page);
    const dependent = page.locator('[name="e2e_c_dep"]');
    const requiredDependent = page.locator('[name="e2e_c_rdep"]');

    await accountOptionOf(page, 'e2e_c_sel', 'radio', 'Gold').check();
    await dependent.fill('typed under gold');
    await accountOptionOf(page, 'e2e_c_sel', 'radio', 'Silver').check();
    await expect(dependent).toBeHidden();
    await expect(dependent).toBeDisabled();
    await expect(requiredDependent).toBeVisible();
    await expect(requiredDependent).toBeEnabled();

    // required and empty: the form is not submitted
    await submit(page);
    await expect(page).toHaveURL(/customer\/account\/create/);
    expect(await requiredDependent.evaluate((element: HTMLInputElement) => element.validity.valueMissing)).toBe(true);

    await requiredDependent.fill('silver needs this');
    await submit(page);
    await expect(page).toHaveURL(/customer\/account\/?$/, { timeout: 30_000 });
    const saved = accountValues(email).customer_attributes;
    expect(saved.e2e_c_rdep).toBe('silver needs this');
    expect(saved.e2e_c_dep ?? '', 'the value typed while Gold was chosen is not saved').toBe('');
});
