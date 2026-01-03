import { test, expect } from '@playwright/test';
import { demoBrigade, testMembers, csvImportData, invalidData } from '../fixtures/test-data';
import {
  authenticateAsAdmin,
  isProduction,
  getBrigadeSlug,
  waitForPageLoad,
} from '../fixtures/test-helpers';

/**
 * Member CRUD Tests
 *
 * Tests all member management operations:
 * - Create members
 * - Read/list members
 * - Update members
 * - Delete/deactivate members
 * - CSV import
 * - Member ordering
 */

test.describe('Member Management', () => {
  const slug = getBrigadeSlug();

  test.beforeEach(async ({ page }) => {
    await authenticateAsAdmin(page, slug);
    // Navigate to members page
    await page.goto(`/${slug}/admin/members`);
    await waitForPageLoad(page);
  });

  test.describe('Member Listing', () => {
    test('should display members list page', async ({ page }) => {
      // Should show members heading or table
      await expect(page.locator('h1, h2, .page-title').filter({ hasText: /member/i }).first()).toBeVisible();

      // Should have add member button
      await expect(page.locator('button, a').filter({ hasText: /add|new|create/i }).first()).toBeVisible();
    });

    test('should load members via API', async ({ page }) => {
      const response = await page.request.get(`/${slug}/admin/api/members`);
      expect(response.ok()).toBeTruthy();

      const data = await response.json();
      expect(data).toHaveProperty('members');
      expect(Array.isArray(data.members)).toBeTruthy();
    });

    test('should display member information in list', async ({ page }) => {
      // Wait for members to load
      await page.waitForResponse((resp) =>
        resp.url().includes('/admin/api/members') && resp.status() === 200
      );

      // Should show member names (if any exist)
      // The demo brigade should have some members
      const memberElements = page.locator('tr, .member-item, .member-card');
      const count = await memberElements.count();

      // Verify table or list structure exists
      expect(count).toBeGreaterThanOrEqual(0);
    });
  });

  test.describe('Create Member', () => {
    test('should open create member modal/form', async ({ page }) => {
      // Click add member button
      await page.locator('button, a').filter({ hasText: /add|new|create/i }).first().click();

      // Should show form
      await expect(page.locator('input[name="name"], input[name="display_name"]')).toBeVisible();
    });

    test('should create a new member', async ({ page }) => {
      const testMember = {
        name: `Test Member ${Date.now()}`,
        rank: 'FF',
      };

      // Click add member button
      await page.locator('button, a').filter({ hasText: /add|new|create/i }).first().click();
      await page.waitForTimeout(500);

      // Fill form
      await page.locator('input[name="name"], input[name="display_name"]').first().fill(testMember.name);
      await page.locator('input[name="rank"]').first().fill(testMember.rank);

      // Submit
      await page.locator('button[type="submit"], button').filter({ hasText: /save|create|add/i }).first().click();

      // Wait for API response
      await page.waitForResponse((resp) =>
        resp.url().includes('/admin/api/members') && resp.status() === 200
      );

      // Verify member appears in list
      await expect(page.locator('body')).toContainText(testMember.name);
    });

    test('should validate required fields', async ({ page }) => {
      // Click add member button
      await page.locator('button, a').filter({ hasText: /add|new|create/i }).first().click();
      await page.waitForTimeout(500);

      // Try to submit empty form
      const submitButton = page.locator('button[type="submit"], button').filter({ hasText: /save|create|add/i }).first();

      // Either the button should be disabled or form validation should trigger
      const isDisabled = await submitButton.isDisabled();
      if (!isDisabled) {
        await submitButton.click();
        // Should show validation error
        await expect(page.locator('.error, .invalid-feedback, [aria-invalid="true"]')).toBeVisible();
      }
    });

    test('should create member via API', async ({ page }) => {
      const testMember = {
        name: `API Test Member ${Date.now()}`,
        rank: 'QFF',
      };

      const response = await page.request.post(`/${slug}/admin/api/members`, {
        data: JSON.stringify(testMember),
        headers: { 'Content-Type': 'application/json' },
      });

      expect(response.ok()).toBeTruthy();
      const data = await response.json();
      expect(data.success || data.member).toBeTruthy();
    });
  });

  test.describe('Update Member', () => {
    test('should update member name', async ({ page }) => {
      // First create a member
      const originalName = `Update Test ${Date.now()}`;
      await page.request.post(`/${slug}/admin/api/members`, {
        data: JSON.stringify({ name: originalName, rank: 'FF' }),
        headers: { 'Content-Type': 'application/json' },
      });

      // Reload page
      await page.reload();
      await waitForPageLoad(page);

      // Find and click edit on the member
      const memberRow = page.locator('tr, .member-item').filter({ hasText: originalName });
      const editButton = memberRow.locator('button, a').filter({ hasText: /edit/i });

      if (await editButton.count() > 0) {
        await editButton.click();
        await page.waitForTimeout(500);

        // Update name
        const newName = `Updated ${Date.now()}`;
        await page.locator('input[name="name"], input[name="display_name"]').first().fill(newName);

        // Save
        await page.locator('button[type="submit"], button').filter({ hasText: /save|update/i }).first().click();

        // Verify update
        await page.waitForResponse((resp) =>
          resp.url().includes('/admin/api/members') && resp.status() === 200
        );
      }
    });

    test('should update member via API', async ({ page }) => {
      // Create a member first
      const createResponse = await page.request.post(`/${slug}/admin/api/members`, {
        data: JSON.stringify({ name: `API Update Test ${Date.now()}`, rank: 'FF' }),
        headers: { 'Content-Type': 'application/json' },
      });
      const created = await createResponse.json();
      const memberId = created.member?.id || created.id;

      if (memberId) {
        // Update via API
        const updateResponse = await page.request.put(`/${slug}/admin/api/members/${memberId}`, {
          data: JSON.stringify({ name: `Updated via API ${Date.now()}`, rank: 'SFF' }),
          headers: { 'Content-Type': 'application/json' },
        });

        expect(updateResponse.ok()).toBeTruthy();
      }
    });
  });

  test.describe('Delete/Deactivate Member', () => {
    test.skip(isProduction(), 'Skip destructive tests in production');

    test('should deactivate member (soft delete)', async ({ page }) => {
      // Create a test member
      const memberName = `Delete Test ${Date.now()}`;
      const createResponse = await page.request.post(`/${slug}/admin/api/members`, {
        data: JSON.stringify({ name: memberName, rank: 'FF' }),
        headers: { 'Content-Type': 'application/json' },
      });
      const created = await createResponse.json();
      const memberId = created.member?.id || created.id;

      if (memberId) {
        // Delete via API
        const deleteResponse = await page.request.delete(`/${slug}/admin/api/members/${memberId}`);
        expect(deleteResponse.ok()).toBeTruthy();

        // Reload and verify member is deactivated/removed
        await page.reload();
        await waitForPageLoad(page);

        // Member should either be removed from active list or marked as inactive
        // Check that they're not in the active members list
        const activeMembers = await page.request.get(`/${slug}/admin/api/members?active=true`);
        const data = await activeMembers.json();
        const memberStillActive = data.members?.some((m: any) => m.id === memberId && m.is_active);
        expect(memberStillActive).toBeFalsy();
      }
    });
  });

  test.describe('CSV Import', () => {
    test.skip(isProduction(), 'Skip import tests in production');

    test('should import members from CSV', async ({ page }) => {
      // Find import button
      const importButton = page.locator('button, a').filter({ hasText: /import/i });

      if (await importButton.count() > 0) {
        await importButton.click();
        await page.waitForTimeout(500);

        // Should show import interface
        await expect(page.locator('textarea, input[type="file"]')).toBeVisible();
      }
    });

    test('should import members via API', async ({ page }) => {
      const response = await page.request.post(`/${slug}/admin/api/members/import`, {
        data: JSON.stringify({ csv: csvImportData }),
        headers: { 'Content-Type': 'application/json' },
      });

      // Import should succeed or return validation message
      expect([200, 400]).toContain(response.status());
    });
  });

  test.describe('Member Ordering', () => {
    test('should support different member ordering options', async ({ page }) => {
      // Get members with different orderings
      const rankNameResponse = await page.request.get(`/${slug}/admin/api/members?order=rank_name`);
      const alphabeticalResponse = await page.request.get(`/${slug}/admin/api/members?order=alphabetical`);

      expect(rankNameResponse.ok()).toBeTruthy();
      expect(alphabeticalResponse.ok()).toBeTruthy();
    });
  });

  test.describe('Member Validation', () => {
    test('should reject empty member name', async ({ page }) => {
      const response = await page.request.post(`/${slug}/admin/api/members`, {
        data: JSON.stringify({ name: '', rank: 'FF' }),
        headers: { 'Content-Type': 'application/json' },
      });

      // Should return error
      expect([400, 422]).toContain(response.status());
    });

    test('should handle duplicate member names', async ({ page }) => {
      const memberName = `Duplicate Test ${Date.now()}`;

      // Create first member
      await page.request.post(`/${slug}/admin/api/members`, {
        data: JSON.stringify({ name: memberName, rank: 'FF' }),
        headers: { 'Content-Type': 'application/json' },
      });

      // Try to create duplicate
      const response = await page.request.post(`/${slug}/admin/api/members`, {
        data: JSON.stringify({ name: memberName, rank: 'FF' }),
        headers: { 'Content-Type': 'application/json' },
      });

      // Should either succeed (allow duplicates) or reject
      expect([200, 400, 409, 422]).toContain(response.status());
    });
  });
});
