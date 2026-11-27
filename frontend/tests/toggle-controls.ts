import { expect, type Page } from '@playwright/test';

export async function setLanguage(page: Page, language: string, options: { force?: boolean } = {}) {
  const control = page.locator('button.language-control[role="switch"]');
  const checked = String(language === 'my');
  if (await control.getAttribute('aria-checked') !== checked) {
    if (options.force) {
      // A locale change from another tab must update an already-open editor too.
      await page.evaluate(value => {
        localStorage.setItem('restaurant-pos:business-locale', value);
        window.dispatchEvent(new StorageEvent('storage', { key:'restaurant-pos:business-locale', newValue:value }));
      }, language);
    } else await control.click();
  }
  await expect(control).toHaveAttribute('aria-checked', checked);
}

export async function setTheme(page: Page, theme: 'light' | 'dark') {
  const control = page.locator('button.appearance-control[role="switch"]');
  const checked = String(theme === 'dark');
  if (await control.getAttribute('aria-checked') !== checked) await control.click();
  await expect(control).toHaveAttribute('aria-checked', checked);
  await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
}
