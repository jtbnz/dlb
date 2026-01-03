# DLB Testing Documentation

This document describes the comprehensive testing suite for the Fire Brigade Callout Attendance System (DLB).

## Table of Contents

- [Overview](#overview)
- [Quick Start](#quick-start)
- [Test Structure](#test-structure)
- [Running Tests](#running-tests)
- [Test Environments](#test-environments)
- [Writing Tests](#writing-tests)
- [Chrome DevTools Integration](#chrome-devtools-integration)

## Overview

The DLB testing suite includes:

- **Unit Tests (PHPUnit)**: Test models, services, and business logic in isolation
- **E2E Tests (Playwright)**: Test complete user workflows in a real browser

### Test Coverage

| Feature | Unit Tests | E2E Tests |
|---------|------------|-----------|
| Authentication (PIN, Admin, Super Admin) | ✓ | ✓ |
| Member CRUD | ✓ | ✓ |
| Truck/Position Management | ✓ | ✓ |
| Callout/Attendance Workflow | ✓ | ✓ |
| API v1 (Token Auth) | ✓ | ✓ |
| Admin Settings | - | ✓ |
| Audit Logging | - | ✓ |
| Brigade Management | ✓ | ✓ |

## Quick Start

### Prerequisites

- Node.js 18+
- PHP 8.0+
- Composer (for unit tests)

### Installation

```bash
# Install Node.js dependencies
npm install

# Install Playwright browsers
npx playwright install chromium

# Install PHP dependencies (for unit tests)
composer install
```

### Run All Tests

```bash
# Run all tests locally
./scripts/run-tests.sh

# Or using npm
npm run test:all
```

## Test Structure

```
tests/
├── e2e/                          # Playwright E2E tests
│   ├── 01-authentication.spec.ts # Auth flows
│   ├── 02-member-crud.spec.ts    # Member management
│   ├── 03-truck-position.spec.ts # Truck/position config
│   ├── 04-callout-attendance.spec.ts # Core workflow
│   ├── 05-api-v1.spec.ts         # API v1 tests
│   ├── 06-admin-settings.spec.ts # Admin/settings
│   └── config/
│       └── test.env.ts           # Environment config
├── unit/                         # PHPUnit tests
│   ├── bootstrap.php             # Test setup
│   └── Models/
│       ├── BrigadeTest.php
│       ├── MemberTest.php
│       ├── CalloutTest.php
│       ├── AttendanceTest.php
│       └── ApiTokenTest.php
├── fixtures/                     # Test data & helpers
│   ├── test-data.ts
│   └── test-helpers.ts
├── global-setup.ts               # Pre-test setup
└── global-teardown.ts            # Post-test cleanup
```

## Running Tests

### Using the Test Runner Script

```bash
# Run all tests locally
./scripts/run-tests.sh

# Run only unit tests
./scripts/run-tests.sh unit

# Run only E2E tests
./scripts/run-tests.sh e2e

# Run against production
./scripts/run-tests.sh e2e production

# Run specific test suites
./scripts/run-tests.sh auth    # Authentication tests
./scripts/run-tests.sh crud    # CRUD operation tests
./scripts/run-tests.sh api     # API v1 tests
```

### Using npm Scripts

```bash
# Run E2E tests with UI
npm run test:ui

# Run E2E tests with visible browser
npm run test:headed

# Run in debug mode
npm run test:debug

# Run against local server
npm run test:local

# Run against production
npm run test:prod

# Run unit tests only
npm run test:unit
```

### Running Specific Tests

```bash
# Run a specific test file
npx playwright test tests/e2e/01-authentication.spec.ts

# Run tests matching a pattern
npx playwright test --grep "PIN"

# Run a specific test by name
npx playwright test --grep "should accept valid PIN"
```

## Test Environments

### Local Development

Tests run against a local PHP development server with subdirectory deployment simulation.

```bash
# Start the local server manually
php -S localhost:8080 router.php

# Run tests against local server
npm run test:local
```

**Configuration:**
- URL: `http://localhost:8080/dlb`
- Brigade: `demo`
- PIN: `1234`
- Admin: `admin` / `admin123`
- Super Admin: `superadmin` / `changeme123`

### Production (Demo Brigade)

Tests run against the live production server using the demo brigade.

```bash
# Run against production
npm run test:prod
# or
./scripts/run-tests.sh e2e production
```

**Important:**
- Destructive tests (delete operations) are **skipped** in production
- Super admin tests are **skipped** in production
- Only non-destructive read/write operations are tested

**Environment Variables:**
```bash
# Override default credentials for production
PROD_DEMO_PIN=1234
PROD_DEMO_ADMIN=admin
PROD_DEMO_PASSWORD=your_password
```

## Writing Tests

### E2E Test Example

```typescript
import { test, expect } from '@playwright/test';
import { authenticateWithPin, getBrigadeSlug } from '../fixtures/test-helpers';

test.describe('My Feature', () => {
  const slug = getBrigadeSlug();

  test.beforeEach(async ({ page }) => {
    await authenticateWithPin(page, slug);
  });

  test('should do something', async ({ page }) => {
    // Navigate
    await page.goto(`/${slug}/attendance`);

    // Interact
    await page.click('button.submit');

    // Assert
    await expect(page.locator('.success')).toBeVisible();
  });
});
```

### Unit Test Example

```php
<?php

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;

class MyModelTest extends TestCase
{
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        // Set up test schema
    }

    public function testSomething(): void
    {
        // Test logic
        $this->assertTrue(true);
    }
}
```

### Test Helpers

```typescript
import {
  authenticateWithPin,      // Authenticate with brigade PIN
  authenticateAsAdmin,      // Authenticate as brigade admin
  authenticateAsSuperAdmin, // Authenticate as super admin
  isProduction,             // Check if running in production
  getBrigadeSlug,           // Get current brigade slug
  waitForPageLoad,          // Wait for page to fully load
  createTestMember,         // Create a test member via API
  createTestTruck,          // Create a test truck via API
} from '../fixtures/test-helpers';
```

### Skip Production-Unsafe Tests

```typescript
test.describe('Destructive Operations', () => {
  test.skip(isProduction(), 'Skip destructive tests in production');

  test('should delete item', async ({ page }) => {
    // This test will only run locally
  });
});
```

## Chrome DevTools Integration

The test suite is configured for Chrome DevTools integration for debugging.

### Using Playwright Inspector

```bash
# Run tests with Playwright Inspector
npx playwright test --debug
```

### Chrome DevTools Protocol

Tests run with remote debugging enabled:

```typescript
// In playwright.config.ts
launchOptions: {
  args: ['--remote-debugging-port=9222'],
}
```

### MCP Chrome DevTools

You can interact with the browser during tests using the Chrome DevTools MCP:

```bash
# Take a snapshot of the current page
mcp__chrome-devtools__take_snapshot

# Click an element
mcp__chrome-devtools__click --uid "element-uid"

# Fill a form field
mcp__chrome-devtools__fill --uid "input-uid" --value "test"

# Take a screenshot
mcp__chrome-devtools__take_screenshot
```

### Debugging Tips

1. **Use headed mode** to see the browser:
   ```bash
   npm run test:headed
   ```

2. **Add pauses** for debugging:
   ```typescript
   await page.pause(); // Opens Playwright Inspector
   ```

3. **Take screenshots on failure** (automatic):
   ```typescript
   // Screenshots are saved to test-results/
   ```

4. **Use trace viewer**:
   ```bash
   npx playwright show-trace trace.zip
   ```

## Subdirectory Deployment

The test suite is designed to work with subdirectory deployment (`/dlb/`).

### How It Works

1. The `router.php` file simulates subdirectory deployment locally
2. Tests use relative URLs that work with any base path
3. The `BASE_URL` environment variable controls the deployment path

### Testing Different Base Paths

```bash
# Test with custom base path
BASE_URL=http://localhost:8080/custom-path npm run test
```

## CI/CD Integration

### GitHub Actions Example

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Setup Node
        uses: actions/setup-node@v3
        with:
          node-version: '18'

      - name: Install dependencies
        run: |
          npm ci
          composer install
          npx playwright install chromium

      - name: Run tests
        run: npm run test:all

      - uses: actions/upload-artifact@v3
        if: failure()
        with:
          name: playwright-report
          path: playwright-report/
```

## Troubleshooting

### Tests fail to start

1. Ensure PHP is installed: `php -v`
2. Ensure Node.js is installed: `node -v`
3. Install dependencies: `npm install`
4. Install Playwright browsers: `npx playwright install`

### Database errors

1. Ensure `data/` directory is writable
2. Delete `data/database.sqlite` for a fresh start
3. Check `config/config.php` exists

### Production tests fail

1. Verify network access to production server
2. Check production credentials
3. Ensure demo brigade exists on production

### Browser not starting

1. Install Chromium: `npx playwright install chromium`
2. Check for conflicting browser processes
3. Try headed mode for debugging: `npm run test:headed`
