import { defineConfig, devices } from '@playwright/test';

/**
 * DLB Fire Brigade Attendance System - Playwright Configuration
 *
 * Supports:
 * - Local development testing (subdirectory deployment)
 * - Production server testing against demo brigade
 * - Chrome DevTools integration
 */

// Environment configuration
const BASE_URL = process.env.BASE_URL || 'http://localhost:8080/dlb/';
const BRIGADE_SLUG = process.env.BRIGADE_SLUG || 'demo-brigade';
const IS_PRODUCTION = BASE_URL.includes('kiaora.tech');

export default defineConfig({
  testDir: './tests/e2e',

  // Run tests in files in parallel
  fullyParallel: false, // Sequential for database consistency

  // Fail the build on CI if you accidentally left test.only in the source code
  forbidOnly: !!process.env.CI,

  // Retry on CI only
  retries: process.env.CI ? 2 : 0,

  // Limit parallel workers
  workers: 1, // Single worker for database consistency

  // Reporter configuration
  reporter: [
    ['html', { open: 'never' }],
    ['list'],
  ],

  // Shared settings for all projects
  use: {
    // Base URL for navigation
    baseURL: BASE_URL,

    // Collect trace on first retry
    trace: 'on-first-retry',

    // Screenshot on failure
    screenshot: 'only-on-failure',

    // Video on failure
    video: 'retain-on-failure',

    // Default timeout for actions
    actionTimeout: 10000,

    // Navigation timeout
    navigationTimeout: 30000,
  },

  // Timeout for each test
  timeout: 60000,

  // Configure projects for different environments
  projects: [
    // Local development testing
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // Enable Chrome DevTools protocol
        launchOptions: {
          args: ['--remote-debugging-port=9222'],
        },
      },
    },

    // Mobile testing
    {
      name: 'mobile-chrome',
      use: {
        ...devices['Pixel 5'],
      },
    },

    // Production testing (against demo brigade)
    {
      name: 'production',
      use: {
        ...devices['Desktop Chrome'],
        baseURL: 'https://kiaora.tech/dlb',
      },
    },
  ],

  // Global setup - can be used to reset database before tests
  globalSetup: require.resolve('./tests/global-setup.ts'),

  // Global teardown
  globalTeardown: require.resolve('./tests/global-teardown.ts'),

  // Web server configuration for local testing
  webServer: IS_PRODUCTION ? undefined : {
    command: 'php -S localhost:8080 router.php',
    url: BASE_URL,
    reuseExistingServer: !process.env.CI,
    timeout: 120000,
    cwd: process.cwd(),
  },
});
