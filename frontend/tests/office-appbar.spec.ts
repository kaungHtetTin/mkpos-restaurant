import { test, expect } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

test.beforeAll(() => {
  const backend = path.resolve('..', 'backend');
  const result = spawnSync('php', ['artisan', 'platform:admin', '--no-ansi'], {
    cwd: backend, input: 'Office App Bar QA\nappbar@example.test\nAppBarPassword123!\n', encoding: 'utf8',
    env: { ...process.env, DB_DATABASE: process.env.E2E_DATABASE || path.join(backend, 'database', 'office-appbar-test.sqlite') },
  });
  if (result.status !== 0 && !result.stdout.includes('already been taken')) throw new Error(result.stdout + result.stderr);
});

test.beforeEach(async ({ page }) => {
  await page.goto('/office');
  await page.getByLabel('Email', { exact: true }).fill('appbar@example.test');
  await page.getByLabel('Password', { exact: true }).fill('AppBarPassword123!');
  await page.getByRole('button', { name: 'Sign in to office', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Businesses', exact: true })).toBeVisible();
});

test('all office pages expose page actions in the app bar', async ({ page }, info) => {
  test.setTimeout(120000);
  await page.emulateMedia({reducedMotion:'reduce'});
  const bar = page.getByRole('banner', { name: 'Office app bar' });
  const pages: [string, string[], string?][] = [
    ['Businesses', ['Add business'], 'Add business'],
    ['Overview', []],
    ['Expiry calendar', []],
    ['Business locations', []],
    ['Payment approvals', []],
    ['Payments', ['Export this page']],
    ['Plans', ['Create plan'], 'Create plan'],
    ['Payment methods', ['Add payment method'], 'Add payment method'],
    ['Financial book', ['Record financial entry', 'Allocate founder profit', 'Export this page']],
    ['Bank books', ['Add bank book'], 'Add bank book'],
    ['Entry categories', ['Add category'], 'Add category'],
    ['Financial report', ['Export this page']],
    ['Founders', ['Allocate founder profit', 'Edit founder shares'], 'Edit founder shares'],
    ['Support reports', []],
    ['Tutorials & notices', ['Add content'], 'Add content'],
    ['Administrators', ['Add administrator'], 'Add administrator'],
    ['Branding & contacts', ['Edit platform settings'], 'Edit platform settings'],
    ['System health', []],
    ['Activity log', []],
    ['My account', ['Edit my account'], 'Edit my account'],
  ];
  for (const [label, actions, editor] of pages) {
    const section = page.getByLabel('Office section', { exact: true });
    if (await section.isVisible()) await section.selectOption({ label });
    else await page.getByRole('navigation', { name: 'Office navigation' }).getByRole('link', { name: label, exact: true }).click();
    await expect(page.locator('main h1')).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: 'Loading records' })).toHaveCount(0);
    for (const action of actions) await expect(bar.getByRole('button', { name: action, exact: true })).toBeVisible();
    await expect(page.locator('main > .office-title button')).toHaveCount(0);
    const hasFilters=!['Plans','Branding & contacts','System health','My account'].includes(label);
    if(hasFilters){
      await bar.getByRole('button',{name:'Filters',exact:true}).click();
      const drawer=page.getByRole('dialog',{name:'Page filters',exact:true});await expect(drawer).toBeVisible();
      const bounds=await drawer.boundingBox();expect(bounds!.x+bounds!.width).toBeLessThanOrEqual(page.viewportSize()!.width+1);
      if(label==='Financial book')await page.screenshot({path:`test-results/${info.project.name}-office-filter-drawer.png`,animations:'disabled'});
      await drawer.press('Escape');await expect(drawer).toHaveCount(0);await expect(bar.getByRole('button',{name:'Filters',exact:true})).toBeFocused();
    }else await expect(bar.getByRole('button',{name:'Filters',exact:true})).toHaveCount(0);
    await expect(page.locator('main .office-filters,main .office-tools-filters,main .office-finance-category-filter')).toHaveCount(0);

    await expect(page.getByRole('alert')).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    if (editor) {
      await bar.getByRole('button', { name: editor, exact: true }).click();
      await expect(page.getByRole('dialog')).toBeVisible();
      await page.getByRole('dialog').press('Escape');
      await expect(page.getByRole('dialog')).toHaveCount(0);
    }
    await expect(page.getByRole('button', { name: /^(Refresh|Run health checks)/ })).toHaveCount(0);
    if (actions.includes('Export this page')) {
      const download = page.waitForEvent('download');
      await bar.getByRole('button', { name: 'Export this page', exact: true }).click();
      expect((await download).suggestedFilename()).toMatch(/\.csv$/);
    }
  }
  await page.goto('/office/plans');
  await expect(bar.getByRole('button', { name: 'Create plan' })).toBeVisible();
  await page.screenshot({ path: `test-results/${info.project.name}-office-appbar.png`, fullPage: true });
});

