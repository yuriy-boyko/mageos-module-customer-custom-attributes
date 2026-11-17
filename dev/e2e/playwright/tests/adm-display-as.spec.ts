import { attributeTab, expect, openNewAttribute, test } from './helpers';

/**
 * RAD1: "Display As" in the attribute form. The block is only there for dropdown, multiple select and yes/no; a dropdown or yes/no can be
 * shown as radio buttons, a multiple select as checkboxes, and the other choice is not offered.
 */
const visibleOptions = async (page: import('@playwright/test').Page): Promise<string[]> =>
    page.locator('#display_as option').evaluateAll((nodes) => (nodes as HTMLOptionElement[]).filter((node) => !node.disabled && node.style.display !== 'none').map((node) => node.value));

test('RAD1: Display As is offered for dropdown, multiple select and yes/no only, with the matching choice', { tag: ['@RAD1'] }, async ({ adminPage: page }) => {
    await openNewAttribute(page, 'address');
    const type = page.locator('#frontend_input');
    const expected: Record<string, string[] | null> = {
        text: null,
        textarea: null,
        date: null,
        select: ['', 'radio'],
        multiselect: ['', 'checkbox'],
        boolean: ['', 'radio'],
    };
    for (const [input, options] of Object.entries(expected)) {
        await type.selectOption(input);
        const block = page.locator('#display_fieldset');
        if (options === null) {
            await expect(block, `${input}: no Display As`).toBeHidden();
        } else {
            await expect(block, `${input}: Display As shown`).toBeVisible();
            expect(await visibleOptions(page), `${input}: choices`).toEqual(options);
        }
    }
});
