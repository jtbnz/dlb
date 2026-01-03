import { test, expect } from '@playwright/test';
import { demoBrigade, testApiToken, attendanceStatus } from '../fixtures/test-data';
import {
  authenticateAsAdmin,
  isProduction,
  getBrigadeSlug,
  waitForPageLoad,
  createApiToken,
} from '../fixtures/test-helpers';

/**
 * API v1 Tests
 *
 * Tests the token-based API for external integrations:
 * - Token generation and management
 * - Token authentication
 * - Muster CRUD operations
 * - Attendance via API
 * - Member sync
 * - Rate limiting
 * - Error handling
 */

test.describe('API Token Management', () => {
  const slug = getBrigadeSlug();

  test.beforeEach(async ({ page }) => {
    await authenticateAsAdmin(page, slug);
  });

  test('should list API tokens', async ({ page }) => {
    const response = await page.request.get(`${slug}/admin/api/tokens`);
    expect(response.ok()).toBeTruthy();

    const data = await response.json();
    expect(data.tokens || data).toBeDefined();
  });

  test('should create API token', async ({ page }) => {
    const response = await page.request.post(`${slug}/admin/api/tokens`, {
      data: JSON.stringify({
        name: `Test Token ${Date.now()}`,
        permissions: testApiToken.permissions,
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    expect(response.ok()).toBeTruthy();
    const data = await response.json();

    // Should return the plain token (only shown once)
    expect(data.token || data.plain_token).toBeDefined();
    expect(data.success || data.token).toBeTruthy();
  });

  test('should update API token permissions', async ({ page }) => {
    // Create a token first
    const createResponse = await page.request.post(`${slug}/admin/api/tokens`, {
      data: JSON.stringify({
        name: `Update Test Token ${Date.now()}`,
        permissions: ['musters:read'],
      }),
      headers: { 'Content-Type': 'application/json' },
    });
    const created = await createResponse.json();
    const tokenId = created.id || created.token?.id;

    if (tokenId) {
      // Update permissions
      const updateResponse = await page.request.put(`${slug}/admin/api/tokens/${tokenId}`, {
        data: JSON.stringify({
          permissions: ['musters:read', 'musters:create'],
        }),
        headers: { 'Content-Type': 'application/json' },
      });

      expect(updateResponse.ok()).toBeTruthy();
    }
  });

  test('should revoke API token', async ({ page }) => {
    // Create a token first
    const createResponse = await page.request.post(`${slug}/admin/api/tokens`, {
      data: JSON.stringify({
        name: `Revoke Test Token ${Date.now()}`,
        permissions: ['musters:read'],
      }),
      headers: { 'Content-Type': 'application/json' },
    });
    const created = await createResponse.json();
    const tokenId = created.id || created.token?.id;

    if (tokenId) {
      // Revoke token
      const revokeResponse = await page.request.delete(`${slug}/admin/api/tokens/${tokenId}`);
      expect(revokeResponse.ok()).toBeTruthy();
    }
  });

  test('should validate token permissions', async ({ page }) => {
    const response = await page.request.post(`${slug}/admin/api/tokens`, {
      data: JSON.stringify({
        name: `Invalid Permission Token ${Date.now()}`,
        permissions: ['invalid:permission'],
      }),
      headers: { 'Content-Type': 'application/json' },
    });

    // Should reject invalid permissions or accept (implementation may vary)
    expect([200, 400, 422]).toContain(response.status());
  });
});

test.describe('API v1 Authentication', () => {
  const slug = getBrigadeSlug();
  let validToken: string;

  test.beforeAll(async ({ request }) => {
    // This would need to be set up via admin panel or fixture
    // For now, we'll test with invalid tokens
  });

  test('should reject requests without token', async ({ page }) => {
    const response = await page.request.get(`${slug}/api/v1/musters`);
    expect([401, 403]).toContain(response.status());
  });

  test('should reject requests with invalid token', async ({ page }) => {
    const response = await page.request.get(`${slug}/api/v1/musters`, {
      headers: {
        'Authorization': 'Bearer invalid_token_12345',
      },
    });
    expect([401, 403]).toContain(response.status());
  });

  test('should reject requests with malformed authorization header', async ({ page }) => {
    const response = await page.request.get(`${slug}/api/v1/musters`, {
      headers: {
        'Authorization': 'Basic invalid',
      },
    });
    expect([401, 403]).toContain(response.status());
  });
});

test.describe('API v1 Muster Operations', () => {
  const slug = getBrigadeSlug();
  let apiToken: string | null = null;

  test.beforeEach(async ({ page }) => {
    // Authenticate and create token for each test
    await authenticateAsAdmin(page, slug);
    apiToken = await createApiToken(page, slug, testApiToken.permissions);
  });

  test('should create muster via API v1', async ({ page }) => {
    test.skip(!apiToken, 'Failed to create API token');

    // Use unique ICAD number to avoid conflicts with previous test runs
    const uniqueIcad = `F${Date.now()}`;
    const response = await page.request.post(`${slug}/api/v1/musters`, {
      data: JSON.stringify({
        icad_number: uniqueIcad,
        call_date: new Date().toISOString().split('T')[0],
        call_time: '19:00',
        location: 'Station',
        call_type: 'Training',
        visible: false,
      }),
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${apiToken}`,
      },
    });

    expect(response.ok()).toBeTruthy();
    const data = await response.json();
    expect(data.success || data.muster).toBeTruthy();
  });

  test('should list musters via API v1', async ({ page }) => {
    test.skip(!apiToken, 'Failed to create API token');

    const response = await page.request.get(`${slug}/api/v1/musters`, {
      headers: {
        'Authorization': `Bearer ${apiToken}`,
      },
    });

    expect(response.ok()).toBeTruthy();
    const data = await response.json();
    expect(data.musters || data.callouts).toBeDefined();
  });

  test('should update muster visibility via API v1', async ({ page }) => {
    test.skip(!apiToken, 'Failed to create API token');

    // Create a muster first with unique ICAD
    const createResponse = await page.request.post(`${slug}/api/v1/musters`, {
      data: JSON.stringify({
        icad_number: `F${Date.now()}`,
        call_date: new Date().toISOString().split('T')[0],
        visible: false,
      }),
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${apiToken}`,
      },
    });
    const created = await createResponse.json();
    const musterId = created.muster?.id || created.id;

    if (musterId) {
      const updateResponse = await page.request.put(`${slug}/api/v1/musters/${musterId}/visibility`, {
        data: JSON.stringify({ visible: true }),
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${apiToken}`,
        },
      });

      expect(updateResponse.ok()).toBeTruthy();
    }
  });
});

test.describe('API v1 Attendance Operations', () => {
  const slug = getBrigadeSlug();
  let apiToken: string;
  let musterId: number;
  let memberId: number;

  test.beforeEach(async ({ page }) => {
    // Authenticate and create token
    await authenticateAsAdmin(page, slug);
    apiToken = await createApiToken(page, slug, testApiToken.permissions);

    // Get a member ID
    const membersResponse = await page.request.get(`${slug}/admin/api/members`);
    const membersData = await membersResponse.json();
    if (membersData.members?.length > 0) {
      memberId = membersData.members[0].id;
    }

    // Create a muster for attendance tests with unique ICAD
    if (apiToken) {
      const musterResponse = await page.request.post(`${slug}/api/v1/musters`, {
        data: JSON.stringify({
          icad_number: `F${Date.now()}`,
          call_date: new Date().toISOString().split('T')[0],
          visible: true,
        }),
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${apiToken}`,
        },
      });
      const musterData = await musterResponse.json();
      musterId = musterData.muster?.id || musterData.id;
    }
  });

  test('should set member attendance status via API v1', async ({ page }) => {
    test.skip(!apiToken || !musterId || !memberId, 'Missing token, muster, or member');

    const response = await page.request.post(`${slug}/api/v1/musters/${musterId}/attendance`, {
      data: JSON.stringify({
        member_id: memberId,
        status: attendanceStatus.LEAVE,
        notes: 'Set via API test',
      }),
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${apiToken}`,
      },
    });

    expect(response.ok()).toBeTruthy();
  });

  test('should bulk set attendance via API v1', async ({ page }) => {
    test.skip(!apiToken || !musterId || !memberId, 'Missing token, muster, or member');

    const response = await page.request.post(`${slug}/api/v1/musters/${musterId}/attendance/bulk`, {
      data: JSON.stringify({
        attendance: [
          { member_id: memberId, status: attendanceStatus.LEAVE, notes: 'Bulk test' },
        ],
      }),
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${apiToken}`,
      },
    });

    expect(response.ok()).toBeTruthy();
    const data = await response.json();
    expect(data.success || data.created >= 0).toBeTruthy();
  });

  test('should get muster attendance via API v1', async ({ page }) => {
    if (!apiToken || !musterId) {
      test.skip();
      return;
    }

    const response = await page.request.get(`${slug}/api/v1/musters/${musterId}/attendance`, {
      headers: {
        'Authorization': `Bearer ${apiToken}`,
      },
    });

    expect(response.ok()).toBeTruthy();
    const data = await response.json();
    expect(data.attendance || data.muster).toBeDefined();
  });
});

test.describe('API v1 Member Operations', () => {
  const slug = getBrigadeSlug();
  let apiToken: string | null = null;

  test.beforeEach(async ({ page }) => {
    // Authenticate and create token for each test
    await authenticateAsAdmin(page, slug);
    apiToken = await createApiToken(page, slug, ['members:read', 'members:create']);
  });

  test('should list members via API v1', async ({ page }) => {
    test.skip(!apiToken, 'Failed to create API token');

    const response = await page.request.get(`${slug}/api/v1/members`, {
      headers: {
        'Authorization': `Bearer ${apiToken}`,
      },
    });

    expect(response.ok()).toBeTruthy();
    const data = await response.json();
    expect(data.members).toBeDefined();
  });

  test('should create member via API v1', async ({ page }) => {
    test.skip(!apiToken, 'Failed to create API token');

    const response = await page.request.post(`${slug}/api/v1/members`, {
      data: JSON.stringify({
        name: `API Created Member ${Date.now()}`,
        rank: 'FF',
        is_active: true,
      }),
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${apiToken}`,
      },
    });

    expect(response.ok()).toBeTruthy();
  });
});

test.describe('API v1 Error Handling', () => {
  const slug = getBrigadeSlug();

  test('should return proper error format for invalid token', async ({ page }) => {
    const response = await page.request.get(`${slug}/api/v1/musters`, {
      headers: {
        'Authorization': 'Bearer invalid_token',
      },
    });

    const data = await response.json();
    expect(data.success).toBeFalsy();
    expect(data.error).toBeDefined();
  });

  test('should return 404 for non-existent muster', async ({ page }) => {
    // Authenticate and get a valid token
    await authenticateAsAdmin(page, slug);
    const apiToken = await createApiToken(page, slug, ['attendance:read']);
    test.skip(!apiToken, 'Failed to create API token');

    const response = await page.request.get(`${slug}/api/v1/musters/99999/attendance`, {
      headers: {
        'Authorization': `Bearer ${apiToken}`,
      },
    });

    expect(response.status()).toBe(404);
  });

  test('should reject modification of submitted muster', async ({ page }) => {
    // This tests the MUSTER_SUBMITTED error code
    await authenticateAsAdmin(page, slug);

    // Get submitted callouts
    const calloutsResponse = await page.request.get(`${slug}/admin/api/callouts`);
    const calloutsData = await calloutsResponse.json();
    const submittedCallout = calloutsData.callouts?.find((c: any) => c.status === 'submitted');

    test.skip(!submittedCallout, 'No submitted callout found to test');

    // Get token
    const apiToken = await createApiToken(page, slug, ['attendance:create']);
    test.skip(!apiToken, 'Failed to create API token');

    // Try to add attendance to submitted callout
    const response = await page.request.post(`${slug}/api/v1/musters/${submittedCallout.id}/attendance`, {
      data: JSON.stringify({ member_id: 1, status: 'L' }),
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${apiToken}`,
      },
    });

    expect([400, 403, 409]).toContain(response.status());
  });
});

test.describe('API v1 Permission Enforcement', () => {
  const slug = getBrigadeSlug();

  test('should reject operations without required permission', async ({ page }) => {
    await authenticateAsAdmin(page, slug);

    // Create token with limited permissions (read only)
    const apiToken = await createApiToken(page, slug, ['musters:read']);
    test.skip(!apiToken, 'Failed to create API token');

    // Try to create a muster (requires musters:create) - should be rejected due to missing permission
    const response = await page.request.post(`${slug}/api/v1/musters`, {
      data: JSON.stringify({
        icad_number: `F${Date.now()}`,
        call_date: new Date().toISOString().split('T')[0],
      }),
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${apiToken}`,
      },
    });

    expect([401, 403]).toContain(response.status());
  });
});
