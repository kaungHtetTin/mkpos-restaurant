import { test, expect } from '@playwright/test';

test('office remember option preserves failed input, submits once, and resets after sign out', async ({ page }) => {
  const settings = { name: 'Restaurant POS', timezone: 'Asia/Yangon', theme_color: '#c7a92e', support_email: '', support_phone: '', support_url: '', logo_url: null, version: 1 };
  const admin = { id: 991, name: 'Remember Test Admin', email: 'remember@example.test', office_role: 'super_admin', business_id: null, is_platform_admin: true, active: true, roles: [] };
  const attempts: Record<string, unknown>[] = [];
  await page.route('**/api/v1/**', async route => {
    const path = new URL(route.request().url()).pathname;
    let json: unknown = {};
    if (path === '/api/v1/platform/branding') json = { name: settings.name };
    if (path.includes('/realtime/')) json = { enabled: false };
    if (path === '/api/v1/office/session') json = { user: null, csrf: 'test-csrf', settings };
    if (path === '/api/v1/office/manage/settings') json = settings;
    if (path === '/api/v1/office/businesses') json = { businesses: { data: [], current_page: 1, last_page: 1, total: 0, per_page: 25, from: null, to: null }, summary: {}, plans: [] };
    if (path === '/api/v1/office/logout') json = { csrf: 'signed-out-csrf' };
    if (path === '/api/v1/office/login') {
      attempts.push(route.request().postDataJSON());
      await new Promise(resolve => setTimeout(resolve, 300));
      if (attempts.length === 1) {
        await route.fulfill({ status: 422, json: { message: 'The office account or password is incorrect.' } });
        return;
      }
      json = { user: admin, csrf: 'signed-in-csrf', settings };
    }
    await route.fulfill({ json });
  });
  await page.goto('/office');
  const remember = page.getByRole('checkbox', { name: 'Remember me for 30 days' });
  await expect(remember).not.toBeChecked();
  await page.getByLabel('Email', { exact: true }).fill(admin.email);
  await page.getByLabel('Password', { exact: true }).fill('TestPassword123!');
  await remember.focus();
  await page.keyboard.press('Space');
  await expect(remember).toBeChecked();
  await page.getByRole('button', { name: 'Sign in to office' }).click();
  await expect(page.getByRole('button', { name: 'Signing in...' })).toBeDisabled();
  await expect(page.getByRole('alert')).toContainText('incorrect');
  await expect(remember).toBeChecked();
  await expect(page.getByLabel('Email', { exact: true })).toHaveValue(admin.email);
  await expect(page.getByLabel('Password', { exact: true })).toHaveValue('TestPassword123!');
  expect(attempts).toHaveLength(1);
  expect(attempts[0].remember).toBe(true);
  await remember.uncheck();
  await page.getByLabel('Password', { exact: true }).press('Enter');
  await expect(page.getByRole('button', { name: 'Sign out', exact: true })).toBeVisible();
  expect(attempts).toHaveLength(2);
  expect(attempts[1].remember).toBe(false);
  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await expect(remember).not.toBeChecked();
  await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');
});
