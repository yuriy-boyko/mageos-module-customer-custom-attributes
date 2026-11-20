import type { Page } from '@playwright/test';
import { adminOpen, expect, field, openCheckoutAsGuest, test } from './helpers';

/**
 * DEP3 (browser): the relations screen refuses a rule that cannot work and names the rule number; DEP1 (part): a valid relation is saved,
 * listed and deleted again.
 *
 * The screen only offers valid choices, so the invalid ones are put into the form by script, as a hand-made request would: an option that
 * does not belong to the parent, a text attribute as the parent, an attribute depending on itself, a duplicate, an empty name, no rules.
 * Fixtures: address attributes e2e_a_sel (dropdown North/South), e2e_a_yn (yes/no), e2e_a_text (text), e2e_a_warn (text, in no relation).
 */
async function newRelation(page: Page, name: string): Promise<void> {
    await adminOpen(page, 'mageos_cca/relation');
    await page.locator('#mageos-cca-relation-new').click();
    await expect(page).toHaveURL(/mageos_cca\/relation\/new/);
    await page.locator('#mageos-cca-relation-name').fill(name);
    await expect(page.locator('#mageos-cca-relation-entity')).toHaveValue('address');
}

/** Sets the three selects of a rule row; a value the screen does not offer is added as an option first. */
async function setRule(page: Page, row: number, rule: { parent: string; option: string; dependent: string }): Promise<void> {
    await page.evaluate(({ row, rule }) => {
        const selects = document.querySelectorAll('#mageos-cca-relation-rules tbody tr')[row].querySelectorAll('select');
        const choose = (select: HTMLSelectElement, value: string) => {
            if (![...select.options].some((option) => option.value === value)) {
                select.add(new Option(value, value));
            }
            select.value = value;
        };
        choose(selects[0] as HTMLSelectElement, rule.parent);
        selects[0].dispatchEvent(new Event('change'));
        choose(selects[1] as HTMLSelectElement, rule.option);
        choose(selects[2] as HTMLSelectElement, rule.dependent);
    }, { row, rule });
}

/** Saves without the browser's own checks and returns the error messages of the page. */
async function saveRefused(page: Page): Promise<string> {
    await page.evaluate(() => {
        (document.getElementById('mageos-cca-relation-form') as HTMLFormElement).noValidate = true;
        document.getElementById('mageos-cca-relation-name')?.removeAttribute('required');
    });
    await page.locator('#mageos-cca-relation-save').click();
    await expect(page.locator('.message-error').first()).toBeVisible();
    return (await page.locator('.message-error').allInnerTexts()).join(' | ').replace(/\s+/g, ' ');
}

