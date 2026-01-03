import { FullConfig } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

/**
 * Global teardown for Playwright tests
 *
 * This runs once after all tests and handles:
 * - Database restoration for local testing
 * - Cleanup of test artifacts
 */
async function globalTeardown(config: FullConfig): Promise<void> {
  const isProduction = process.env.BASE_URL?.includes('kiaora.tech');

  console.log('\n🧹 Running global test teardown...');

  if (isProduction) {
    console.log('   Production mode - no cleanup needed');
    return;
  }

  const dataDir = path.join(process.cwd(), 'data');
  const dbPath = path.join(dataDir, 'database.sqlite');
  const backupPath = path.join(dataDir, 'database.sqlite.backup');

  // Optionally restore the original database
  if (process.env.RESTORE_DB === 'true' && fs.existsSync(backupPath)) {
    console.log('   📦 Restoring original database...');
    fs.copyFileSync(backupPath, dbPath);
    fs.unlinkSync(backupPath);
  } else if (fs.existsSync(backupPath)) {
    console.log('   📋 Backup available at: database.sqlite.backup');
    console.log('   💡 Set RESTORE_DB=true to restore after tests');
  }

  console.log('   ✅ Global teardown complete\n');
}

export default globalTeardown;
