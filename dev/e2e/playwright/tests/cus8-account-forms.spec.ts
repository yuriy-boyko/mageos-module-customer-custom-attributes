import { accountOptionOf, accountValues, customerEmail, expect, setAccountValues, test } from './helpers';

/**
 * The account information form (Hyvä account area) with customer attributes.
 * CUS8: a required customer attribute left empty shows a message and nothing is saved.
 * RAD7: attributes shown as radio buttons and checkboxes render with their saved values and save.
 * CUS12: dependency rules in the account edit form (a dependent field shows for its option, a hidden required one does not block);
 *   the registration form is in cus12-registration-form.spec.ts.
 *
 * Fixtures: e2e_c_areq is required and only on the account forms; e2e_c_sel (radio) shows e2e_c_dep for Gold and the required
 * e2e_c_rdep for Silver; e2e_c_multi is a checkbox group.
 */
const account = {
    e2e_c_areq: 'account required',
    e2e_c_text: 'account text',
    e2e_c_sel: 'Gold',
    e2e_c_multi: 'Opt 2',
};

test.beforeEach(() => {
    setAccountValues(customerEmail(), account);
});

const save = (page: import('@playwright/test').Page) => page.locator('form[action*="editPost"] button[type="submit"]').click();

test('a required customer attribute left empty is not saved', { tag: ['@CUS8'] }, async ({ customerPage: page }) => {
    const before = accountValues(customerEmail()).customer_attributes;
    await page.goto('/customer/account/edit/');
    const required = page.locator('[name="e2e_c_areq"]');
    await expect(required).toBeVisible();
    await expect(required).toHaveValue('account required');

    // empty the required field and change another one: the form is not submitted, nothing is saved
    await required.fill('');
    await page.locator('[name="e2e_c_text"]').fill('must not be saved');
    await save(page);
    await expect(page).toHaveURL(/customer\/account\/edit/);
    expect(await required.evaluate((element: HTMLInputElement) => element.validity.valueMissing)).toBe(true);
    await expect(page.getByText(/required/i).first()).toBeVisible();
    expect(accountValues(customerEmail()).customer_attributes).toEqual(before);

    // positive control: with a value the form is saved
    await required.fill('now filled');
    await save(page);
    await expect(page).toHaveURL(/customer\/account\/?$/);
    await expect(page.getByText(/You saved the account information/i).first()).toBeVisible();
    const after = accountValues(customerEmail()).customer_attributes;
    expect(after.e2e_c_areq).toBe('now filled');
    expect(after.e2e_c_text).toBe('must not be saved');
});

test('radio buttons and checkboxes render with their saved values and save; rules show and hide fields', { tag: ['@RAD7', '@CUS12'] }, async ({ customerPage: page }) => {
    await page.goto('/customer/account/edit/');

    // rendered with the saved values (Gold, Opt 2)
    await expect(accountOptionOf(page, 'e2e_c_sel', 'radio', 'Gold')).toBeChecked();
    await expect(accountOptionOf(page, 'e2e_c_multi', 'checkbox', 'Opt 2')).toBeChecked();
    await expect(accountOptionOf(page, 'e2e_c_multi', 'checkbox', 'Opt 1')).not.toBeChecked();

    // the rule: Gold shows the text field, the required one is hidden and disabled
    const dependent = page.locator('[name="e2e_c_dep"]');
    const requiredDependent = page.locator('[name="e2e_c_rdep"]');
    await expect(dependent).toBeVisible();
    await expect(requiredDependent).toBeHidden();
    await expect(requiredDependent).toBeDisabled();

    // Silver swaps them
    await accountOptionOf(page, 'e2e_c_sel', 'radio', 'Silver').check();
    await expect(requiredDependent).toBeVisible();
    await expect(requiredDependent).toBeEnabled();
    await expect(dependent).toBeHidden();
    await expect(dependent).toBeDisabled();

    // a shown required dependent left empty is not saved (the account holds a value for it, so empty it first)
    await requiredDependent.fill('');
    await accountOptionOf(page, 'e2e_c_multi', 'checkbox', 'Opt 1').check();
    await accountOptionOf(page, 'e2e_c_multi', 'checkbox', 'Opt 2').uncheck();
    await accountOptionOf(page, 'e2e_c_multi', 'checkbox', 'Opt 3').check();
    await save(page);
    await expect(page).toHaveURL(/customer\/account\/edit/);
    expect(await requiredDependent.evaluate((element: HTMLInputElement) => element.validity.valueMissing)).toBe(true);

    // filled: saved
    await requiredDependent.fill('silver needs this');
    await save(page);
    await expect(page).toHaveURL(/customer\/account\/?$/);

    // the saved values come back
    await page.goto('/customer/account/edit/');
    await expect(accountOptionOf(page, 'e2e_c_sel', 'radio', 'Silver')).toBeChecked();
    await expect(accountOptionOf(page, 'e2e_c_multi', 'checkbox', 'Opt 1')).toBeChecked();
    await expect(accountOptionOf(page, 'e2e_c_multi', 'checkbox', 'Opt 3')).toBeChecked();
    await expect(accountOptionOf(page, 'e2e_c_multi', 'checkbox', 'Opt 2')).not.toBeChecked();
    await expect(page.locator('[name="e2e_c_rdep"]')).toHaveValue('silver needs this');
});
