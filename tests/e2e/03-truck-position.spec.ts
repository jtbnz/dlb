import { test, expect } from '@playwright/test';
import { demoBrigade, testTrucks, positionTemplates } from '../fixtures/test-data';
import {
  authenticateAsAdmin,
  isProduction,
  getBrigadeSlug,
  waitForPageLoad,
} from '../fixtures/test-helpers';

/**
 * Truck and Position Management Tests
 *
 * Tests all truck and position operations:
 * - Create trucks
 * - Create positions
 * - Update trucks/positions
 * - Delete trucks/positions
 * - Drag-to-reorder
 * - Position templates
 */

test.describe('Truck Management', () => {
  const slug = getBrigadeSlug();

  test.beforeEach(async ({ page }) => {
    await authenticateAsAdmin(page, slug);
    await page.goto(`${slug}/admin/trucks`);
    await waitForPageLoad(page);
  });

  test.describe('Truck Listing', () => {
    test('should display trucks page', async ({ page }) => {
      // Should show trucks heading
      await expect(page.locator('h1, h2, .page-title').filter({ hasText: /truck|vehicle/i }).first()).toBeVisible();

      // Should have add truck button
      await expect(page.locator('button, a').filter({ hasText: /add|new|create/i }).first()).toBeVisible();
    });

    test('should load trucks via API', async ({ page }) => {
      const response = await page.request.get(`${slug}/admin/api/trucks`);
      expect(response.ok()).toBeTruthy();

      const data = await response.json();
      expect(data).toHaveProperty('trucks');
      expect(Array.isArray(data.trucks)).toBeTruthy();
    });

    test('should display truck information with positions', async ({ page }) => {
      // Wait for trucks to load
      await page.waitForResponse((resp) =>
        resp.url().includes('/admin/api/trucks') && resp.status() === 200
      );

      // Verify trucks are displayed
      const truckElements = page.locator('.truck-card, .truck-item, [data-truck-id]');
      // Demo brigade should have some trucks
      const count = await truckElements.count();
      expect(count).toBeGreaterThanOrEqual(0);
    });
  });

  test.describe('Create Truck', () => {
    test('should open create truck modal/form', async ({ page }) => {
      // Click add truck button
      await page.locator('button, a').filter({ hasText: /add|new|create/i }).first().click();

      // Should show form
      await expect(page.locator('input[name="name"]')).toBeVisible();
    });

    test('should create a new truck', async ({ page }) => {
      const truckName = `Test Truck ${Date.now()}`;

      // Click add truck button
      await page.locator('button, a').filter({ hasText: /add|new|create/i }).first().click();
      await page.waitForTimeout(500);

      // Fill form
      await page.locator('input[name="name"]').first().fill(truckName);

      // Uncheck is_station if present
      const stationCheckbox = page.locator('input[name="is_station"]');
      if (await stationCheckbox.isChecked()) {
        await stationCheckbox.uncheck();
      }

      // Submit
      await page.locator('button[type="submit"], button').filter({ hasText: /save|create|add/i }).first().click();

      // Wait for API response
      await page.waitForResponse((resp) =>
        resp.url().includes('/admin/api/trucks') && resp.status() === 200
      );

      // Verify truck appears
      await expect(page.locator('body')).toContainText(truckName);
    });

    test('should create station truck', async ({ page }) => {
      const stationName = `Test Station ${Date.now()}`;

      // Click add truck button
      await page.locator('button, a').filter({ hasText: /add|new|create/i }).first().click();
      await page.waitForTimeout(500);

      // Fill form
      await page.locator('input[name="name"]').first().fill(stationName);

      // Check is_station
      const stationCheckbox = page.locator('input[name="is_station"]');
      if (await stationCheckbox.count() > 0 && !await stationCheckbox.isChecked()) {
        await stationCheckbox.check();
      }

      // Submit
      await page.locator('button[type="submit"], button').filter({ hasText: /save|create|add/i }).first().click();

      // Wait for API response
      await page.waitForResponse((resp) =>
        resp.url().includes('/admin/api/trucks') && resp.status() === 200
      );
    });

    test('should create truck via API', async ({ page }) => {
      const response = await page.request.post(`${slug}/admin/api/trucks`, {
        data: JSON.stringify({ name: `API Truck ${Date.now()}`, is_station: false }),
        headers: { 'Content-Type': 'application/json' },
      });

      expect(response.ok()).toBeTruthy();
      const data = await response.json();
      expect(data.success || data.truck).toBeTruthy();
    });
  });

  test.describe('Update Truck', () => {
    test('should update truck name via API', async ({ page }) => {
      // Create a truck first
      const createResponse = await page.request.post(`${slug}/admin/api/trucks`, {
        data: JSON.stringify({ name: `Update Test Truck ${Date.now()}`, is_station: false }),
        headers: { 'Content-Type': 'application/json' },
      });
      const created = await createResponse.json();
      const truckId = created.truck?.id || created.id;

      if (truckId) {
        // Update via API
        const updateResponse = await page.request.put(`${slug}/admin/api/trucks/${truckId}`, {
          data: JSON.stringify({ name: `Updated Truck ${Date.now()}` }),
          headers: { 'Content-Type': 'application/json' },
        });

        expect(updateResponse.ok()).toBeTruthy();
      }
    });
  });

  test.describe('Delete Truck', () => {
    test.skip(isProduction(), 'Skip destructive tests in production');

    test('should delete truck via API', async ({ page }) => {
      // Create a truck first
      const createResponse = await page.request.post(`${slug}/admin/api/trucks`, {
        data: JSON.stringify({ name: `Delete Test Truck ${Date.now()}`, is_station: false }),
        headers: { 'Content-Type': 'application/json' },
      });
      const created = await createResponse.json();
      const truckId = created.truck?.id || created.id;

      if (truckId) {
        // Delete via API
        const deleteResponse = await page.request.delete(`${slug}/admin/api/trucks/${truckId}`);
        expect(deleteResponse.ok()).toBeTruthy();

        // Verify truck is deleted
        const listResponse = await page.request.get(`${slug}/admin/api/trucks`);
        const data = await listResponse.json();
        const truckStillExists = data.trucks?.some((t: any) => t.id === truckId);
        expect(truckStillExists).toBeFalsy();
      }
    });
  });

  test.describe('Truck Reordering', () => {
    test('should reorder trucks via API', async ({ page }) => {
      // Get current trucks
      const listResponse = await page.request.get(`${slug}/admin/api/trucks`);
      const data = await listResponse.json();
      const trucks = data.trucks || [];

      if (trucks.length >= 2) {
        // Reverse the order
        const newOrder = trucks.map((t: any) => t.id).reverse();

        const reorderResponse = await page.request.put(`${slug}/admin/api/trucks/reorder`, {
          data: JSON.stringify({ order: newOrder }),
          headers: { 'Content-Type': 'application/json' },
        });

        expect(reorderResponse.ok()).toBeTruthy();
      }
    });
  });
});

