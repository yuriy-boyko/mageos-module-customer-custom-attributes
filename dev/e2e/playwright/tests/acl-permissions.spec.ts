import { ACL, aclMenu, attributeRow, benchCommand, deleteAclAdmin, expect, expectForbidden, setAclAdmin, signInAclAdmin, test } from './helpers';
import type { Page } from '@playwright/test';

/**
 * ACL1-ACL5: roles with only some of the module's resources. A test admin (e2e_acl_user) with its own role is created for each test and
 * deleted afterwards. Admin links carry a secret key that belongs to the session, so a forbidden address is built from a link the admin
 * had a right to: the same controller and action with another `entity`. For a screen the role never had, the key is computed the way
 * Magento does: a hash of route, controller, action and the session's form key, asked from the bench (checked against a real link in ACL5).
 */
const CODE = 'e2e_acl_x';

test.beforeEach(() => deleteAclAdmin());
test.afterEach(() => deleteAclAdmin());

type Links = { index: string; add: string; save: string };

/** The index link of an entity from the menu, the "Add New" link of its grid and the save address of its form. */
async function harvest(page: Page, entity: 'customer' | 'address'): Promise<Links> {
    await page.goto('/backend/');
    const index = (await page.locator(`a[href*="mageos_cca/attribute/index/entity/${entity}"]`).first().getAttribute('href')) as string;
    await page.goto(index);
    const add = (await page.locator('.page-actions a, .page-actions button').filter({ hasText: /Add New/i }).first().evaluate((element) => (element as HTMLAnchorElement).getAttribute('href') || '')) || '';
    let addUrl = add;
    if (!addUrl) {
        await page.locator('.page-actions button').filter({ hasText: /Add New/i }).first().click();
        addUrl = page.url();
    } else {
        await page.goto(addUrl);
    }
    const save = (await page.locator('#edit_form').getAttribute('action')) as string;
    return { index, add: addUrl, save };
}

const swap = (url: string, from: string, to: string): string => url.replace(`/entity/${from}/`, `/entity/${to}/`);

async function postSave(page: Page, saveUrl: string, entity: string): Promise<number> {
    const formKey = await page.locator('input[name="form_key"]').first().inputValue();
    const response = await page.request.post(saveUrl, {
        form: { form_key: formKey, entity, attribute_code: CODE, 'frontend_label[0]': 'ACL test', frontend_input: 'text', is_required: '0', sort_order: '1' },
        maxRedirects: 0,
    });
    return response.status();
}

async function denyBothWays(page: Page, own: 'customer' | 'address', other: 'customer' | 'address'): Promise<void> {
    const links = await harvest(page, own);
    expect(links.add).toContain('/new/');
    // own area works, the other one is refused for index, new and save
    await expectForbidden(page, swap(links.index, own, other));
    await expectForbidden(page, swap(links.add, own, other));
    await page.goto(links.add); // a page of the own area, for the form key
    expect(await postSave(page, swap(links.save, own, other), other)).toBe(403);
    expect(attributeRow(other, CODE).exists).toBe(false);
}

test('a role with only Customer Address Attributes sees only that menu entry', { tag: ['@ACL1'] }, async ({ browser, baseURL }) => {
    setAclAdmin([ACL.address]);
    const { page, context } = await signInAclAdmin(browser, baseURL as string);
    expect(await aclMenu(page)).toEqual({ customer: 0, address: 1, relations: 0 });
    const links = await harvest(page, 'address');
    await page.goto(links.index);
    await expect(page.locator('#mageosCcaAttributeGrid')).toBeVisible();
    await context.close();
});

test('the same role is refused the customer area: index, new and save', { tag: ['@ACL2'] }, async ({ browser, baseURL }) => {
    setAclAdmin([ACL.address]);
    const { page, context } = await signInAclAdmin(browser, baseURL as string);
    await denyBothWays(page, 'address', 'customer');
    await context.close();
});

test('a role with only Customer Attributes is refused the address area', { tag: ['@ACL3'] }, async ({ browser, baseURL }) => {
    setAclAdmin([ACL.customer]);
    const { page, context } = await signInAclAdmin(browser, baseURL as string);
    expect(await aclMenu(page)).toEqual({ customer: 1, address: 0, relations: 0 });
    await denyBothWays(page, 'customer', 'address');
    await context.close();
});

/** The admin address of an action with its secret key, computed like Magento's backend URL model. `path` is e.g. `mageos_cca/relation/index`. */
async function keyedUrl(page: Page, path: string, params = ''): Promise<string> {
    await page.goto('/backend/');
    const formKey = await page.evaluate(() => (window as unknown as { FORM_KEY: string }).FORM_KEY);
    const key = benchCommand('E2E_FIXTURE_CMD', ['secret-key', path, formKey]).trim();
    return `/backend/${path}/${params ? params.replace(/^\/|\/$/g, '') + '/' : ''}key/${key}/`;
}

test('a role with neither attribute resource has no menu entries and is refused everywhere', { tag: ['@ACL4'] }, async ({ browser, baseURL }) => {
    setAclAdmin([]);
    const { page, context } = await signInAclAdmin(browser, baseURL as string);
    expect(await aclMenu(page)).toEqual({ customer: 0, address: 0, relations: 0 });
    for (const entity of ['customer', 'address']) {
        await expectForbidden(page, await keyedUrl(page, 'mageos_cca/attribute/index', `entity/${entity}`));
        await expectForbidden(page, await keyedUrl(page, 'mageos_cca/attribute/new', `entity/${entity}`));
        const save = await keyedUrl(page, 'mageos_cca/attribute/save', `entity/${entity}`);
        const formKey = await page.evaluate(() => (window as unknown as { FORM_KEY: string }).FORM_KEY);
        const response = await page.request.post(save, {
            form: { form_key: formKey, entity, attribute_code: CODE, 'frontend_label[0]': 'ACL test', frontend_input: 'text', is_required: '0', sort_order: '1' },
            maxRedirects: 0,
        });
        expect(response.status()).toBe(403);
        expect(attributeRow(entity as 'customer' | 'address', CODE).exists).toBe(false);
    }
    await context.close();
});

test('the Attribute Relations screen needs its own resource', { tag: ['@ACL5'] }, async ({ browser, baseURL }) => {
    // with the resource: the menu entry and the screen; the computed key is the one in the menu link
    setAclAdmin([ACL.relations]);
    let session = await signInAclAdmin(browser, baseURL as string);
    expect((await aclMenu(session.page)).relations).toBeGreaterThan(0);
    const menuHref = (await session.page.locator('a[href*="mageos_cca/relation"]').first().getAttribute('href')) as string;
    const computed = await keyedUrl(session.page, 'mageos_cca/relation/index');
    expect(menuHref).toContain(computed.replace(/^\/backend/, ''));
    const response = await session.page.goto(menuHref);
    expect(response?.status()).toBe(200);
    await expect(session.page.locator('.page-title')).toContainText(/Relations/i);
    await session.context.close();

    // without it (the role keeps an attribute grid): no menu entry, 403 on the screen
    setAclAdmin([ACL.address]);
    session = await signInAclAdmin(browser, baseURL as string);
    expect((await aclMenu(session.page)).relations).toBe(0);
    await expectForbidden(session.page, await keyedUrl(session.page, 'mageos_cca/relation/index'));
    await expectForbidden(session.page, await keyedUrl(session.page, 'mageos_cca/relation/new'));
    await session.context.close();
});
