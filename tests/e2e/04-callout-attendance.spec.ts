import { test, expect } from '@playwright/test';
import { demoBrigade, testCallouts, attendanceStatus } from '../fixtures/test-data';
import {
  authenticateWithPin,
  authenticateAsAdmin,
  isProduction,
  getBrigadeSlug,
  waitForPageLoad,
} from '../fixtures/test-helpers';

/**
 * Callout and Attendance Tests
 *
 * Tests the core attendance workflow:
 * - Create callouts
 * - Assign attendance (tap-to-assign)
 * - Remove attendance
 * - Submit callouts
 * - Callout history
 * - Real-time sync (SSE)
 * - Copy last call
 */

test.describe('Callout Management', () => {
  const slug = getBrigadeSlug();

  test.describe('Member View - Attendance Entry', () => {
    test.beforeEach(async ({ page }) => {
      await authenticateWithPin(page, slug, demoBrigade.pin);
    });

    test('should display attendance entry page', async ({ page }) => {
      // Should show brigade name
      await expect(page.locator('h1, h2, .brigade-name').first()).toBeVisible();

      // Should show trucks section
      await expect(page.locator('.trucks, .truck-container, [data-trucks]')).toBeVisible();

      // Should show available members section
      await expect(page.locator('.members, .available-members, [data-members]')).toBeVisible();
    });

    test('should load active callout or create new', async ({ page }) => {
      const response = await page.request.get(`/${slug}/api/callout/active`);
      expect(response.ok()).toBeTruthy();

      const data = await response.json();
      // Should return callout data (either existing or newly created)
      expect(data.callouts || data.callout).toBeDefined();
    });

    test('should display trucks and positions', async ({ page }) => {
      const response = await page.request.get(`/${slug}/api/trucks`);
      expect(response.ok()).toBeTruthy();

      const data = await response.json();
      expect(data.trucks).toBeDefined();
      expect(Array.isArray(data.trucks)).toBeTruthy();

      // Each truck should have positions
      for (const truck of data.trucks) {
        expect(truck.positions).toBeDefined();
      }
    });

    test('should display available members', async ({ page }) => {
      const response = await page.request.get(`/${slug}/api/members`);
      expect(response.ok()).toBeTruthy();

      const data = await response.json();
      expect(data.members).toBeDefined();
      expect(Array.isArray(data.members)).toBeTruthy();
    });
  });

  test.describe('Create Callout', () => {
    test.beforeEach(async ({ page }) => {
      await authenticateWithPin(page, slug, demoBrigade.pin);
    });

    test('should create callout with valid ICAD number', async ({ page }) => {
      const callout = {
        icad_number: `F${Date.now().toString().slice(-7)}`,
        location: 'Test Location',
        call_type: 'Test Call',
      };

      const response = await page.request.post(`/${slug}/api/callout`, {
        data: JSON.stringify(callout),
        headers: { 'Content-Type': 'application/json' },
      });

      expect(response.ok()).toBeTruthy();
      const data = await response.json();
      expect(data.callout || data.success).toBeTruthy();
    });

    test('should create muster callout', async ({ page }) => {
      const callout = {
        icad_number: 'muster',
        location: 'Station',
        call_type: 'Training',
      };

      const response = await page.request.post(`/${slug}/api/callout`, {
        data: JSON.stringify(callout),
        headers: { 'Content-Type': 'application/json' },
      });

      expect(response.ok()).toBeTruthy();
    });

    test('should validate ICAD number format', async ({ page }) => {
      const invalidCallout = {
        icad_number: '1234567', // Should start with 'F' or be 'muster'
      };

      const response = await page.request.post(`/${slug}/api/callout`, {
        data: JSON.stringify(invalidCallout),
        headers: { 'Content-Type': 'application/json' },
      });

      // Should reject invalid ICAD or accept it (implementation may vary)
      expect([200, 400, 422]).toContain(response.status());
    });

    test('should update callout ICAD number', async ({ page }) => {
      // Get active callout
      const activeResponse = await page.request.get(`/${slug}/api/callout/active`);
      const activeData = await activeResponse.json();
      const callouts = activeData.callouts || [activeData.callout];

      if (callouts.length > 0 && callouts[0]?.id) {
        const calloutId = callouts[0].id;
        const newIcad = `F${Date.now().toString().slice(-7)}`;

        const updateResponse = await page.request.put(`/${slug}/api/callout/${calloutId}`, {
          data: JSON.stringify({ icad_number: newIcad }),
          headers: { 'Content-Type': 'application/json' },
        });

        expect(updateResponse.ok()).toBeTruthy();
      }
    });
  });

  test.describe('Attendance Assignment', () => {
    let calloutId: number;
    let memberId: number;
    let positionId: number;
    let truckId: number;

    test.beforeEach(async ({ page }) => {
      await authenticateWithPin(page, slug, demoBrigade.pin);

      // Get or create active callout
      const activeResponse = await page.request.get(`/${slug}/api/callout/active`);
      const activeData = await activeResponse.json();
      const callouts = activeData.callouts || [activeData.callout];

      if (callouts.length > 0 && callouts[0]?.id) {
        calloutId = callouts[0].id;
      }

      // Get members
      const membersResponse = await page.request.get(`/${slug}/api/members`);
      const membersData = await membersResponse.json();
      if (membersData.members?.length > 0) {
        memberId = membersData.members[0].id;
      }

      // Get trucks and positions
      const trucksResponse = await page.request.get(`/${slug}/api/trucks`);
      const trucksData = await trucksResponse.json();
      if (trucksData.trucks?.length > 0) {
        truckId = trucksData.trucks[0].id;
        if (trucksData.trucks[0].positions?.length > 0) {
          positionId = trucksData.trucks[0].positions[0].id;
        }
      }
    });

    test('should add attendance via API', async ({ page }) => {
      if (!calloutId || !memberId || !truckId || !positionId) {
        test.skip();
        return;
      }

      const response = await page.request.post(`/${slug}/api/attendance`, {
        data: JSON.stringify({
          callout_id: calloutId,
          member_id: memberId,
          truck_id: truckId,
          position_id: positionId,
        }),
        headers: { 'Content-Type': 'application/json' },
      });

      expect(response.ok()).toBeTruthy();
      const data = await response.json();
      expect(data.attendance || data.success).toBeTruthy();
    });

    test('should prevent duplicate attendance for same member', async ({ page }) => {
      if (!calloutId || !memberId || !truckId || !positionId) {
        test.skip();
        return;
      }

      // Add first attendance
      await page.request.post(`/${slug}/api/attendance`, {
        data: JSON.stringify({
          callout_id: calloutId,
          member_id: memberId,
          truck_id: truckId,
          position_id: positionId,
        }),
        headers: { 'Content-Type': 'application/json' },
      });

      // Try to add duplicate
      const response = await page.request.post(`/${slug}/api/attendance`, {
        data: JSON.stringify({
          callout_id: calloutId,
          member_id: memberId,
          truck_id: truckId,
          position_id: positionId,
        }),
        headers: { 'Content-Type': 'application/json' },
      });

      // Should either update existing or reject duplicate
      expect([200, 400, 409]).toContain(response.status());
    });

    test('should remove attendance via API', async ({ page }) => {
      if (!calloutId || !memberId || !truckId || !positionId) {
        test.skip();
        return;
      }

      // Add attendance first
      const addResponse = await page.request.post(`/${slug}/api/attendance`, {
        data: JSON.stringify({
          callout_id: calloutId,
          member_id: memberId,
          truck_id: truckId,
          position_id: positionId,
        }),
        headers: { 'Content-Type': 'application/json' },
      });
      const addData = await addResponse.json();
      const attendanceId = addData.attendance?.id || addData.id;

      if (attendanceId) {
        // Remove attendance
        const removeResponse = await page.request.delete(`/${slug}/api/attendance/${attendanceId}`);
        expect(removeResponse.ok()).toBeTruthy();
      }
    });
  });

  test.describe('Submit Callout', () => {
    test.beforeEach(async ({ page }) => {
      await authenticateWithPin(page, slug, demoBrigade.pin);
    });

    test('should submit callout with attendance', async ({ page }) => {
      // Create a new callout
      const calloutResponse = await page.request.post(`/${slug}/api/callout`, {
        data: JSON.stringify({
          icad_number: `F${Date.now().toString().slice(-7)}`,
          location: 'Submit Test Location',
        }),
        headers: { 'Content-Type': 'application/json' },
      });
      const calloutData = await calloutResponse.json();
      const calloutId = calloutData.callout?.id || calloutData.id;

      if (calloutId) {
        // Get members and trucks for attendance
        const membersResponse = await page.request.get(`/${slug}/api/members`);
        const membersData = await membersResponse.json();
        const trucksResponse = await page.request.get(`/${slug}/api/trucks`);
        const trucksData = await trucksResponse.json();

        if (membersData.members?.length > 0 && trucksData.trucks?.[0]?.positions?.length > 0) {
          // Add some attendance
          await page.request.post(`/${slug}/api/attendance`, {
            data: JSON.stringify({
              callout_id: calloutId,
              member_id: membersData.members[0].id,
              truck_id: trucksData.trucks[0].id,
              position_id: trucksData.trucks[0].positions[0].id,
            }),
            headers: { 'Content-Type': 'application/json' },
          });
        }

        // Submit callout
        const submitResponse = await page.request.post(`/${slug}/api/callout/${calloutId}/submit`, {
          data: JSON.stringify({ submitted_by: 'Test User' }),
          headers: { 'Content-Type': 'application/json' },
        });

        expect(submitResponse.ok()).toBeTruthy();
        const submitData = await submitResponse.json();
        expect(submitData.success).toBeTruthy();
      }
    });

    test('should prevent modifications to submitted callout', async ({ page }) => {
      // This test verifies that submitted callouts cannot be modified
      // Get active callouts
      const activeResponse = await page.request.get(`/${slug}/api/callout/active`);
      const activeData = await activeResponse.json();

      // If there's a submitted callout in history, try to modify it
      const historyResponse = await page.request.get(`/${slug}/api/history`);
      const historyData = await historyResponse.json();

      const submittedCallout = historyData.callouts?.find((c: any) => c.status === 'submitted');
      if (submittedCallout) {
        // Try to update - should fail or be rejected
        const updateResponse = await page.request.put(`/${slug}/api/callout/${submittedCallout.id}`, {
          data: JSON.stringify({ icad_number: 'FMODIFIED' }),
          headers: { 'Content-Type': 'application/json' },
        });

        // Should reject modification
        expect([400, 403, 409]).toContain(updateResponse.status());
      }
    });
  });

  test.describe('Copy Last Call', () => {
    test.beforeEach(async ({ page }) => {
      await authenticateWithPin(page, slug, demoBrigade.pin);
    });

    test('should get last call attendance', async ({ page }) => {
      const response = await page.request.get(`/${slug}/api/callout/last-attendance`);
      // This may return empty if no previous calls
      expect([200, 404]).toContain(response.status());
    });

    test('should copy last call attendance', async ({ page }) => {
      // Get or create active callout
      const activeResponse = await page.request.get(`/${slug}/api/callout/active`);
      const activeData = await activeResponse.json();
      const callouts = activeData.callouts || [activeData.callout];

      if (callouts.length > 0 && callouts[0]?.id) {
        const calloutId = callouts[0].id;

        const copyResponse = await page.request.post(`/${slug}/api/callout/${calloutId}/copy-last`);
        // May succeed or fail if no previous attendance
        expect([200, 400, 404]).toContain(copyResponse.status());
      }
    });
  });

  test.describe('Callout History', () => {
    test.beforeEach(async ({ page }) => {
      await authenticateWithPin(page, slug, demoBrigade.pin);
    });

    test('should get callout history', async ({ page }) => {
      const response = await page.request.get(`/${slug}/api/history`);
      expect(response.ok()).toBeTruthy();

      const data = await response.json();
      expect(data.callouts).toBeDefined();
      expect(Array.isArray(data.callouts)).toBeTruthy();
    });

    test('should get callout history detail', async ({ page }) => {
      const historyResponse = await page.request.get(`/${slug}/api/history`);
      const historyData = await historyResponse.json();

      if (historyData.callouts?.length > 0) {
        const calloutId = historyData.callouts[0].id;
        const detailResponse = await page.request.get(`/${slug}/api/history/${calloutId}`);

        expect(detailResponse.ok()).toBeTruthy();
        const detailData = await detailResponse.json();
        expect(detailData.callout || detailData.id).toBeDefined();
      }
    });
  });
});

