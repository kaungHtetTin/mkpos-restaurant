import { test, expect } from '@playwright/test';

for (const role of ['cashier', 'manager']) {
  test(`${role} sees the permitted sales periods`, async ({ page }, info) => {
    if (info.project.name === 'phone') await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    await page.getByLabel('Email', { exact: true }).fill(`${role}@littlelemon.local`);
    await page.getByLabel('Password', { exact: true }).fill('Lemon2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page.getByRole('banner')).toBeVisible();
    const response = page.waitForResponse(response => response.url().includes('/api/v1/sales?') && response.ok());
    await page.goto('/sales?date=2026-11-10&period=month');
    const report = await (await response).json();

    if (role === 'cashier') {
      expect(report.period).toBe('day');
      expect(Object.keys(report.summary)).toEqual(['day']);
      await expect(page.getByRole('button', { name: 'Show daily sales', exact: true })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Show monthly sales', exact: true })).toHaveCount(0);
      await expect(page.getByRole('button', { name: 'Show yearly sales', exact: true })).toHaveCount(0);
      await expect(page.getByLabel(/^Period/)).toHaveCount(0);
      await expect(page.getByLabel('Chart period', { exact: true })).toHaveCount(0);
      await expect(page.getByRole('heading', { name: 'Daily sales chart', exact: true })).toBeVisible();
      await page.getByLabel('Reference date', { exact: true }).fill('2026-11-09');
      await page.getByRole('button', { name: 'Apply', exact: true }).click();
      await expect(page).toHaveURL(/date=2026-11-09&period=day/);
      await page.reload();
      await expect(page.getByLabel('Reference date', { exact: true })).toHaveValue('2026-11-09');
      await expect(page.getByRole('heading', { name: 'Daily sales chart', exact: true })).toBeVisible();
    } else {
      expect(report.period).toBe('month');
      await expect(page.getByRole('button', { name: 'Show monthly sales', exact: true })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Show yearly sales', exact: true })).toBeVisible();
      await expect(page.getByLabel(/^Period/)).toHaveValue('month');
      await expect(page.getByRole('heading', { name: 'Monthly sales chart', exact: true })).toBeVisible();
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  });
}
