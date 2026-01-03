/**
 * Test Data Fixtures for DLB Testing Suite
 *
 * Contains test data for all entities and scenarios
 */

// Demo brigade credentials (auto-created by the system)
export const demoBrigade = {
  slug: 'demo',
  name: 'Demo Brigade',
  pin: '1234',
  adminUsername: 'admin',
  adminPassword: 'admin123',
};

// Super admin credentials (from config)
export const superAdmin = {
  username: 'superadmin',
  password: 'changeme123',
};

// Test members for CRUD operations
export const testMembers = [
  { name: 'John Smith', rank: 'CFO', first_name: 'John', last_name: 'Smith', email: 'john@test.com' },
  { name: 'Jane Doe', rank: 'DCFO', first_name: 'Jane', last_name: 'Doe', email: 'jane@test.com' },
  { name: 'Bob Wilson', rank: 'SFF', first_name: 'Bob', last_name: 'Wilson', email: 'bob@test.com' },
  { name: 'Alice Brown', rank: 'QFF', first_name: 'Alice', last_name: 'Brown', email: 'alice@test.com' },
  { name: 'Charlie Davis', rank: 'FF', first_name: 'Charlie', last_name: 'Davis', email: 'charlie@test.com' },
];

// Test trucks for configuration
export const testTrucks = [
  { name: 'Pump 1', is_station: false },
  { name: 'Tanker', is_station: false },
  { name: 'Support', is_station: false },
  { name: 'Station', is_station: true },
];

// Position templates
export const positionTemplates = {
  light: ['OIC', 'DR'],
  medium: ['OIC', 'DR', '1', '2'],
  full: ['OIC', 'DR', '1', '2', '3', '4'],
  station: ['Standby'],
};

// Test callout data
export const testCallouts = [
  {
    icad_number: 'F1234567',
    location: '123 Test Street, Test City',
    call_type: 'Structure Fire',
    call_date: new Date().toISOString().split('T')[0],
    call_time: '14:30',
  },
  {
    icad_number: 'F7654321',
    location: '456 Demo Road, Demo Town',
    call_type: 'Medical',
    call_date: new Date().toISOString().split('T')[0],
    call_time: '09:15',
  },
  {
    icad_number: 'muster',
    location: 'Station',
    call_type: 'Training',
    call_date: new Date().toISOString().split('T')[0],
    call_time: '19:00',
  },
];

// Test API token configuration
export const testApiToken = {
  name: 'Test Integration Token',
  permissions: [
    'musters:create',
    'musters:read',
    'musters:update',
    'attendance:create',
    'attendance:read',
    'members:read',
    'members:create',
  ],
};

// CSV import test data
export const csvImportData = `name,rank
Test Member 1,FF
Test Member 2,QFF
Test Member 3,SFF`;

// Invalid test data for validation testing
export const invalidData = {
  emptyName: { name: '', rank: 'FF' },
  invalidIcad: { icad_number: '1234567' }, // Should start with 'F' or be 'muster'
  shortPin: '123', // Should be 4-6 digits
  shortPassword: 'short', // Should be min 8 chars
};

// Attendance status codes
export const attendanceStatus = {
  IN_ATTENDANCE: 'I',
  LEAVE: 'L',
  ABSENT: 'A',
};

// Brigade settings
export const testSettings = {
  include_non_attendees: true,
  require_submitter_name: false,
  member_order: 'rank_name',
  email_recipients: ['test1@example.com', 'test2@example.com'],
};
