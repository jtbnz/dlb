import { FullConfig } from '@playwright/test';
import { execSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';

/**
 * Global setup for Playwright tests
 *
 * This runs once before all tests and handles:
 * - Database backup and reset for local testing
 * - Configuration validation
 * - Test environment preparation
 */
async function globalSetup(config: FullConfig): Promise<void> {
  const isProduction = process.env.BASE_URL?.includes('kiaora.tech');

  console.log('\n🔧 Running global test setup...');
  console.log(`   Environment: ${isProduction ? 'PRODUCTION' : 'LOCAL'}`);
  console.log(`   Base URL: ${process.env.BASE_URL || 'http://localhost:8080/dlb'}`);

  if (isProduction) {
    console.log('   ⚠️  Running against production - using demo brigade only');
    console.log('   ⚠️  Destructive tests will be skipped');
    return;
  }

  // Local testing setup
  const dataDir = path.join(process.cwd(), 'data');
  const dbPath = path.join(dataDir, 'database.sqlite');
  const backupPath = path.join(dataDir, 'database.sqlite.backup');
  const testDbPath = path.join(dataDir, 'database.sqlite.test');

  // Ensure data directory exists
  if (!fs.existsSync(dataDir)) {
    fs.mkdirSync(dataDir, { recursive: true });
  }

  // Backup existing database if it exists
  if (fs.existsSync(dbPath)) {
    console.log('   📦 Backing up existing database...');
    fs.copyFileSync(dbPath, backupPath);
    console.log('   📋 Using existing database (preserves demo brigade)');
  } else {
    // Check if we have a test database template
    if (fs.existsSync(testDbPath)) {
      console.log('   📋 Using test database template...');
      fs.copyFileSync(testDbPath, dbPath);
    } else {
      console.log('   ✨ Fresh database will be created on first request');
    }
  }

  // Ensure test config exists
  const configPath = path.join(process.cwd(), 'config', 'config.php');
  const configSamplePath = path.join(process.cwd(), 'config', 'config.sample.php');

  if (!fs.existsSync(configPath) && fs.existsSync(configSamplePath)) {
    console.log('   📝 Creating config from sample...');
    fs.copyFileSync(configSamplePath, configPath);
  }

  console.log('   ✅ Global setup complete\n');
}

export default globalSetup;
