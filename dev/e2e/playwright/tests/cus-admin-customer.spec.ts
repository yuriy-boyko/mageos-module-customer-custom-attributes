import { accountValues, chosenLabels, customerEmail, customerTab, deleteSavedAddresses, expect, openCustomerEdit, setAccountValues, setSavedAddress, test } from './helpers';

/**
 * CUS11: a customer attribute value is editable in the admin customer form (Account Information) and saved.
 * BOOK4: an address value is editable in the admin customer's address modal (Addresses) and saved by core.
 * Fixtures: customer e2e_c_text, e2e_c_sel (Gold/Silver), e2e_c_multi (Opt 1-3), e2e_c_date, e2e_c_area, e2e_c_bool;
 * address e2e_a_text, e2e_a_sel (North/South), e2e_a_multi (Red/Green/Blue), e2e_a_date, e2e_a_area, e2e_a_bool.
 */
const email = customerEmail();

test.afterEach(() => {
    deleteSavedAddresses(email);
});

test('CUS11: a customer attribute value edited in the admin customer form is saved', { tag: ['@CUS11'] }, async ({ adminPage: page }) => {
    setAccountValues(email, { e2e_c_text: 'before', e2e_c_sel: 'Gold', e2e_c_multi: 'Opt 1', e2e_c_date: '2026-03-04', e2e_c_area: 'old', e2e_c_bool: '0' });
    await openCustomerEdit(page, email);
    await customerTab(page, 'Account Information');
    const field = (code: string) => page.locator(`[name="customer[${code}]"]`);
    await expect(field('e2e_c_text')).toHaveValue('before');
    await expect(field('e2e_c_sel')).toHaveValue(/.+/);

    await field('e2e_c_text').fill('After text é');
    await field('e2e_c_sel').selectOption({ label: 'Silver' });
    await field('e2e_c_multi').selectOption([{ label: 'Opt 2' }, { label: 'Opt 3' }]);
    await field('e2e_c_date').fill('12/31/2026');
    await field('e2e_c_area').fill('new line one\nnew line two');
    await field('e2e_c_bool').check({ force: true }); // a yes/no switch in this form
    await page.locator('#save').click();
    await expect(page.locator('.message-success')).toBeVisible({ timeout: 30_000 });

    const saved = accountValues(email).customer_attributes;
    expect(saved.e2e_c_text).toBe('After text é');
    expect(saved.e2e_c_sel).toMatch(/^\d+$/);
    expect(saved.e2e_c_multi).toMatch(/^\d+,\d+$/);
    expect(saved.e2e_c_date).toContain('2026-12-31');
    expect(saved.e2e_c_area).toBe('new line one\r\nnew line two');
    expect(saved.e2e_c_bool).toBe('1');

    // the save returns to the customer grid: open the customer again, the form shows the saved values
    await openCustomerEdit(page, email);
    await customerTab(page, 'Account Information');
    await expect(field('e2e_c_text')).toHaveValue('After text é');
    expect(await chosenLabels(field('e2e_c_sel'))).toEqual(['Silver']);
    expect(await chosenLabels(field('e2e_c_multi'))).toEqual(['Opt 2', 'Opt 3']);
});

test('BOOK4: an address value edited in the admin customer form is saved', { tag: ['@BOOK4'] }, async ({ adminPage: page }) => {
    setAccountValues(email, { e2e_c_text: 'seed text' });
    setSavedAddress(email, { e2e_a_text: 'Before address', e2e_a_sel: 'North', e2e_a_multi: 'Green', e2e_a_date: '2026-07-08', e2e_a_area: 'old', e2e_a_bool: '0', e2e_a_req: 'req', e2e_a_rdep: 'dep' });
    const before = Object.keys(accountValues(email).addresses);
    expect(before).toHaveLength(1);

    await openCustomerEdit(page, email);
    await customerTab(page, 'Addresses');
    // the "Edit" link next to the default billing address opens the address modal
    await page.getByText('Edit', { exact: true }).first().click();
    const modal = page.locator('.modal-slide._show, .modal-popup._show').first();
    const field = (code: string) => modal.locator(`[name="${code}"]`);
    await expect(field('e2e_a_text')).toHaveValue('Before address');
    expect(await chosenLabels(field('e2e_a_sel'))).toEqual(['North']);

    await field('e2e_a_text').fill('After address é');
    await field('e2e_a_sel').selectOption({ label: 'South' });
    await field('e2e_a_multi').selectOption([{ label: 'Red' }, { label: 'Blue' }]);
    await field('e2e_a_date').fill('12/31/2026');
    await field('e2e_a_area').fill('changed area');
    await field('e2e_a_bool').check({ force: true });
    // a date attribute of an earlier setup of this bench (cca_dr, range 2026-2027) refuses an empty value in core's form: give it one
    if (await field('cca_dr').count()) {
        await field('cca_dr').fill('6/1/2026');
    }
    await modal.getByRole('button', { name: 'Save' }).click();
    await expect(modal).toBeHidden({ timeout: 30_000 });

    const addresses = accountValues(email).addresses;
    expect(Object.keys(addresses)).toEqual(before);
    const saved = Object.values(addresses)[0];
    expect(saved.e2e_a_text).toBe('After address é');
    expect(saved.e2e_a_sel).toMatch(/^\d+$/);
    expect(saved.e2e_a_multi).toMatch(/^\d+,\d+$/);
    expect(saved.e2e_a_date).toContain('2026-12-31');
    expect(saved.e2e_a_area).toBe('changed area');
    expect(saved.e2e_a_bool).toBe('1');
});
