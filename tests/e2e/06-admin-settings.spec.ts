import { test, expect } from '@playwright/test';
import { demoBrigade, testSettings, superAdmin } from '../fixtures/test-data';
import {
  authenticateAsAdmin,
  authenticateAsSuperAdmin,
  isProduction,
  getBrigadeSlug,
  waitForPageLoad,
} from '../fixtures/test-helpers';

/**
 * Admin Settings and Audit Log Tests
 *
 * Tests admin functionality:
 * - Brigade settings
 * - PIN management
 * - Password management
 * - Email configuration
 * - QR code generation
 * - Backup/restore
 * - Audit log
 */

test.describe('Brigade Settings', () => {
  const slug = getBrigadeSlug();

  test.beforeEach(async ({ page }) => {
    await authenticateAsAdmin(page, slug);
    await page.goto(`/${slug}/admin/settings`);
    await waitForPageLoad(page);
  });

  test('should display settings page', async ({ page }) => {
    // Should show settings heading
    await expect(page.locator('h1, h2, .page-title').filter({ hasText: /setting/i }).first()).toBeVisible();
  });

  test('should load settings via API', async ({ page }) => {
    const response = await page.request.get(`/${slug}/admin/api/settings`);
    expect(response.ok()).toBeTruthy();

    const data = await response.json();
    expect(data.settings || data.brigade || data.name).toBeDefined();
  });

  test('should update brigade settings', async ({ page }) => {
    const response = await page.request.put(`/${slug}/admin/api/settings`, {
      data: JSON.stringify({
        include_non_attendees: true,
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    expect(response.ok()).toBeTruthy();
  });

  test('should update email recipients', async ({ page }) => {
    const response = await page.request.put(`/${slug}/admin/api/settings`, {
      data: JSON.stringify({
        email_recipients: testSettings.email_recipients,
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    expect(response.ok()).toBeTruthy();
  });

  test('should update member ordering preference', async ({ page }) => {
    const response = await page.request.put(`/${slug}/admin/api/settings`, {
      data: JSON.stringify({
        member_order: 'alphabetical',
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    expect(response.ok()).toBeTruthy();
  });
});

test.describe('PIN Management', () => {
  const slug = getBrigadeSlug();

  test.skip(isProduction(), 'Skip PIN changes in production');

  test.beforeEach(async ({ page }) => {
    await authenticateAsAdmin(page, slug);
  });

  test('should update brigade PIN', async ({ page }) => {
    const newPin = '5678';

    const response = await page.request.put(`/${slug}/admin/api/settings/pin`, {
      data: JSON.stringify({
        current_password: demoBrigade.adminPassword,
        new_pin: newPin,
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    expect(response.ok()).toBeTruthy();

    // Restore original PIN
    await page.request.put(`/${slug}/admin/api/settings/pin`, {
      data: JSON.stringify({
        current_password: demoBrigade.adminPassword,
        new_pin: demoBrigade.pin,
      }),
      headers: { 'Content-Type': 'application/json' },
    });
  });

  test('should reject PIN change without password', async ({ page }) => {
    const response = await page.request.put(`/${slug}/admin/api/settings/pin`, {
      data: JSON.stringify({
        new_pin: '9999',
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    expect([400, 401, 422]).toContain(response.status());
  });

  test('should validate PIN format', async ({ page }) => {
    // Test too short PIN
    const response = await page.request.put(`/${slug}/admin/api/settings/pin`, {
      data: JSON.stringify({
        current_password: demoBrigade.adminPassword,
        new_pin: '12', // Too short
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    expect([400, 422]).toContain(response.status());
  });
});

test.describe('Password Management', () => {
  const slug = getBrigadeSlug();

  test.skip(isProduction(), 'Skip password changes in production');

  test.beforeEach(async ({ page }) => {
    await authenticateAsAdmin(page, slug);
  });

  test('should update admin password', async ({ page }) => {
    const newPassword = 'newpassword123';

    const response = await page.request.put(`/${slug}/admin/api/settings/password`, {
      data: JSON.stringify({
        current_password: demoBrigade.adminPassword,
        new_password: newPassword,
        confirm_password: newPassword,
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    expect(response.ok()).toBeTruthy();

    // Restore original password
    await page.request.put(`/${slug}/admin/api/settings/password`, {
      data: JSON.stringify({
        current_password: newPassword,
        new_password: demoBrigade.adminPassword,
        confirm_password: demoBrigade.adminPassword,
      }),
      headers: { 'Content-Type': 'application/json' },
    });
  });

  test('should reject password change with wrong current password', async ({ page }) => {
    const response = await page.request.put(`/${slug}/admin/api/settings/password`, {
      data: JSON.stringify({
        current_password: 'wrongpassword',
        new_password: 'newpassword123',
        confirm_password: 'newpassword123',
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    expect([400, 401, 422]).toContain(response.status());
  });

  test('should enforce minimum password length', async ({ page }) => {
    const response = await page.request.put(`/${slug}/admin/api/settings/password`, {
      data: JSON.stringify({
        current_password: demoBrigade.adminPassword,
        new_password: 'short', // Too short
        confirm_password: 'short',
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    expect([400, 422]).toContain(response.status());
  });

  test('should require password confirmation match', async ({ page }) => {
    const response = await page.request.put(`/${slug}/admin/api/settings/password`, {
      data: JSON.stringify({
        current_password: demoBrigade.adminPassword,
        new_password: 'newpassword123',
        confirm_password: 'differentpassword',
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    expect([400, 422]).toContain(response.status());
  });
});

test.describe('QR Code Generation', () => {
  const slug = getBrigadeSlug();

  test.beforeEach(async ({ page }) => {
    await authenticateAsAdmin(page, slug);
  });

  test('should generate QR code', async ({ page }) => {
    const response = await page.request.get(`/${slug}/admin/api/qrcode`);
    expect(response.ok()).toBeTruthy();

    // Should return HTML or image
    const contentType = response.headers()['content-type'];
    expect(contentType).toMatch(/html|image/);
  });

  test('should download QR code as PNG', async ({ page }) => {
    const response = await page.request.get(`/${slug}/admin/api/qrcode/download`);
    expect(response.ok()).toBeTruthy();

    // Should return PNG image
    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('image/png');
  });
});

test.describe('Backup and Restore', () => {
  const slug = getBrigadeSlug();

  test.skip(isProduction(), 'Skip backup/restore in production');

  test.beforeEach(async ({ page }) => {
    await authenticateAsAdmin(page, slug);
  });

  test('should download database backup', async ({ page }) => {
    const response = await page.request.get(`/${slug}/admin/api/backup`);
    expect(response.ok()).toBeTruthy();

    // Should return SQLite file
    const contentType = response.headers()['content-type'];
    expect(contentType).toMatch(/octet-stream|sqlite|application/);
  });

  test('should have restore endpoint', async ({ page }) => {
    // Test that restore endpoint exists (don't actually restore)
    // This just verifies the route is defined
    const response = await page.request.post(`/${slug}/admin/api/restore`, {
      data: '',
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    // Should return error for empty upload, not 404
    expect([400, 422]).toContain(response.status());
  });
});

test.describe('Audit Log', () => {
  const slug = getBrigadeSlug();

  test.beforeEach(async ({ page }) => {
    await authenticateAsAdmin(page, slug);
  });

  test('should display audit log page', async ({ page }) => {
    await page.goto(`/${slug}/admin/audit`);
    await waitForPageLoad(page);

    // Should show audit log heading
    await expect(page.locator('h1, h2, .page-title').filter({ hasText: /audit|log|activity/i }).first()).toBeVisible();
  });

  test('should load audit log via API', async ({ page }) => {
    const response = await page.request.get(`/${slug}/admin/api/audit`);
    expect(response.ok()).toBeTruthy();

    const data = await response.json();
    expect(data.logs || data.audit || Array.isArray(data)).toBeTruthy();
  });

  test('should filter audit log by action', async ({ page }) => {
    const response = await page.request.get(`/${slug}/admin/api/audit?action=attendance_added`);
    expect(response.ok()).toBeTruthy();
  });

  test('should filter audit log by date range', async ({ page }) => {
    const today = new Date().toISOString().split('T')[0];
    const response = await page.request.get(`/${slug}/admin/api/audit?from=${today}&to=${today}`);
    expect(response.ok()).toBeTruthy();
  });

  test('audit entries should have required fields', async ({ page }) => {
    const response = await page.request.get(`/${slug}/admin/api/audit`);
    const data = await response.json();

    const logs = data.logs || data.audit || data;
    if (Array.isArray(logs) && logs.length > 0) {
      const entry = logs[0];
      // Check for common audit log fields
      expect(entry.action || entry.event || entry.type).toBeDefined();
      expect(entry.created_at || entry.timestamp || entry.date).toBeDefined();
    }
  });
});

test.describe('Super Admin Brigade Management', () => {
  test.skip(isProduction(), 'Skip super admin tests in production');

  test.beforeEach(async ({ page }) => {
    await authenticateAsSuperAdmin(page);
  });

  test('should list all brigades', async ({ page }) => {
    const response = await page.request.get('/admin/api/brigades');
    expect(response.ok()).toBeTruthy();

    const data = await response.json();
    expect(data.brigades || Array.isArray(data)).toBeTruthy();
  });

  test('should create new brigade', async ({ page }) => {
    const response = await page.request.post('/admin/api/brigades', {
      data: JSON.stringify({
        name: `Test Brigade ${Date.now()}`,
        slug: `test-brigade-${Date.now()}`,
        pin: '1234',
        admin_username: 'testadmin',
        admin_password: 'testpassword123',
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    expect(response.ok()).toBeTruthy();
  });

  test('should update brigade', async ({ page }) => {
    // Get list of brigades
    const listResponse = await page.request.get('/admin/api/brigades');
    const listData = await listResponse.json();
    const brigades = listData.brigades || listData;

    if (Array.isArray(brigades) && brigades.length > 0) {
      // Find a test brigade to update
      const testBrigade = brigades.find((b: any) => b.slug?.startsWith('test-'));

      if (testBrigade) {
        const response = await page.request.put(`/admin/api/brigades/${testBrigade.id}`, {
          data: JSON.stringify({
            name: `Updated Brigade ${Date.now()}`,
          }),
          headers: { 'Content-Type': 'application/json' },
        });

        expect(response.ok()).toBeTruthy();
      }
    }
  });

  test('should delete brigade', async ({ page }) => {
    // Create a brigade to delete
    const createResponse = await page.request.post('/admin/api/brigades', {
      data: JSON.stringify({
        name: `Delete Test Brigade ${Date.now()}`,
        slug: `delete-test-${Date.now()}`,
        pin: '1234',
        admin_username: 'deleteadmin',
        admin_password: 'deletepassword123',
      }),
      headers: { 'Content-Type': 'application/json' },
    });
    const created = await createResponse.json();
    const brigadeId = created.brigade?.id || created.id;

    if (brigadeId) {
      const deleteResponse = await page.request.delete(`/admin/api/brigades/${brigadeId}`);
      expect(deleteResponse.ok()).toBeTruthy();
    }
  });

  test('should validate unique brigade slug', async ({ page }) => {
    // Try to create brigade with existing slug
    const response = await page.request.post('/admin/api/brigades', {
      data: JSON.stringify({
        name: 'Duplicate Slug Test',
        slug: 'demo', // Already exists
        pin: '1234',
        admin_username: 'dupeadmin',
        admin_password: 'dupepassword123',
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    expect([400, 409, 422]).toContain(response.status());
  });
});

test.describe('FENZ Integration Status', () => {
  test.skip(isProduction(), 'Skip FENZ tests in production');

  test.beforeEach(async ({ page }) => {
    await authenticateAsSuperAdmin(page);
  });

  test('should display FENZ status page', async ({ page }) => {
    await page.goto('/admin/fenz-status');
    await waitForPageLoad(page);

    // Should show FENZ status content
    await expect(page.locator('body')).toContainText(/fenz|status|incident/i);
  });

  test('should get FENZ status via API', async ({ page }) => {
    const response = await page.request.get('/admin/api/fenz-status');
    expect(response.ok()).toBeTruthy();
  });

  test('should trigger FENZ fetch', async ({ page }) => {
    const response = await page.request.post('/admin/api/fenz-trigger');
    // May fail if no region configured, but should not 404
    expect([200, 400, 500]).toContain(response.status());
  });
});

test.describe('Admin Navigation', () => {
  const slug = getBrigadeSlug();

  test.beforeEach(async ({ page }) => {
    await authenticateAsAdmin(page, slug);
  });

  test('should navigate to all admin pages', async ({ page }) => {
    const pages = [
      { url: `/${slug}/admin/dashboard`, title: /dashboard|overview/i },
      { url: `/${slug}/admin/members`, title: /member/i },
      { url: `/${slug}/admin/trucks`, title: /truck|vehicle/i },
      { url: `/${slug}/admin/callouts`, title: /callout|history/i },
      { url: `/${slug}/admin/settings`, title: /setting/i },
      { url: `/${slug}/admin/audit`, title: /audit|log/i },
      { url: `/${slug}/admin/api-tokens`, title: /token|api/i },
    ];

    for (const adminPage of pages) {
      await page.goto(adminPage.url);
      await waitForPageLoad(page);

      // Verify page loads without error
      await expect(page.locator('h1, h2, .page-title').first()).toBeVisible();
    }
  });

  test('should show consistent navigation across pages', async ({ page }) => {
    await page.goto(`/${slug}/admin/dashboard`);
    await waitForPageLoad(page);

    // Check for navigation elements
    const nav = page.locator('nav, .sidebar, .admin-nav');
    await expect(nav.first()).toBeVisible();

    // Verify navigation links exist
    const navLinks = ['Members', 'Trucks', 'Callouts', 'Settings'];
    for (const link of navLinks) {
      await expect(page.locator(`a, button`).filter({ hasText: new RegExp(link, 'i') }).first()).toBeVisible();
    }
  });
});