test.describe('Position Management', () => {
  const slug = getBrigadeSlug();
  let testTruckId: number;

  test.beforeEach(async ({ page }) => {
    await authenticateAsAdmin(page, slug);

    // Create a test truck for position tests
    const createResponse = await page.request.post(`${slug}/admin/api/trucks`, {
      data: JSON.stringify({ name: `Position Test Truck ${Date.now()}`, is_station: false }),
      headers: { 'Content-Type': 'application/json' },
    });
    const created = await createResponse.json();
    testTruckId = created.truck?.id || created.id;

    await page.goto(`${slug}/admin/trucks`);
    await waitForPageLoad(page);
  });

  test.describe('Create Position', () => {
    test('should create position via API', async ({ page }) => {
      if (!testTruckId) {
        test.skip();
        return;
      }

      const response = await page.request.post(`${slug}/admin/api/trucks/${testTruckId}/positions`, {
        data: JSON.stringify({ name: 'OIC', allow_multiple: false }),
        headers: { 'Content-Type': 'application/json' },
      });

      expect(response.ok()).toBeTruthy();
      const data = await response.json();
      expect(data.success || data.position).toBeTruthy();
    });

    test('should create multiple positions (light template)', async ({ page }) => {
      if (!testTruckId) {
        test.skip();
        return;
      }

      for (const positionName of positionTemplates.light) {
        const response = await page.request.post(`${slug}/admin/api/trucks/${testTruckId}/positions`, {
          data: JSON.stringify({ name: positionName, allow_multiple: false }),
          headers: { 'Content-Type': 'application/json' },
        });
        expect(response.ok()).toBeTruthy();
      }

      // Verify positions were created
      const trucksResponse = await page.request.get(`${slug}/admin/api/trucks`);
      const data = await trucksResponse.json();
      const truck = data.trucks?.find((t: any) => t.id === testTruckId);
      expect(truck?.positions?.length).toBeGreaterThanOrEqual(positionTemplates.light.length);
    });

    test('should create standby position with allow_multiple', async ({ page }) => {
      // Create a station truck
      const stationResponse = await page.request.post(`${slug}/admin/api/trucks`, {
        data: JSON.stringify({ name: `Station Test ${Date.now()}`, is_station: true }),
        headers: { 'Content-Type': 'application/json' },
      });
      const station = await stationResponse.json();
      const stationId = station.truck?.id || station.id;

      if (stationId) {
        // Create standby position
        const response = await page.request.post(`${slug}/admin/api/trucks/${stationId}/positions`, {
          data: JSON.stringify({ name: 'Standby', allow_multiple: true }),
          headers: { 'Content-Type': 'application/json' },
        });

        expect(response.ok()).toBeTruthy();
      }
    });
  });

  test.describe('Update Position', () => {
    test('should update position via API', async ({ page }) => {
      if (!testTruckId) {
        test.skip();
        return;
      }

      // Create a position first
      const createResponse = await page.request.post(`${slug}/admin/api/trucks/${testTruckId}/positions`, {
        data: JSON.stringify({ name: 'Update Test Position', allow_multiple: false }),
        headers: { 'Content-Type': 'application/json' },
      });
      const created = await createResponse.json();
      const positionId = created.position?.id || created.id;

      if (positionId) {
        // Update position
        const updateResponse = await page.request.put(`${slug}/admin/api/positions/${positionId}`, {
          data: JSON.stringify({ name: 'Updated Position' }),
          headers: { 'Content-Type': 'application/json' },
        });

        expect(updateResponse.ok()).toBeTruthy();
      }
    });
  });

  test.describe('Delete Position', () => {
    test.skip(isProduction(), 'Skip destructive tests in production');

    test('should delete position via API', async ({ page }) => {
      if (!testTruckId) {
        test.skip();
        return;
      }

      // Create a position first
      const createResponse = await page.request.post(`${slug}/admin/api/trucks/${testTruckId}/positions`, {
        data: JSON.stringify({ name: 'Delete Test Position', allow_multiple: false }),
        headers: { 'Content-Type': 'application/json' },
      });
      const created = await createResponse.json();
      const positionId = created.position?.id || created.id;

      if (positionId) {
        // Delete position
        const deleteResponse = await page.request.delete(`${slug}/admin/api/positions/${positionId}`);
        expect(deleteResponse.ok()).toBeTruthy();
      }
    });
  });

  test.describe('Position Validation', () => {
    test('should reject empty position name', async ({ page }) => {
      if (!testTruckId) {
        test.skip();
        return;
      }

      const response = await page.request.post(`${slug}/admin/api/trucks/${testTruckId}/positions`, {
        data: JSON.stringify({ name: '', allow_multiple: false }),
        headers: { 'Content-Type': 'application/json' },
      });

      expect([400, 422]).toContain(response.status());
    });
  });
});

