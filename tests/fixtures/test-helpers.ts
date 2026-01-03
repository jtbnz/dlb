import { Page, expect } from '@playwright/test';
import { demoBrigade, superAdmin } from './test-data';

/**
 * Test Helper Utilities for DLB Testing Suite
 */

/**
 * Check if running in production mode
 */
export function isProduction(): boolean {
  return process.env.BASE_URL?.includes('kiaora.tech') || false;
}

/**
 * Get the brigade slug for testing
 */
export function getBrigadeSlug(): string {
  return process.env.BRIGADE_SLUG || demoBrigade.slug;
}

/**
 * Get base URL with optional path
 */
export function getUrl(path: string = ''): string {
  const baseUrl = process.env.BASE_URL || 'http://localhost:8080/dlb';
  return `${baseUrl}${path}`;
}

/**
 * Authenticate with brigade PIN
 */
export async function authenticateWithPin(
  page: Page,
  slug: string = demoBrigade.slug,
  pin: string = demoBrigade.pin
): Promise<void> {
  await page.goto(slug);

  // Wait for PIN form
  await page.waitForSelector('input[name="pin"], input[type="password"]');

  // Enter PIN
  const pinInput = page.locator('input[name="pin"], input[type="password"]').first();
  await pinInput.fill(pin);

  // Submit
  const submitButton = page.locator('button[type="submit"], input[type="submit"]');
  await submitButton.click();

  // Wait for navigation to attendance page
  await page.waitForURL(`**/${slug}/attendance**`, { timeout: 10000 });
}

/**
 * Authenticate as brigade admin
 */
export async function authenticateAsAdmin(
  page: Page,
  slug: string = demoBrigade.slug,
  username: string = demoBrigade.adminUsername,
  password: string = demoBrigade.adminPassword
): Promise<void> {
  await page.goto(`${slug}/admin`);

  // Wait for login form
  await page.waitForSelector('input[name="username"]');

  // Fill credentials
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);

  // Submit
  await page.locator('button[type="submit"], input[type="submit"]').click();

  // Wait for dashboard
  await page.waitForURL(`**/${slug}/admin/dashboard**`, { timeout: 10000 });
}

/**
 * Authenticate as super admin
 */
export async function authenticateAsSuperAdmin(
  page: Page,
  username: string = superAdmin.username,
  password: string = superAdmin.password
): Promise<void> {
  await page.goto('admin');

  // Wait for login form
  await page.waitForSelector('input[name="username"]');

  // Fill credentials
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);

  // Submit
  await page.locator('button[type="submit"], input[type="submit"]').click();

  // Wait for dashboard
  await page.waitForURL('**/admin/dashboard**', { timeout: 10000 });
}

/**
 * Make API request with admin session
 */
export async function adminApiRequest(
  page: Page,
  method: string,
  endpoint: string,
  data?: any
): Promise<any> {
  const response = await page.request[method.toLowerCase()](endpoint, {
    data: data ? JSON.stringify(data) : undefined,
    headers: {
      'Content-Type': 'application/json',
    },
  });
  return response.json();
}

/**
 * Make API v1 request with bearer token
 */
