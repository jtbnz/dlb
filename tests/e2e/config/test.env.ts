/**
 * Test Environment Configuration
 *
 * This file contains environment-specific configurations for running tests
 * in different environments (local development, production demo, etc.)
 */

export interface TestEnvironment {
  name: string;
  baseUrl: string;
  brigadeSlug: string;
  brigadePin: string;
  adminUsername: string;
  adminPassword: string;
  superAdminUsername?: string;
  superAdminPassword?: string;
  skipDestructiveTests: boolean;
  skipSuperAdminTests: boolean;
}

/**
 * Local Development Environment
 * Uses the demo brigade auto-created by the system
 */
export const localEnvironment: TestEnvironment = {
  name: 'local',
  baseUrl: 'http://localhost:8080/dlb',
  brigadeSlug: 'demo',
  brigadePin: '1234',
  adminUsername: 'admin',
  adminPassword: 'admin123',
  superAdminUsername: 'superadmin',
  superAdminPassword: 'changeme123',
  skipDestructiveTests: false,
  skipSuperAdminTests: false,
};

/**
 * Production Environment (Demo Brigade)
 * Tests against the live production server using the demo brigade
 * Destructive tests are skipped to avoid affecting production data
 */
export const productionEnvironment: TestEnvironment = {
  name: 'production',
  baseUrl: 'https://kiaora.tech/dlb',
  brigadeSlug: 'demo',
  brigadePin: process.env.PROD_DEMO_PIN || '1234',
  adminUsername: process.env.PROD_DEMO_ADMIN || 'admin',
  adminPassword: process.env.PROD_DEMO_PASSWORD || 'admin123',
  skipDestructiveTests: true,
  skipSuperAdminTests: true,
};

/**
 * Get current test environment based on BASE_URL
 */
export function getTestEnvironment(): TestEnvironment {
  const baseUrl = process.env.BASE_URL || localEnvironment.baseUrl;

  if (baseUrl.includes('kiaora.tech')) {
    return productionEnvironment;
  }

  return {
    ...localEnvironment,
    baseUrl,
    brigadeSlug: process.env.BRIGADE_SLUG || localEnvironment.brigadeSlug,
  };
}

/**
 * Check if running in production mode
 */
export function isProductionTest(): boolean {
  return getTestEnvironment().name === 'production';
}

/**
 * Get credentials for the current environment
 */
export function getCredentials() {
  const env = getTestEnvironment();
  return {
    brigade: {
      slug: env.brigadeSlug,
      pin: env.brigadePin,
    },
    admin: {
      username: env.adminUsername,
      password: env.adminPassword,
    },
    superAdmin: env.superAdminUsername
      ? {
          username: env.superAdminUsername,
          password: env.superAdminPassword,
        }
      : null,
  };
}
