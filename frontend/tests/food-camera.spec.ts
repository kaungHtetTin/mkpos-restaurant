import { test, expect, type Page } from '@playwright/test';

test.beforeEach(async ({ page }, info) => {
  if (info.project.name === 'phone') await page.setViewportSize({ width: 390, height: 844 });
});

async function openEditor(page: Page) {
  await page.goto('/');
  await page.getByLabel('Email', { exact: true }).fill('manager@littlelemon.local');
  await page.getByLabel('Password', { exact: true }).fill('Lemon2026!');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Your floor, at a glance.' })).toBeVisible();
  await page.goto('/menu');
  await page.getByRole('button', { name: 'Add menu item', exact: true }).click();
  await page.getByLabel('Food name', { exact: true }).fill('Camera draft');
}

test('camera captures the square frame and releases tracks on capture, cancel and close', async ({ page }) => {
  await page.addInitScript(() => {
    const tracks: MediaStreamTrack[] = [];
    Object.assign(window, { cameraTestTracks: tracks });
    Object.defineProperty(navigator.mediaDevices, 'getUserMedia', { value: async () => {
      const canvas = document.createElement('canvas'); canvas.width = 640; canvas.height = 360;
      const context = canvas.getContext('2d')!;
      context.fillStyle = '#f00'; context.fillRect(0, 0, 640, 360);
      context.fillStyle = '#0f0'; context.fillRect(140, 0, 360, 360);
      const stream = canvas.captureStream(10);
      const timer = setInterval(() => context.fillRect(140, 0, 360, 360), 100);
      tracks.push(...stream.getTracks());
      const stop = tracks.at(-1)!.stop.bind(tracks.at(-1)!);
      tracks.at(-1)!.stop = () => { clearInterval(timer); stop(); };
      return stream;
    } });
  });
  await openEditor(page);
  await page.getByRole('button', { name: 'Take photo', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Save changes', exact: true })).toBeDisabled();
  const frame = await page.locator('.camera-viewport').boundingBox();
  expect(frame).toBeTruthy(); expect(Math.abs(frame!.width - frame!.height)).toBeLessThan(1);
  await page.getByRole('button', { name: 'Capture photo', exact: true }).click();
  const photo = page.getByRole('img', { name: 'Food photo preview', exact: true });
  await expect(photo).toHaveJSProperty('naturalWidth', 360);
  await expect(photo).toHaveJSProperty('naturalHeight', 360);
  const pixel = await photo.evaluate((image: HTMLImageElement) => {
    const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
    const context = canvas.getContext('2d')!; context.drawImage(image, 0, 0, 1, 1);
    return [...context.getImageData(0, 0, 1, 1).data];
  });
  expect(pixel[1]).toBeGreaterThan(240); expect(pixel[0]).toBeLessThan(15);
  await expect.poll(() => page.evaluate(() => (window as unknown as { cameraTestTracks: MediaStreamTrack[] }).cameraTestTracks.every(track => track.readyState === 'ended'))).toBe(true);
  await expect(page.getByLabel('Food name', { exact: true })).toHaveValue('Camera draft');
  const capturedUrl = await photo.getAttribute('src');
  await page.getByRole('button', { name: 'Take photo', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Capture photo', exact: true })).toBeEnabled();
  await page.getByRole('button', { name: 'Cancel camera', exact: true }).click();
  await expect(photo).toHaveAttribute('src', capturedUrl!);
  await expect.poll(() => page.evaluate(() => (window as unknown as { cameraTestTracks: MediaStreamTrack[] }).cameraTestTracks.every(track => track.readyState === 'ended'))).toBe(true);
  await page.getByRole('button', { name: 'Take photo', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Capture photo', exact: true })).toBeEnabled();
  await page.getByRole('button', { name: 'Close dialog', exact: true }).click();
  await expect.poll(() => page.evaluate(() => (window as unknown as { cameraTestTracks: MediaStreamTrack[] }).cameraTestTracks.every(track => track.readyState === 'ended'))).toBe(true);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('denied camera permission lets the user cancel and keep the form draft', async ({ page }) => {
  await page.addInitScript(() => {
    Object.defineProperty(navigator.mediaDevices, 'getUserMedia', { value: async () => { throw new DOMException('Denied', 'NotAllowedError'); } });
  });
  await openEditor(page);
  await page.getByRole('button', { name: 'Take photo', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('Camera permission was denied');
  await expect(page.getByRole('button', { name: 'Capture photo', exact: true })).toBeDisabled();
  await page.getByRole('button', { name: 'Cancel camera', exact: true }).click();
  await expect(page.getByLabel('Food name', { exact: true })).toHaveValue('Camera draft');
  await expect(page.getByLabel('Choose food photo', { exact: true })).toBeEnabled();
});