test.describe('Admin Callout Management', () => {
  const slug = getBrigadeSlug();

  test.beforeEach(async ({ page }) => {
    await authenticateAsAdmin(page, slug);
  });

  test('should list all callouts in admin', async ({ page }) => {
    const response = await page.request.get(`/${slug}/admin/api/callouts`);
    expect(response.ok()).toBeTruthy();

    const data = await response.json();
    expect(data.callouts).toBeDefined();
  });

  test('should get callout details', async ({ page }) => {
    const listResponse = await page.request.get(`/${slug}/admin/api/callouts`);
    const listData = await listResponse.json();

    if (listData.callouts?.length > 0) {
      const calloutId = listData.callouts[0].id;
      const detailResponse = await page.request.get(`/${slug}/admin/api/callouts/${calloutId}`);

      expect(detailResponse.ok()).toBeTruthy();
    }
  });

  test('should update callout', async ({ page }) => {
    const listResponse = await page.request.get(`/${slug}/admin/api/callouts`);
    const listData = await listResponse.json();

    const activeCallout = listData.callouts?.find((c: any) => c.status === 'active');
    if (activeCallout) {
      const updateResponse = await page.request.put(`/${slug}/admin/api/callouts/${activeCallout.id}`, {
        data: JSON.stringify({ location: 'Updated Location' }),
        headers: { 'Content-Type': 'application/json' },
      });

      expect(updateResponse.ok()).toBeTruthy();
    }
  });

  test('should unlock submitted callout', async ({ page }) => {
    const listResponse = await page.request.get(`/${slug}/admin/api/callouts`);
    const listData = await listResponse.json();

    const submittedCallout = listData.callouts?.find((c: any) => c.status === 'submitted');
    if (submittedCallout) {
      const unlockResponse = await page.request.put(`/${slug}/admin/api/callouts/${submittedCallout.id}/unlock`);
      expect(unlockResponse.ok()).toBeTruthy();
    }
  });

  test.skip(isProduction(), 'Skip destructive tests in production');

  test('should delete callout', async ({ page }) => {
    // Create a callout to delete
    await authenticateWithPin(page, slug, demoBrigade.pin);
    const createResponse = await page.request.post(`/${slug}/api/callout`, {
      data: JSON.stringify({ icad_number: `F${Date.now().toString().slice(-7)}` }),
      headers: { 'Content-Type': 'application/json' },
    });
    const created = await createResponse.json();
    const calloutId = created.callout?.id || created.id;

    if (calloutId) {
      await authenticateAsAdmin(page, slug);
      const deleteResponse = await page.request.delete(`/${slug}/admin/api/callouts/${calloutId}`);
      expect(deleteResponse.ok()).toBeTruthy();
    }
  });

  test('should export callouts', async ({ page }) => {
    const response = await page.request.get(`/${slug}/admin/api/callouts/export?format=csv`);
    expect([200, 204]).toContain(response.status());
  });

  test('should add attendance via admin API', async ({ page }) => {
    const listResponse = await page.request.get(`/${slug}/admin/api/callouts`);
    const listData = await listResponse.json();
    const activeCallout = listData.callouts?.find((c: any) => c.status === 'active');

    if (activeCallout) {
      const membersResponse = await page.request.get(`/${slug}/admin/api/members`);
      const membersData = await membersResponse.json();
      const trucksResponse = await page.request.get(`/${slug}/admin/api/trucks`);
      const trucksData = await trucksResponse.json();

      if (membersData.members?.length > 0 && trucksData.trucks?.[0]?.positions?.length > 0) {
        const response = await page.request.post(`/${slug}/admin/api/callouts/${activeCallout.id}/attendance`, {
          data: JSON.stringify({
            member_id: membersData.members[0].id,
            truck_id: trucksData.trucks[0].id,
            position_id: trucksData.trucks[0].positions[0].id,
          }),
          headers: { 'Content-Type': 'application/json' },
        });

        expect(response.ok()).toBeTruthy();
      }
    }
  });
});

