#!/usr/bin/env php
<?php
/**
 * Import Callouts from Excel Spreadsheet
 *
 * Usage:
 *   php import-callouts.php <brigade-slug> <excel-file> [--dry-run] [--year=2025]
 *
 * Example:
 *   php import-callouts.php pukekohe 2025.xlsx --dry-run
 *   php import-callouts.php pukekohe 2025.xlsx --year=2025
 *
 * The script will:
 *   1. Read the AllCalls25 sheet from the Excel file
 *   2. Create callouts for each row with a valid Event Number
 *   3. Create attendance records for members marked with "I"
 *   4. Create missing members as inactive
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Check autoloader exists before requiring
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    echo "ERROR: Composer autoload not found at: $autoloadPath\n";
    echo "Please run 'composer install' in the project root directory first.\n";
    exit(1);
}

require_once $autoloadPath;

// Check PhpSpreadsheet is installed
if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
    echo "ERROR: PhpSpreadsheet is not installed.\n";
    echo "Please run 'composer install' to install dependencies.\n";
    exit(1);
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

// Parse command line arguments
$args = parseArguments($argv);

if (empty($args['brigade']) || empty($args['file'])) {
    echo "Usage: php import-callouts.php <brigade-slug> <excel-file> [--dry-run] [--year=YYYY]\n";
    echo "\nOptions:\n";
    echo "  --dry-run    Show what would be imported without making changes\n";
    echo "  --year=YYYY  Specify the year for the sheet name (default: 2025)\n";
    echo "\nExample:\n";
    echo "  php import-callouts.php pukekohe 2025.xlsx --dry-run\n";
    exit(1);
}

$brigadeSlug = $args['brigade'];
$excelFile = $args['file'];
$dryRun = $args['dry-run'] ?? false;
$year = $args['year'] ?? '2025';
$sheetName = "AllCalls{$year[2]}{$year[3]}";  // e.g., AllCalls25 for 2025

// Resolve file path
if (!str_starts_with($excelFile, '/')) {
    $excelFile = __DIR__ . '/' . $excelFile;
}

if (!file_exists($excelFile)) {
    error("Excel file not found: $excelFile");
}

info("Import Callouts Script");
info("======================");
info("Brigade: $brigadeSlug");
info("File: $excelFile");
info("Sheet: $sheetName");
info("Dry run: " . ($dryRun ? 'Yes' : 'No'));
info("");

// Initialize database connection
$db = initDatabase();

// Find brigade
$brigade = $db->queryOne("SELECT * FROM brigades WHERE slug = ?", [$brigadeSlug]);
if (!$brigade) {
    error("Brigade not found: $brigadeSlug");
}

$brigadeId = $brigade['id'];
info("Found brigade: {$brigade['name']} (ID: $brigadeId)");

// Get or create the Station truck and Standby position for imported attendance
$defaultTruck = getOrCreateDefaultTruck($db, $brigadeId, $dryRun);
$defaultPosition = getOrCreateDefaultPosition($db, $defaultTruck['id'], $dryRun);

info("Using truck: {$defaultTruck['name']} (ID: {$defaultTruck['id']})");
info("Using position: {$defaultPosition['name']} (ID: {$defaultPosition['id']})");
info("");

// Load Excel file
info("Loading Excel file...");
$spreadsheet = IOFactory::load($excelFile);

// Get the sheet
try {
    $sheet = $spreadsheet->getSheetByName($sheetName);
    if (!$sheet) {
        error("Sheet '$sheetName' not found in Excel file. Available sheets: " .
              implode(', ', $spreadsheet->getSheetNames()));
    }
} catch (Exception $e) {
    error("Error loading sheet: " . $e->getMessage());
}

$data = $sheet->toArray(null, true, true, true);
info("Loaded " . count($data) . " rows from sheet");

// Find header row and member columns
$headerRow = null;
$memberColumns = [];
$dataStartRow = null;

foreach ($data as $rowIndex => $row) {
    // Look for the header row with "Event Number"
    if (isset($row['I']) && $row['I'] === 'Event Number') {
        $headerRow = $row;
        $dataStartRow = $rowIndex + 1;

        // Map column letters to member names
        foreach ($row as $col => $value) {
            if (!empty($value) && isValidMemberColumn($value)) {
                $memberColumns[$col] = $value;
            }
        }
        break;
    }
}

if (!$headerRow) {
    error("Could not find header row with 'Event Number' column");
}

info("Found " . count($memberColumns) . " member columns");
info("Data starts at row $dataStartRow");
info("");

// Load existing members for matching
$existingMembers = loadExistingMembers($db, $brigadeId);
info("Found " . count($existingMembers) . " existing members in database");

// Process callouts
$stats = [
    'callouts_created' => 0,
    'callouts_skipped' => 0,
    'callouts_updated' => 0,
    'attendance_created' => 0,
    'members_created' => 0,
    'members_matched' => 0,
    'errors' => [],
];

$processedRows = 0;
$skippedRows = 0;

foreach ($data as $rowIndex => $row) {
    if ($rowIndex < $dataStartRow) {
        continue;
    }

    // Check for valid Event Number (should start with 'F' and have digits)
    $eventNumber = trim($row['I'] ?? '');
    if (empty($eventNumber) || !preg_match('/^F\d+$/', $eventNumber)) {
        $skippedRows++;
        continue;
    }

    $processedRows++;

    // Extract callout data
    $calloutData = extractCalloutData($row, $year);

    if (!$calloutData) {
        $stats['errors'][] = "Row $rowIndex: Could not extract callout data";
        continue;
    }

    // Check if callout already exists
    $existingCallout = $db->queryOne(
        "SELECT * FROM callouts WHERE brigade_id = ? AND icad_number = ?",
        [$brigadeId, $eventNumber]
    );

    $calloutId = null;

    if ($existingCallout) {
        $calloutId = $existingCallout['id'];
        $stats['callouts_skipped']++;
        debug("Callout $eventNumber already exists (ID: $calloutId)");
    } else {
        // Create callout
        if (!$dryRun) {
            $calloutId = $db->insert('callouts', [
                'brigade_id' => $brigadeId,
                'icad_number' => $eventNumber,
                'status' => 'submitted',
                'location' => $calloutData['address'],
                'call_type' => $calloutData['event_type'],
                'call_date' => $calloutData['date'],
                'call_time' => $calloutData['time'],
                'submitted_at' => $calloutData['datetime'],
                'submitted_by' => 'Import Script',
                'created_at' => $calloutData['datetime'],
            ]);
        } else {
            $calloutId = -1; // Placeholder for dry run
        }
        $stats['callouts_created']++;
        debug("Created callout $eventNumber" . ($dryRun ? " (dry run)" : " (ID: $calloutId)"));
    }

    // Process attendance
    foreach ($memberColumns as $col => $memberName) {
        $attendanceValue = trim($row[$col] ?? '');

        if ($attendanceValue !== 'I') {
            continue; // Only process members who attended (marked with 'I')
        }

        // Find or create member
        $member = findOrCreateMember($db, $brigadeId, $memberName, $existingMembers, $dryRun, $stats);

        if (!$member) {
            $stats['errors'][] = "Row $rowIndex: Could not find/create member: $memberName";
            continue;
        }

        // Create attendance record (only if callout exists or was created)
        if ($calloutId && $calloutId > 0) {
            // Check if attendance already exists
            $existingAttendance = $db->queryOne(
                "SELECT id FROM attendance WHERE callout_id = ? AND member_id = ?",
                [$calloutId, $member['id']]
            );

            if (!$existingAttendance) {
                if (!$dryRun) {
                    $db->insert('attendance', [
                        'callout_id' => $calloutId,
                        'member_id' => $member['id'],
                        'truck_id' => $defaultTruck['id'],
                        'position_id' => $defaultPosition['id'],
                        'status' => 'I',
                        'source' => 'import',
                        'created_at' => $calloutData['datetime'],
                    ]);
                }
                $stats['attendance_created']++;
            }
        } elseif ($dryRun) {
            $stats['attendance_created']++; // Count for dry run
        }
    }
}

// Print summary
info("");
info("=== Import Summary ===");
info("Rows processed: $processedRows");
info("Rows skipped (no Event Number): $skippedRows");
info("");
info("Callouts created: {$stats['callouts_created']}");
info("Callouts skipped (existing): {$stats['callouts_skipped']}");
info("Attendance records created: {$stats['attendance_created']}");
info("Members created (inactive): {$stats['members_created']}");
info("Members matched: {$stats['members_matched']}");

if (!empty($stats['errors'])) {
    info("");
    warning("Errors encountered: " . count($stats['errors']));
    foreach (array_slice($stats['errors'], 0, 10) as $error) {
        warning("  - $error");
    }
    if (count($stats['errors']) > 10) {
        warning("  ... and " . (count($stats['errors']) - 10) . " more errors");
    }
}

if ($dryRun) {
    info("");
    info("This was a DRY RUN. No changes were made to the database.");
    info("Run without --dry-run to actually import the data.");
}

info("");
info("Done!");

// ============================================================================
// Helper Functions
// ============================================================================

function parseArguments(array $argv): array {
    $args = [
        'brigade' => null,
        'file' => null,
        'dry-run' => false,
        'year' => '2025',
    ];

    $positional = [];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];

        if ($arg === '--dry-run') {
            $args['dry-run'] = true;
        } elseif (str_starts_with($arg, '--year=')) {
            $args['year'] = substr($arg, 7);
        } elseif (!str_starts_with($arg, '-')) {
            $positional[] = $arg;
        }
    }

    if (isset($positional[0])) $args['brigade'] = $positional[0];
    if (isset($positional[1])) $args['file'] = $positional[1];

    return $args;
}

function initDatabase(): \App\Services\Database {
    // Load config
    $configPath = __DIR__ . '/../config/config.php';
    if (!file_exists($configPath)) {
        error("Config file not found: $configPath");
    }

    $config = require $configPath;
    $dbPath = $config['database']['path'];

    if (!file_exists($dbPath)) {
        error("Database file not found: $dbPath");
    }

    return new \App\Services\Database($dbPath);
}

function getOrCreateDefaultTruck($db, int $brigadeId, bool $dryRun): array {
    // Try to find Station truck first
    $truck = $db->queryOne(
        "SELECT * FROM trucks WHERE brigade_id = ? AND is_station = 1 LIMIT 1",
        [$brigadeId]
    );

    if ($truck) {
        return $truck;
    }

    // Try to find any truck named "Station" or "Imported"
    $truck = $db->queryOne(
        "SELECT * FROM trucks WHERE brigade_id = ? AND (name = 'Station' OR name = 'Imported') LIMIT 1",
        [$brigadeId]
    );

    if ($truck) {
        return $truck;
    }

    // Create "Imported" truck
    if (!$dryRun) {
        $truckId = $db->insert('trucks', [
            'brigade_id' => $brigadeId,
            'name' => 'Imported',
            'is_station' => 1,
            'sort_order' => 999,
        ]);
        return ['id' => $truckId, 'name' => 'Imported'];
    }

    return ['id' => -1, 'name' => 'Imported (will be created)'];
}

function getOrCreateDefaultPosition($db, int $truckId, bool $dryRun): array {
    if ($truckId < 0) {
        return ['id' => -1, 'name' => 'Imported (will be created)'];
    }

    // Try to find Standby or Imported position
    $position = $db->queryOne(
        "SELECT * FROM positions WHERE truck_id = ? AND (name = 'Standby' OR name = 'Imported') LIMIT 1",
        [$truckId]
    );

    if ($position) {
        return $position;
    }

    // Try to find any position with allow_multiple = 1
    $position = $db->queryOne(
        "SELECT * FROM positions WHERE truck_id = ? AND allow_multiple = 1 LIMIT 1",
        [$truckId]
    );

    if ($position) {
        return $position;
    }

    // Create "Imported" position
    if (!$dryRun) {
        $positionId = $db->insert('positions', [
            'truck_id' => $truckId,
            'name' => 'Imported',
            'allow_multiple' => 1,
            'sort_order' => 999,
        ]);
        return ['id' => $positionId, 'name' => 'Imported'];
    }

    return ['id' => -1, 'name' => 'Imported (will be created)'];
}

function isValidMemberColumn(string $value): bool {
    // Check if the column header looks like a member name (e.g., "CFO John Smith")
    $ranks = ['CFO', 'DCFO', 'SSO', 'SO', 'SFF', 'QFF', 'FF', 'RFF', 'RCFF'];
    foreach ($ranks as $rank) {
        if (str_starts_with($value, "$rank ")) {
            return true;
        }
    }
    return false;
}

function loadExistingMembers($db, int $brigadeId): array {
    $members = $db->query(
        "SELECT * FROM members WHERE brigade_id = ?",
        [$brigadeId]
    );

    $indexed = [];
    foreach ($members as $member) {
        // Index by display_name (lowercase for case-insensitive matching)
        $key = strtolower(trim($member['display_name']));
        $indexed[$key] = $member;

        // Also index by first_name + last_name combination
        if (!empty($member['first_name']) && !empty($member['last_name'])) {
            $nameKey = strtolower(trim($member['first_name'] . ' ' . $member['last_name']));
            if (!isset($indexed[$nameKey])) {
                $indexed[$nameKey] = $member;
            }
        }
    }

    return $indexed;
}

function findOrCreateMember($db, int $brigadeId, string $memberName, array &$existingMembers, bool $dryRun, array &$stats): ?array {
    $memberName = trim($memberName);
    $searchKey = strtolower($memberName);

    // Try exact match on display_name
    if (isset($existingMembers[$searchKey])) {
        $stats['members_matched']++;
        return $existingMembers[$searchKey];
    }

    // Parse the member name to extract rank and name parts
    $parsed = parseMemberName($memberName);

    // Try matching by first + last name
    if (!empty($parsed['first_name']) && !empty($parsed['last_name'])) {
        $nameKey = strtolower($parsed['first_name'] . ' ' . $parsed['last_name']);
        if (isset($existingMembers[$nameKey])) {
            $stats['members_matched']++;
            return $existingMembers[$nameKey];
        }
    }

    // Try partial matching (last name)
    foreach ($existingMembers as $key => $member) {
        if (!empty($parsed['last_name']) && !empty($member['last_name'])) {
            if (strtolower($member['last_name']) === strtolower($parsed['last_name'])) {
                // Check if first name also matches or is similar
                if (!empty($parsed['first_name']) && !empty($member['first_name'])) {
                    if (strtolower($member['first_name']) === strtolower($parsed['first_name'])) {
                        $stats['members_matched']++;
                        return $member;
                    }
                }
            }
        }
    }

    // Member not found, create as inactive
    info("Creating new member (inactive): $memberName");

    if (!$dryRun) {
        $memberId = $db->insert('members', [
            'brigade_id' => $brigadeId,
            'display_name' => $memberName,
            'rank' => $parsed['rank'],
            'first_name' => $parsed['first_name'],
            'last_name' => $parsed['last_name'],
            'is_active' => 0, // Inactive since they're not current members
        ]);

        $newMember = [
            'id' => $memberId,
            'display_name' => $memberName,
            'rank' => $parsed['rank'],
            'first_name' => $parsed['first_name'],
            'last_name' => $parsed['last_name'],
        ];

        // Add to cache
        $existingMembers[$searchKey] = $newMember;

        $stats['members_created']++;
        return $newMember;
    }

    $stats['members_created']++;
    return ['id' => -1, 'display_name' => $memberName]; // Placeholder for dry run
}

function parseMemberName(string $memberName): array {
    $ranks = ['CFO', 'DCFO', 'SSO', 'SO', 'SFF', 'QFF', 'FF', 'RFF', 'RCFF'];
    $rank = '';
    $name = $memberName;

    foreach ($ranks as $r) {
        if (str_starts_with($memberName, "$r ")) {
            $rank = $r;
            $name = trim(substr($memberName, strlen($r) + 1));
            break;
        }
    }

    // Split name into first and last
    $parts = explode(' ', $name, 2);
    $firstName = $parts[0] ?? '';
    $lastName = $parts[1] ?? '';

    // Handle names with multiple parts (e.g., "Joseph O'Neill-Gregory")
    if (empty($lastName) && strpos($firstName, '-') !== false) {
        // Single hyphenated name
        $lastName = $firstName;
        $firstName = '';
    }

    return [
        'rank' => $rank,
        'first_name' => $firstName,
        'last_name' => $lastName,
    ];
}

function extractCalloutData(array $row, string $year): ?array {
    $eventNumber = trim($row['I'] ?? '');
    if (empty($eventNumber)) {
        return null;
    }

    // Extract date - column E (Date)
    $dateValue = $row['E'] ?? null;
    $date = null;

    if ($dateValue) {
        if ($dateValue instanceof \DateTimeInterface) {
            $date = $dateValue->format('Y-m-d');
        } elseif (is_numeric($dateValue)) {
            // Excel serial date
            try {
                $date = ExcelDate::excelToDateTimeObject($dateValue)->format('Y-m-d');
            } catch (Exception $e) {
                $date = null;
            }
        } elseif (is_string($dateValue)) {
            // Try to parse string date
            $timestamp = strtotime($dateValue);
            if ($timestamp !== false) {
                $date = date('Y-m-d', $timestamp);
            }
        }
    }

    // Extract time - column C (Time)
    $timeValue = $row['C'] ?? null;
    $time = null;

    if ($timeValue) {
        if ($timeValue instanceof \DateTimeInterface) {
            $time = $timeValue->format('H:i:s');
        } elseif (is_numeric($timeValue)) {
            // Excel serial time (fraction of day)
            $seconds = round($timeValue * 86400);
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            $secs = $seconds % 60;
            $time = sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
        } elseif (is_string($timeValue) && preg_match('/^\d{1,2}:\d{2}/', $timeValue)) {
            $time = $timeValue;
            if (substr_count($time, ':') === 1) {
                $time .= ':00';
            }
        }
    }

    // Combine date and time for datetime
    $datetime = null;
    if ($date) {
        $datetime = $date . ' ' . ($time ?? '00:00:00');
    } else {
        // If no date, use current date with the year from the sheet
        $datetime = $year . '-01-01 ' . ($time ?? '00:00:00');
    }

    // Extract other fields
    $eventType = trim($row['M'] ?? ''); // Event Type
    $address = trim($row['T'] ?? '');   // Address
    $incidentInfo = trim($row['O'] ?? ''); // Incident Info

    return [
        'event_number' => $eventNumber,
        'date' => $date,
        'time' => $time,
        'datetime' => $datetime,
        'event_type' => $eventType ?: $incidentInfo,
        'address' => $address,
    ];
}

function info(string $message): void {
    echo "[INFO] $message\n";
}

function warning(string $message): void {
    echo "[WARN] $message\n";
}

function debug(string $message): void {
    // Uncomment for verbose output
    // echo "[DEBUG] $message\n";
}

function error(string $message): void {
    echo "[ERROR] $message\n";
    exit(1);
}