export async function apiV1Request(
  page: Page,
  method: string,
  endpoint: string,
  token: string,
  data?: any
): Promise<any> {
  const response = await page.request[method.toLowerCase()](endpoint, {
    data: data ? JSON.stringify(data) : undefined,
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`,
    },
  });
  return response.json();
}

/**
 * Wait for API response with specific status
 */
export async function waitForApiResponse(
  page: Page,
  urlPattern: string | RegExp,
  status: number = 200
): Promise<any> {
  const response = await page.waitForResponse(
    (resp) => resp.url().match(urlPattern) !== null && resp.status() === status
  );
  return response.json();
}

/**
 * Create a test member via API
 */
export async function createTestMember(
  page: Page,
  slug: string,
  member: { name: string; rank: string }
): Promise<any> {
  const response = await page.request.post(`/${slug}/admin/api/members`, {
    data: JSON.stringify(member),
    headers: { 'Content-Type': 'application/json' },
  });
  return response.json();
}

/**
 * Create a test truck via API
 */
export async function createTestTruck(
  page: Page,
  slug: string,
  truck: { name: string; is_station: boolean }
): Promise<any> {
  const response = await page.request.post(`/${slug}/admin/api/trucks`, {
    data: JSON.stringify(truck),
    headers: { 'Content-Type': 'application/json' },
  });
  return response.json();
}

/**
 * Create a test callout via API
 */
export async function createTestCallout(
  page: Page,
  slug: string,
  callout: any
): Promise<any> {
  const response = await page.request.post(`/${slug}/api/callout`, {
    data: JSON.stringify(callout),
    headers: { 'Content-Type': 'application/json' },
  });
  return response.json();
}

/**
 * Clean up test data (for local testing only)
 */
export async function cleanupTestData(page: Page, slug: string): Promise<void> {
  if (isProduction()) {
    console.log('Skipping cleanup in production mode');
    return;
  }

  // Get all test members and delete them
  const membersResponse = await page.request.get(`/${slug}/admin/api/members`);
  const members = await membersResponse.json();

  for (const member of members?.members || []) {
    if (member.name.startsWith('Test ')) {
      await page.request.delete(`/${slug}/admin/api/members/${member.id}`);
    }
  }
}

/**
 * Verify element is visible and contains expected text
 */
export async function expectTextVisible(
  page: Page,
  selector: string,
  expectedText: string
): Promise<void> {
  const element = page.locator(selector);
  await expect(element).toBeVisible();
  await expect(element).toContainText(expectedText);
}

/**
 * Verify toast/notification message
 */
export async function expectNotification(
  page: Page,
  expectedText: string,
  type: 'success' | 'error' | 'info' = 'success'
): Promise<void> {
  const toastSelector = `.toast, .notification, .alert, [role="alert"]`;
  const toast = page.locator(toastSelector).filter({ hasText: expectedText });
  await expect(toast.first()).toBeVisible({ timeout: 5000 });
}

/**
 * Fill form fields
 */
export async function fillForm(
  page: Page,
  fields: Record<string, string>
): Promise<void> {
  for (const [name, value] of Object.entries(fields)) {
    const input = page.locator(`input[name="${name}"], textarea[name="${name}"], select[name="${name}"]`);
    const tagName = await input.evaluate((el) => el.tagName.toLowerCase());

    if (tagName === 'select') {
      await input.selectOption(value);
    } else {
      await input.fill(value);
    }
  }
}

/**
 * Wait for page to be fully loaded
 */
export async function waitForPageLoad(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle');
}

/**
 * Take a screenshot with descriptive name
 */
export async function takeScreenshot(
  page: Page,
  name: string
): Promise<void> {
  await page.screenshot({
    path: `test-results/screenshots/${name}-${Date.now()}.png`,
    fullPage: true,
  });
}

/**
 * Create an API token for testing (requires admin authentication first)
 */
export async function createApiToken(
  page: Page,
  slug: string,
  permissions: string[]
): Promise<string | null> {
  try {
    const response = await page.request.post(`${slug}/admin/api/tokens`, {
      data: JSON.stringify({
        name: `E2E Test Token ${Date.now()}`,
        permissions,
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    if (!response.ok()) {
      console.error('Failed to create API token:', response.status());
      return null;
    }

    const data = await response.json();
    return data.token || data.plain_token || null;
  } catch (error) {
    console.error('Error creating API token:', error);
    return null;
  }
}

/**
 * Get or create an API token (authenticates if needed)
 */
export async function getApiToken(
  page: Page,
  slug: string,
  permissions: string[]
): Promise<string | null> {
  // First authenticate as admin
  await authenticateAsAdmin(page, slug);

  // Then create the token
  return createApiToken(page, slug, permissions);
}
