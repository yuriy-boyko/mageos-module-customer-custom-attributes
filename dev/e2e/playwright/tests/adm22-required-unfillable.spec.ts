import { benchCommand, deleteCreated, expect, openAttribute, openGrid, test } from './helpers';

/**
 * ADM22: a required customer attribute that customers cannot fill in. Magento checks every required customer attribute on each customer
 * save, so one that is not in the registration or account edit form makes registrations or account saves fail. The grid lists such
 * attributes, the edit page says which form is missing.
 */
const CODE = 'e2e_c_requnfill';

test.afterEach(() => {
    deleteCreated(CODE);
});

const gridWarning = (page: import('@playwright/test').Page) => page.locator('.message-warning', { hasText: 'customers cannot fill them in' });

test('ADM22: the grid and the edit page warn about a required attribute missing from an account form', { tag: ['@ADM22'] }, async ({ adminPage: page }) => {
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', '--forms=customer_account_create', CODE]);
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', CODE, 'required=1', 'forms=customer_account_create']);

    await openGrid(page, 'customer');
    await expect(gridWarning(page)).toContainText(CODE);

    await openAttribute(page, 'customer', CODE);
    const edit = page.locator('.message-warning', { hasText: `"ADM ${CODE}" is required, but not in the account edit form` });
    await expect(edit).toBeVisible();
    await expect(edit).toContainText('Customer Account Edit');

    // in both account forms: nothing to warn about
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', CODE, 'required=1', 'forms=customer_account_create,customer_account_edit']);
    await openGrid(page, 'customer');
    const text = (await gridWarning(page).count()) > 0 ? await gridWarning(page).innerText() : '';
    expect(text, 'not listed any more').not.toContain(CODE);
    await openAttribute(page, 'customer', CODE);
    await expect(page.locator('.message-warning', { hasText: `"ADM ${CODE}" is required` })).toHaveCount(0);

    // optional again: never listed
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', CODE, 'required=0', 'forms=customer_account_create']);
    await openGrid(page, 'customer');
    const optional = (await gridWarning(page).count()) > 0 ? await gridWarning(page).innerText() : '';
    expect(optional).not.toContain(CODE);
});

test('ADM22: a required attribute the customer cannot change is listed even when it is in the account forms', { tag: ['@ADM22'] }, async ({ adminPage: page }) => {
    const forms = 'customer_account_create,customer_account_edit';
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'customer', `--forms=${forms}`, '--admin-only', CODE]);
    // made required behind the screen's back (the screen refuses required with admin-only in the account forms), like a data patch can
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', CODE, 'required=1', `forms=${forms}`]);

    await openGrid(page, 'customer');
    await expect(gridWarning(page)).toContainText(CODE);
    await openAttribute(page, 'customer', CODE);
    await expect(page.locator('.message-warning', { hasText: `"ADM ${CODE}" is required, but customers cannot fill it in` })).toBeVisible();
});