test('DEP3: a rule that cannot work is refused with the rule number', { tag: ['@DEP3'] }, async ({ adminPage: page }) => {
    // an empty name
    await newRelation(page, '');
    await setRule(page, 0, { parent: 'e2e_a_sel', option: '', dependent: 'e2e_a_warn' });
    expect(await saveRefused(page)).toContain('Please enter a name for the relation.');

    // no usable rule (the one empty row)
    await newRelation(page, 'E2E refused');
    expect(await saveRefused(page)).toMatch(/Please add at least one rule\.|Rule 1: the parent attribute is not valid/);

    // an option that is not an option of the parent
    await newRelation(page, 'E2E refused');
    await setRule(page, 0, { parent: 'e2e_a_sel', option: '99999999', dependent: 'e2e_a_warn' });
    expect(await saveRefused(page)).toContain('Rule 1: the option is not an option of the parent attribute.');

    // a text attribute as the parent
    await newRelation(page, 'E2E refused');
    await setRule(page, 0, { parent: 'e2e_a_text', option: '1', dependent: 'e2e_a_warn' });
    expect(await saveRefused(page)).toContain('Rule 1: the parent attribute is not valid. Only dropdown, multiple select and yes/no attributes can be parents.');

    // an attribute that depends on itself (the number is the rule's, here the second)
    await newRelation(page, 'E2E refused');
    await setRule(page, 0, { parent: 'e2e_a_sel', option: '', dependent: 'e2e_a_warn' });
    await page.locator('#mageos-cca-relation-add').click();
    const north = await page.evaluate(() => {
        const selects = document.querySelectorAll('#mageos-cca-relation-rules tbody tr')[1].querySelectorAll('select');
        (selects[0] as HTMLSelectElement).value = 'e2e_a_sel';
        selects[0].dispatchEvent(new Event('change'));
        return [...(selects[1] as HTMLSelectElement).options].find((option) => option.text.trim() === 'North')?.value ?? '';
    });
    expect(north, 'the option North is offered for e2e_a_sel').not.toBe('');
    await setRule(page, 1, { parent: 'e2e_a_sel', option: north, dependent: 'e2e_a_sel' });
    expect(await saveRefused(page)).toContain('Rule 2: an attribute cannot depend on itself.');

    // a duplicate of an earlier rule
    await newRelation(page, 'E2E refused');
    await setRule(page, 0, { parent: 'e2e_a_sel', option: '', dependent: 'e2e_a_warn' });
    await page.locator('#mageos-cca-relation-add').click();
    await setRule(page, 1, { parent: 'e2e_a_sel', option: '', dependent: 'e2e_a_warn' });
    const first = await page.evaluate(() => {
        const rows = document.querySelectorAll('#mageos-cca-relation-rules tbody tr');
        const offered = (row: number) => [...(rows[row].querySelectorAll('select')[1] as HTMLSelectElement).options].find((option) => option.text.trim() === 'North')?.value ?? '';
        return [offered(0), offered(1)];
    });
    await page.evaluate((value) => {
        document.querySelectorAll('#mageos-cca-relation-rules tbody tr').forEach((row) => {
            (row.querySelectorAll('select')[1] as HTMLSelectElement).value = value;
        });
    }, first[0]);
    expect(await saveRefused(page)).toContain('Rule 2 is a duplicate of an earlier rule.');
});

test('DEP2: rules that form a circle are refused', { tag: ['@DEP2'] }, async ({ adminPage: page }) => {
    // e2e_a_sel (North) shows e2e_a_rsel and e2e_a_rsel (Show) shows e2e_a_sel: each would depend on the other
    await newRelation(page, 'E2E refused');
    const row = (index: number) => page.locator('#mageos-cca-relation-rules tbody tr').nth(index).locator('select');
    await row(0).nth(0).selectOption('e2e_a_sel');
    await row(0).nth(1).selectOption({ label: 'North' });
    await row(0).nth(2).selectOption('e2e_a_rsel');
    await page.locator('#mageos-cca-relation-add').click();
    await row(1).nth(0).selectOption('e2e_a_rsel');
    await row(1).nth(1).selectOption({ label: 'Show' });
    await row(1).nth(2).selectOption('e2e_a_sel');
    const message = await saveRefused(page);
    expect(message).toMatch(/The rules form a circle: an attribute would depend on itself through other attributes \(.*e2e_a_.*\)/);
});

test('DEP3: a valid relation is saved, listed and deleted again', { tag: ['@quick', '@DEP3', '@DEP1'] }, async ({ adminPage: page }) => {
    await newRelation(page, 'E2E browser relation');
    await page.locator('#mageos-cca-relation-rules tbody tr').first().locator('select').nth(0).selectOption('e2e_a_yn');
    await page.locator('#mageos-cca-relation-rules tbody tr').first().locator('select').nth(1).selectOption({ index: 1 });
    await page.locator('#mageos-cca-relation-rules tbody tr').first().locator('select').nth(2).selectOption('e2e_a_warn');
    await page.locator('#mageos-cca-relation-save-continue').click();
    await expect(page.locator('.message-success')).toBeVisible();
    await expect(page.locator('#mageos-cca-relation-name')).toHaveValue('E2E browser relation');
    await expect(page.locator('#mageos-cca-relation-rules tbody tr')).toHaveCount(1);

    // delete it again through the screen
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#mageos-cca-relation-delete').click();
    await expect(page.locator('.message-success')).toBeVisible();
    await expect(page.locator('tr.mageos-cca-relation-row', { hasText: 'E2E browser relation' })).toHaveCount(0);
});

const EDITED = 'E2E edited relation';