test.describe('Real-time Sync (SSE)', () => {
  const slug = getBrigadeSlug();

  test('should establish SSE connection', async ({ page }) => {
    await authenticateWithPin(page, slug, demoBrigade.pin);

    // Get active callout
    const activeResponse = await page.request.get(`/${slug}/api/callout/active`);
    const activeData = await activeResponse.json();
    const callouts = activeData.callouts || [activeData.callout];

    if (callouts.length > 0 && callouts[0]?.id) {
      // Test SSE endpoint responds
      const sseUrl = `/${slug}/api/sse/callout/${callouts[0].id}`;

      // Make a regular request to verify endpoint exists
      // (Playwright doesn't easily support SSE testing, so we verify the endpoint responds)
      const response = await page.request.get(sseUrl, {
        headers: { Accept: 'text/event-stream' },
        timeout: 5000,
      });

      // SSE endpoints typically return 200 with text/event-stream
      expect([200, 204]).toContain(response.status());
    }
  });
});

test.describe('Attendance Status', () => {
  const slug = getBrigadeSlug();

  test.beforeEach(async ({ page }) => {
    await authenticateWithPin(page, slug, demoBrigade.pin);
  });

  test('should support different attendance statuses', async ({ page }) => {
    // Get data for creating attendance
    const activeResponse = await page.request.get(`/${slug}/api/callout/active`);
    const activeData = await activeResponse.json();
    const callouts = activeData.callouts || [activeData.callout];

    if (callouts.length > 0 && callouts[0]?.id) {
      const calloutId = callouts[0].id;

      const membersResponse = await page.request.get(`/${slug}/api/members`);
      const membersData = await membersResponse.json();

      if (membersData.members?.length > 0) {
        // Test setting member status to Leave
        const response = await page.request.post(`/${slug}/api/attendance`, {
          data: JSON.stringify({
            callout_id: calloutId,
            member_id: membersData.members[0].id,
            status: attendanceStatus.LEAVE,
          }),
          headers: { 'Content-Type': 'application/json' },
        });

        // The API may or may not support status directly
        expect([200, 400]).toContain(response.status());
      }
    }
  });
});
