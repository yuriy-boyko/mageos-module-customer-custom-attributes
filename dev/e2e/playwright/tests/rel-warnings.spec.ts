import type { Page } from '@playwright/test';
import { adminOpen, attributeTab, benchCommand, expect, openAttribute, saveAttribute, test } from './helpers';

/**
 * REL3 / REL4: the relation edit page warns about a rule whose dependent attribute is shown in a storefront form where its parent is not.
 * Relation "E2E address nested relation" (fixtures): rule 1 e2e_a_nroot (Root) -> e2e_a_nsel (Middle), rule 2 e2e_a_nsel -> e2e_a_ndep (Leaf).
 * The attributes sit in all address forms; the tests change that and put it back.
 */
const ALL = 'customer_address_edit,customer_register_address,mageos_hyva_checkout,mageos_address_text';
const RELATION = 'E2E address nested relation';

const forms = (code: string, list: string) => benchCommand('E2E_FIXTURE_CMD', ['set-attribute', code, 'required=0', `forms=${list}`]);

test.afterEach(() => {
    for (const code of ['e2e_a_nroot', 'e2e_a_nsel', 'e2e_a_ndep']) forms(code, ALL);
});

async function openRelation(page: Page): Promise<void> {
    await adminOpen(page, 'mageos_cca/relation');
    await page.locator('tr.mageos-cca-relation-row', { hasText: RELATION }).first().click();
    await expect(page).toHaveURL(/mageos_cca\/relation\/edit/);
}

const warnings = (page: Page) => page.locator('.message-warning').allInnerTexts();

test('REL3: several rules and forms give one warning per rule and form', { tag: ['@REL3'] }, async ({ adminPage: page }) => {
    // control: all attributes in the same forms, no warning
    await openRelation(page);
    expect(await warnings(page)).toEqual([]);

    // Root only in the address book, Middle in the address book and registration, Leaf in all:
    // rule 1 warns for registration (Middle is shown there, Root is not), rule 2 for the checkout (Leaf is shown there, Middle is not)
    forms('e2e_a_nroot', 'customer_address_edit');
    forms('e2e_a_nsel', 'customer_address_edit,customer_register_address');
    await openRelation(page);
    const shown = await warnings(page);
    expect(shown).toHaveLength(2);
    expect(shown[0]).toMatch(/Rule 1: "E2E Address Nested Middle" is shown in "[^"]*[Rr]egist[^"]*", but its parent "E2E Address Nested Root" is not/);
    expect(shown[1]).toMatch(/Rule 2: "E2E Address Nested Leaf" is shown in "[^"]*Checkout[^"]*", but its parent "E2E Address Nested Middle" is not/);

    // the same warnings after saving the relation
    await page.locator('#mageos-cca-relation-save-continue').click();
    await expect(page.locator('.message-success')).toBeVisible();
    // (the page lists each warning twice: once from the save, once from opening the edit page again)
    expect([...new Set(await warnings(page))]).toEqual(shown);
});

test('REL4: a form assignment changed later makes the warning appear when the relation is opened again', { tag: ['@REL4'] }, async ({ adminPage: page }) => {
    await openRelation(page);
    expect(await warnings(page)).toEqual([]);

    // the shop owner takes the parent Root out of the checkout and registration forms on its own edit page
    await openAttribute(page, 'address', 'e2e_a_nroot');
    await attributeTab(page, 'Storefront Properties');
    await page.locator('#used_in_forms').selectOption(['customer_address_edit', 'mageos_address_text']);
    await saveAttribute(page);
    await expect(page.locator('.message-success')).toBeVisible();

    await openRelation(page);
    const shown = await warnings(page);
    expect(shown).toHaveLength(2); // rule 1 for the registration and the checkout form
    for (const text of shown) {
        expect(text).toMatch(/Rule 1: "E2E Address Nested Middle" is shown in ".+", but its parent "E2E Address Nested Root" is not/);
    }
    expect(new Set(shown).size, 'one warning per form').toBe(2);
});