/** Deletes a relation of that name through the screen when a failed run left it behind. */
async function deleteRelationNamed(page: Page, name: string): Promise<void> {
    await adminOpen(page, 'mageos_cca/relation');
    const row = page.locator('tr.mageos-cca-relation-row', { hasText: name });
    if ((await row.count()) === 0) {
        return;
    }
    await row.first().click();
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#mageos-cca-relation-delete').click();
    await expect(page.locator('.message-success')).toBeVisible();
}

test.describe('DEP1 list and edit', () => {
    test.afterEach(async ({ adminPage: page }) => {
        await deleteRelationNamed(page, EDITED);
        await deleteRelationNamed(page, 'E2E listed relation');
    });

    test('DEP1: the list shows a saved relation; it opens from the list, is changed and saved, and the changed rules apply in the checkout', { tag: ['@DEP1'] }, async ({ adminPage: page, page: shop }) => {
        // a new relation, saved with "Save": back on the list with its name, entity and number of rules
        await newRelation(page, 'E2E listed relation');
        const rows = page.locator('#mageos-cca-relation-rules tbody tr');
        await rows.first().locator('select').nth(0).selectOption('e2e_a_yn');
        await rows.first().locator('select').nth(1).selectOption({ label: 'Yes' });
        await rows.first().locator('select').nth(2).selectOption('e2e_a_warn');
        await page.locator('#mageos-cca-relation-save').click();
        await expect(page).toHaveURL(/mageos_cca\/relation\/(index)?/);
        await expect(page.locator('.message-success')).toBeVisible();
        const listed = page.locator('tr.mageos-cca-relation-row', { hasText: 'E2E listed relation' });
        await expect(listed).toHaveCount(1);
        await expect(listed.locator('td').nth(1)).not.toBeEmpty();
        await expect(listed.locator('td').nth(2)).toHaveText('1');

        // opened from the list (a click on the row), renamed, its option changed to No and a second rule added
        await listed.click();
        await expect(page).toHaveURL(/mageos_cca\/relation\/edit/);
        await expect(page.locator('#mageos-cca-relation-name')).toHaveValue('E2E listed relation');
        await page.locator('#mageos-cca-relation-name').fill(EDITED);
        await rows.first().locator('select').nth(1).selectOption({ label: 'No' });
        await page.locator('#mageos-cca-relation-add').click();
        await rows.nth(1).locator('select').nth(0).selectOption('e2e_a_sel');
        await rows.nth(1).locator('select').nth(1).selectOption({ label: 'North' });
        await rows.nth(1).locator('select').nth(2).selectOption('e2e_a_warn');
        await page.locator('#mageos-cca-relation-save').click();
        await expect(page.locator('.message-success')).toBeVisible();
        await expect(page.locator('tr.mageos-cca-relation-row', { hasText: 'E2E listed relation' })).toHaveCount(0);
        const edited = page.locator('tr.mageos-cca-relation-row', { hasText: EDITED });
        await expect(edited.locator('td').nth(2)).toHaveText('2');

        // opened again: the changed rules were stored
        await edited.click();
        await expect(rows).toHaveCount(2);
        await expect(rows.first().locator('select').nth(1).locator('option:checked')).toHaveText('No');
        await expect(rows.nth(1).locator('select').nth(0)).toHaveValue('e2e_a_sel');

        // the checkout follows the changed rules: e2e_a_warn shows for No (no longer for Yes) and for North
        await openCheckoutAsGuest(shop);
        const yesNo = (value: string) => shop.locator(`input[data-form="shipping"][name^="e2e_a_yn"][value="${value}"]`).first();
        const warn = field(shop, 'e2e_a_warn');
        await expect(warn).toBeHidden();
        await yesNo('1').check();
        await expect(warn, 'hidden for Yes after the edit').toBeHidden();
        await yesNo('0').check();
        await expect(warn, 'shown for No').toBeVisible();
        await yesNo('1').check();
        await expect(warn).toBeHidden();
        await shop.locator('label:has(input[data-form="shipping"][name^="e2e_a_sel"])').filter({ hasText: /^\s*North\s*$/ }).first().locator('input').check();
        await expect(warn, 'shown for North (second rule)').toBeVisible();
    });
});