test('business app-bar forms submit and Back retains list state', async ({ page }, info) => {
  const bar = page.getByRole('banner', { name: 'Office app bar' });
  await bar.getByRole('button',{name:'Filters',exact:true}).click();
  await page.getByRole('textbox', { name: 'Search businesses', exact: true }).fill('Little Lemon');
  await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
  await page.getByLabel('Rows per page', { exact: true }).selectOption('10');
  await bar.getByRole('button',{name:'Filters',exact:true}).click();
  await page.getByRole('textbox', { name: 'Search businesses', exact: true }).fill('Unsubmitted draft');
  await page.getByRole('button',{name:'Close filters',exact:true}).click();
  const source = page.url();
  const manage = page.getByRole('link', { name: 'Manage', exact: true });
  await expect(manage).toHaveCount(1);
  await manage.click();
  await expect(bar.getByRole('button', { name: 'Save account', exact: true })).toBeVisible();
  await expect(bar.getByRole('button', { name: 'Update subscription', exact: true })).toBeVisible();
  await page.getByLabel('Contact', { exact: true }).fill(`App bar ${info.project.name}`);
  await bar.getByRole('button', { name: 'Save account', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Business account updated.');
  await page.getByRole('combobox', { name: 'Action', exact: true }).selectOption('extend');
  await page.getByLabel('Reason', { exact: true }).fill('Isolated app bar submit verification');
  await bar.getByRole('button', { name: 'Update subscription', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Subscription updated.');
  const initialRequests: string[] = [];
  page.on('request', request => { if (request.url().includes('/api/v1/office/businesses?')) initialRequests.push(request.url()); });
  await bar.getByRole('link', { name: 'Back to businesses', exact: true }).click();
  await expect(page).toHaveURL(source);
  await bar.getByRole('button',{name:'Filters',exact:true}).click();
  await expect(page.getByRole('textbox', { name: 'Search businesses', exact: true })).toHaveValue('Unsubmitted draft');
  await page.getByRole('button',{name:'Close filters',exact:true}).click();
  await expect(page.getByLabel('Rows per page', { exact: true })).toHaveValue('10');
  expect(initialRequests).toHaveLength(0);
  await expect(bar.getByRole('button', { name: 'Save account', exact: true })).toHaveCount(0);
});

test('filter drawer keeps unapplied drafts, traps focus and resets criteria',async({page},info)=>{
  await page.locator('.office-table tbody tr').first().waitFor();
  const trigger=page.getByRole('button',{name:'Filters',exact:true}),drawer=page.getByRole('dialog',{name:'Page filters',exact:true}),source=page.url();
  let reads=0;page.on('request',request=>{if(request.url().includes('/api/v1/office/businesses?'))reads++;});
  await trigger.click();await expect(drawer.getByLabel('Search businesses',{exact:true})).toBeFocused();
  await drawer.getByLabel('Search businesses',{exact:true}).fill('Little Lemon');
  await drawer.getByRole('button',{name:'Close filters',exact:true}).click();await expect(drawer).toHaveCount(0);await expect(trigger).toBeFocused();await expect(page).toHaveURL(source);expect(reads).toBe(0);
  await trigger.click();await expect(drawer.getByLabel('Search businesses',{exact:true})).toHaveValue('Little Lemon');
  if(info.project.name==='desktop'){
    await page.locator('.office-filter-overlay').click({position:{x:5,y:5}});await expect(drawer).toHaveCount(0);await expect(trigger).toBeFocused();await trigger.click();
  }
  await drawer.getByRole('button',{name:'Apply filters',exact:true}).press('Tab');await expect(drawer.getByRole('button',{name:'Close filters',exact:true})).toBeFocused();
  await drawer.getByRole('button',{name:'Close filters',exact:true}).press('Shift+Tab');await expect(drawer.getByRole('button',{name:'Apply filters',exact:true})).toBeFocused();
  await drawer.press('Escape');await expect(drawer).toHaveCount(0);await expect(trigger).toBeFocused();expect(reads).toBe(0);
  await trigger.click();await drawer.getByLabel('Search businesses',{exact:true}).press('Enter');await expect(drawer).toHaveCount(0);
  await expect.poll(()=>new URL(page.url()).searchParams.get('q')).toBe('Little Lemon');await expect(page.locator('.office-table tbody tr')).toHaveCount(1);await expect(trigger.locator('.office-filter-count')).toHaveText('1');
  await trigger.click();await drawer.getByLabel('Search businesses',{exact:true}).fill('Unapplied second draft');
  const beforeHistory=reads;await page.goBack();await expect(drawer).toHaveCount(0);await expect(page).toHaveURL(source);
  await page.goForward();await expect.poll(()=>new URL(page.url()).searchParams.get('q')).toBe('Little Lemon');await trigger.click();await expect(drawer.getByLabel('Search businesses',{exact:true})).toHaveValue('Unapplied second draft');expect(reads).toBe(beforeHistory);
  await drawer.getByRole('button',{name:'Reset filters',exact:true}).click();await expect(drawer).toHaveCount(0);
  await expect.poll(()=>new URL(page.url()).searchParams.has('q')).toBe(false);await expect(trigger.locator('.office-filter-count')).toHaveCount(0);
  await trigger.click();await expect(drawer.getByLabel('Search businesses',{exact:true})).toHaveValue('');await drawer.press('Escape');
});

test('report, chart, calendar and administrator filters share the right drawer',async({page})=>{
  test.setTimeout(90000);
  const drawer=page.getByRole('dialog',{name:'Page filters',exact:true});
  const open=async()=>{await page.getByRole('button',{name:'Filters',exact:true}).click();await expect(drawer).toBeVisible();};
  await page.goto('/office/report');await open();
  await expect(drawer.getByLabel('Financial entry category filter',{exact:true})).toBeVisible();
  await drawer.getByLabel('Report year',{exact:true}).fill('2025');await drawer.getByLabel('Currency',{exact:true}).fill('USD');await drawer.getByLabel('From',{exact:true}).fill('2025-01-01');await drawer.getByLabel('To',{exact:true}).fill('2025-12-31');
  await drawer.getByRole('button',{name:'Apply filters',exact:true}).click();await expect(drawer).toHaveCount(0);await expect.poll(()=>new URL(page.url()).searchParams.get('year')).toBe('2025');
  expect(new URL(page.url()).searchParams.get('currency')).toBe('USD');await expect(page.locator('main input[aria-label="Report year"],main select[aria-label="Financial entry category filter"]')).toHaveCount(0);
  await open();await expect(drawer.getByLabel('From',{exact:true})).toHaveValue('2025-01-01');await drawer.getByRole('button',{name:'Reset filters',exact:true}).click();expect(new URL(page.url()).searchParams.has('year')).toBe(false);
  await page.goto('/office/overview');await open();await drawer.getByLabel('Income month',{exact:true}).fill('2025-02');await drawer.getByLabel('Income currency',{exact:true}).selectOption('MMK');await drawer.getByRole('button',{name:'Apply filters',exact:true}).click();
  await expect.poll(()=>new URL(page.url()).searchParams.get('month')).toBe('2025-02');await expect(page.locator('.office-chart>div')).toHaveCount(28);
  await page.goto('/office/calendar');await open();await drawer.getByLabel('Expiry month',{exact:true}).fill('2025-03');await drawer.getByLabel('Expiry day (optional)',{exact:true}).fill('2025-03-10');await drawer.getByRole('button',{name:'Apply filters',exact:true}).click();
  await expect.poll(()=>new URL(page.url()).searchParams.get('day')).toBe('2025-03-10');await page.getByRole('button',{name:'Expiries on 2025-03-11',exact:true}).click();
  await open();await expect(drawer.getByLabel('Expiry day (optional)',{exact:true})).toHaveValue('2025-03-11');await drawer.getByRole('button',{name:'Reset filters',exact:true}).click();expect(new URL(page.url()).searchParams.has('day')).toBe(false);
  await page.goto('/office/activity');await page.locator('.office-table').waitFor();const session=await(await page.request.get('/api/v1/office/session')).json();
  await open();await drawer.getByLabel('Activity administrator',{exact:true}).selectOption(String(session.user.id));await drawer.getByRole('button',{name:'Apply filters',exact:true}).click();
  await expect.poll(()=>new URL(page.url()).searchParams.get('actor_id')).toBe(String(session.user.id));await expect(page.locator('main select[aria-label="Activity administrator"]')).toHaveCount(0);
  const finance=await(await page.request.get('/api/v1/office/finance')).json(),book=finance.books[0];
  await page.goto('/office/books');await open();await expect(drawer.getByLabel('From',{exact:true})).toHaveCount(0);
  await drawer.getByLabel('Search records',{exact:true}).fill(book.name);await drawer.getByLabel('Currency',{exact:true}).fill(book.currency);await drawer.getByRole('button',{name:'Apply filters',exact:true}).click();
  const matching=finance.books.filter((candidate:any)=>candidate.currency===book.currency&&candidate.name.toLowerCase().includes(book.name.toLowerCase()));
  await expect(page.locator('.office-table tbody tr')).toHaveCount(matching.length);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();
});
