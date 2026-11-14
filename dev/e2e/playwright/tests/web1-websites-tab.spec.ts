import { attributeRow, attributeTab, createAttributes, deleteCreated, expect, openAttribute, saveAttribute, test } from './helpers';

/**
 * WEB1: the Websites tab of an attribute: Enabled, Values Required and Default Value per website are kept when the attribute is saved and
 * reopened, and "Use Default" names the value of the Properties tab. The attribute is created by the test (e2e_web1, optional text,
 * websites: main = 1, Italy = 2).
 */
const CODE = 'e2e_web1';

test.beforeEach(() => createAttributes('address', [CODE]));
test.afterEach(() => deleteCreated(CODE));

const select = (page: import('@playwright/test').Page, kind: 'visible' | 'required' | 'default', website: number) => page.locator(`#scope_${kind}_${website}`);

test('WEB1: the website overrides are kept, "Use Default" shows the global value', { tag: ['@WEB1'] }, async ({ adminPage: page }) => {
    await openAttribute(page, 'address', CODE);
    await attributeTab(page, 'Websites');

    // nothing overridden: "Use Default" names the global value (enabled, not required)
    for (const website of [1, 2]) {
        expect(await select(page, 'visible', website).locator('option').first().innerText()).toBe('Use Default (Yes)');
        expect(await select(page, 'required', website).locator('option').first().innerText()).toBe('Use Default (No)');
        await expect(select(page, 'visible', website)).toHaveValue('');
        await expect(select(page, 'default', website)).toHaveValue('');
    }

    // override: Italy disabled and required with its own default; the main website only gets a default
    await select(page, 'visible', 2).selectOption('0');
    await select(page, 'required', 2).selectOption('1');
    await select(page, 'default', 2).fill('ita default');
    await select(page, 'default', 1).fill('main default');
    await saveAttribute(page, true);
    await expect(page.locator('.message-success')).toBeVisible();

    // stored, and shown again after reopening
    const stored = attributeRow('address', CODE).websites;
    expect(stored['2']).toMatchObject({ is_visible: '0', is_required: '1', default_value: 'ita default' });
    expect(stored['1']).toMatchObject({ is_visible: null, is_required: null, default_value: 'main default' });
    await openAttribute(page, 'address', CODE);
    await attributeTab(page, 'Websites');
    await expect(select(page, 'visible', 2)).toHaveValue('0');
    await expect(select(page, 'required', 2)).toHaveValue('1');
    await expect(select(page, 'default', 2)).toHaveValue('ita default');
    await expect(select(page, 'visible', 1)).toHaveValue('');
    await expect(select(page, 'default', 1)).toHaveValue('main default');

    // the Properties tab changes the global value: "Use Default" follows it
    await attributeTab(page, 'Properties');
    await page.locator('#is_required').selectOption('1');
    await saveAttribute(page, true);
    await expect(page.locator('.message-success')).toBeVisible();
    await attributeTab(page, 'Websites');
    expect(await select(page, 'required', 1).locator('option').first().innerText()).toBe('Use Default (Yes)');
    await expect(select(page, 'required', 1)).toHaveValue('');
    await expect(select(page, 'required', 2)).toHaveValue('1'); // the override stays

    // back to "Use Default": the overrides disappear
    await select(page, 'visible', 2).selectOption('');
    await select(page, 'required', 2).selectOption('');
    await select(page, 'default', 2).fill('');
    await select(page, 'default', 1).fill('');
    await saveAttribute(page, true);
    const cleared = attributeRow('address', CODE).websites;
    for (const website of ['1', '2']) {
        expect(cleared[website] ?? { is_visible: null, is_required: null, default_value: null }).toMatchObject({ is_visible: null, is_required: null });
        expect(cleared[website]?.default_value ?? null).toBeFalsy();
    }
});