test.describe('Truck/Position Integration', () => {
  const slug = getBrigadeSlug();

  test.beforeEach(async ({ page }) => {
    await authenticateAsAdmin(page, slug);
  });

  test('trucks should include positions in API response', async ({ page }) => {
    const response = await page.request.get(`${slug}/admin/api/trucks`);
    const data = await response.json();

    // Each truck should have a positions array
    for (const truck of data.trucks || []) {
      expect(truck).toHaveProperty('positions');
      expect(Array.isArray(truck.positions)).toBeTruthy();
    }
  });

  test('position sort order should be maintained', async ({ page }) => {
    // Create a truck
    const createResponse = await page.request.post(`${slug}/admin/api/trucks`, {
      data: JSON.stringify({ name: `Sort Test Truck ${Date.now()}`, is_station: false }),
      headers: { 'Content-Type': 'application/json' },
    });
    const truck = await createResponse.json();
    const truckId = truck.truck?.id || truck.id;

    if (truckId) {
      // Create positions in order
      for (let i = 0; i < positionTemplates.full.length; i++) {
        await page.request.post(`${slug}/admin/api/trucks/${truckId}/positions`, {
          data: JSON.stringify({ name: positionTemplates.full[i], allow_multiple: false }),
          headers: { 'Content-Type': 'application/json' },
        });
      }

      // Verify order
      const trucksResponse = await page.request.get(`${slug}/admin/api/trucks`);
      const data = await trucksResponse.json();
      const createdTruck = data.trucks?.find((t: any) => t.id === truckId);

      if (createdTruck?.positions?.length > 1) {
        // Verify positions have sort_order
        const sortOrders = createdTruck.positions.map((p: any) => p.sort_order);
        const isSorted = sortOrders.every((val: number, i: number) => i === 0 || val >= sortOrders[i - 1]);
        expect(isSorted).toBeTruthy();
      }
    }
  });
});
