<?php

namespace App\Services;

use App\Models\Callout;

class FenzFetcher
{
    private const FENZ_URL = 'https://www.fireandemergency.nz/mi_NZ/incidents-and-news/incident-reports/incidents/';
    private const CACHE_DIR = __DIR__ . '/../../data/fenz_cache/';
    private const LOG_FILE = __DIR__ . '/../../data/fenz_fetch.log';
    private const FETCH_COOLDOWN = 3600; // 1 hour between fetches per brigade

    /**
     * Log a message to the FENZ fetch log
     */
    private static function log(string $message): void
    {
        $logDir = dirname(self::LOG_FILE);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $nzTimezone = new \DateTimeZone('Pacific/Auckland');
        $now = new \DateTime('now', $nzTimezone);
        $timestamp = $now->format('Y-m-d H:i:s T');

        $logLine = "[{$timestamp}] {$message}\n";
        file_put_contents(self::LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);

        // Keep log file under 100KB by trimming old entries
        if (file_exists(self::LOG_FILE) && filesize(self::LOG_FILE) > 100000) {
            $lines = file(self::LOG_FILE);
            $lines = array_slice($lines, -500); // Keep last 500 lines
            file_put_contents(self::LOG_FILE, implode('', $lines));
        }
    }

    /**
     * Get recent log entries
     */
    public static function getRecentLogs(int $lines = 100): array
    {
        if (!file_exists(self::LOG_FILE)) {
            return [];
        }

        $allLines = file(self::LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_slice($allLines, -$lines);
    }

    /**
     * Get fetch status for all brigades
     */
    public static function getFetchStatus(): array
    {
        $status = [];

        if (!is_dir(self::CACHE_DIR)) {
            return $status;
        }

        $lockFiles = glob(self::CACHE_DIR . 'brigade_*.lock');
        foreach ($lockFiles as $lockFile) {
            preg_match('/brigade_(\d+)\.lock$/', $lockFile, $matches);
            if ($matches) {
                $brigadeId = (int)$matches[1];
                $lastFetch = (int)file_get_contents($lockFile);
                $status[$brigadeId] = [
                    'last_fetch' => $lastFetch,
                    'last_fetch_formatted' => date('Y-m-d H:i:s', $lastFetch),
                    'next_fetch_available' => $lastFetch + self::FETCH_COOLDOWN,
                    'can_fetch_now' => time() >= $lastFetch + self::FETCH_COOLDOWN,
                ];
            }
        }

        return $status;
    }

    /**
     * Get the current day name in NZ timezone
     */
    public static function getNzDayName(): string
    {
        $nzTimezone = new \DateTimeZone('Pacific/Auckland');
        $now = new \DateTime('now', $nzTimezone);
        return $now->format('l'); // "Monday", "Tuesday", etc.
    }

    /**
     * Get yesterday's day name in NZ timezone
     */
    public static function getNzYesterdayName(): string
    {
        $nzTimezone = new \DateTimeZone('Pacific/Auckland');
        $now = new \DateTime('now', $nzTimezone);
        $yesterday = (clone $now)->modify('-1 day');
        return $yesterday->format('l');
    }

    /**
     * Check if we're in early morning NZ time (before 6am)
     */
    public static function isNzEarlyMorning(): bool
    {
        $nzTimezone = new \DateTimeZone('Pacific/Auckland');
        $now = new \DateTime('now', $nzTimezone);
        return (int)$now->format('H') < 6;
    }

    /**
     * Check if we should attempt a fetch (rate limiting)
     */
    public static function shouldFetch(int $brigadeId): bool
    {
        $lockFile = self::CACHE_DIR . "brigade_{$brigadeId}.lock";

        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0755, true);
        }

        if (file_exists($lockFile)) {
            $lastFetch = (int)file_get_contents($lockFile);
            if (time() - $lastFetch < self::FETCH_COOLDOWN) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mark that we've attempted a fetch
     */
    private static function markFetched(int $brigadeId): void
    {
        $lockFile = self::CACHE_DIR . "brigade_{$brigadeId}.lock";

        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0755, true);
        }

        file_put_contents($lockFile, (string)time());
    }

    /**
     * Fetch incident data from FENZ website
     */
    public static function fetchIncidentData(int $region, string $day): array
    {
        $url = self::FENZ_URL . '?region=' . $region . '&day=' . urlencode($day);

        self::log("Fetching FENZ data: region={$region}, day={$day}");

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Mozilla/5.0 (compatible; BrigadeAttendance/1.0)',
            ],
        ]);

        $html = @file_get_contents($url, false, $context);

        if ($html === false) {
            self::log("ERROR: Failed to fetch {$url}");
            return [];
        }

        $incidents = self::parseIncidents($html);
        self::log("Parsed " . count($incidents) . " incidents from {$day}");

        return $incidents;
    }

    /**
     * Parse incidents from HTML
     * Returns array keyed by ICAD number
     */
    private static function parseIncidents(string $html): array
    {
        $incidents = [];

        // Find all report__table sections
        // Match the structure: report__table containing rows with key/value pairs
        $pattern = '/<div class="report__table"[^>]*>.*?<div class="report__table__body">(.*?)<\/div>\s*<\/div>/s';

        if (!preg_match_all($pattern, $html, $tableMatches)) {
            return [];
        }

        foreach ($tableMatches[1] as $tableBody) {
            $incident = self::parseIncidentTable($tableBody);
            if (!empty($incident['icad_number'])) {
                $incidents[$incident['icad_number']] = $incident;
            }
        }

        return $incidents;
    }

    /**
     * Parse a single incident table
     */
    private static function parseIncidentTable(string $tableBody): array
    {
        $incident = [
            'icad_number' => null,
            'date_time' => null,
            'location' => null,
            'duration' => null,
            'call_type' => null,
            'attending_stations' => null,
        ];

        // Pattern to match rows with key and value cells
        $rowPattern = '/<div class="report__table__row">.*?<div class="report__table__cell report__table__cell--key">([^<]*)<\/div>.*?<div class="report__table__cell report__table__cell--value"><p>([^<]*)<\/p><\/div>.*?<\/div>/s';

        if (preg_match_all($rowPattern, $tableBody, $rowMatches, PREG_SET_ORDER)) {
            foreach ($rowMatches as $row) {
                $key = trim($row[1]);
                $value = trim($row[2]);

                switch ($key) {
                    case 'Incident number':
                        $incident['icad_number'] = $value;
                        break;
                    case 'Date and time':
                        $incident['date_time'] = $value;
                        break;
                    case 'Location':
                        $incident['location'] = $value;
                        break;
                    case 'Duration':
                        $incident['duration'] = $value;
                        break;
                    case 'Call Type':
                        $incident['call_type'] = $value;
                        break;
                    case 'Attending Stations/Brigades':
                        $incident['attending_stations'] = $value;
                        break;
                }
            }
        }

        return $incident;
    }

    /**
     * Update callouts with FENZ data if needed
     * Called opportunistically on page load
     */
    public static function updateIfNeeded(int $brigadeId, int $region = 1): int
    {
        self::log("updateIfNeeded called for brigade {$brigadeId}, region {$region}");

        // Check rate limit
        if (!self::shouldFetch($brigadeId)) {
            self::log("Brigade {$brigadeId}: Rate limited, skipping fetch");
            return 0;
        }

        // Mark that we're attempting a fetch
        self::markFetched($brigadeId);

        // Get unfetched callouts from last 7 days
        $unfetched = Callout::findUnfetchedByBrigade($brigadeId);

        if (empty($unfetched)) {
            self::log("Brigade {$brigadeId}: No unfetched callouts found");
            return 0;
        }

        // Collect ICAD numbers we need to find
        $icadNumbers = [];
        foreach ($unfetched as $callout) {
            $icadNumbers[$callout['icad_number']] = $callout['id'];
        }

        self::log("Brigade {$brigadeId}: Looking for " . count($icadNumbers) . " ICAD numbers: " . implode(', ', array_keys($icadNumbers)));

        // Fetch all 7 days to ensure we catch older callouts
        // FENZ website only shows incidents by day name (Monday, Tuesday, etc.)
        $allDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $incidents = [];

        foreach ($allDays as $day) {
            $dayIncidents = self::fetchIncidentData($region, $day);
            $incidents = array_merge($incidents, $dayIncidents);
        }

        self::log("Brigade {$brigadeId}: Total incidents fetched across all days: " . count($incidents));

        // Match and update callouts
        $updated = 0;
        $notFound = [];
        foreach ($icadNumbers as $icadNumber => $calloutId) {
            if (isset($incidents[$icadNumber])) {
                $incident = $incidents[$icadNumber];
                Callout::updateFenzData(
                    $calloutId,
                    $incident['location'],
                    $incident['duration'],
                    $incident['call_type']
                );
                $updated++;
                self::log("Brigade {$brigadeId}: Updated callout {$calloutId} with ICAD {$icadNumber}");
            } else {
                $notFound[] = $icadNumber;
            }
        }

        if (!empty($notFound)) {
            self::log("Brigade {$brigadeId}: Could not find ICAD numbers: " . implode(', ', $notFound));
        }

        self::log("Brigade {$brigadeId}: Completed - updated {$updated} callouts");

        return $updated;
    }

    /**
     * Fetch and update all brigades (for cron job)
     */
    public static function updateAllBrigades(): array
    {
        $results = [];

        // Get all brigades with unfetched callouts
        $brigades = db()->query(
            "SELECT DISTINCT b.id, b.name, b.region
             FROM brigades b
             INNER JOIN callouts c ON c.brigade_id = b.id
             WHERE c.fenz_fetched_at IS NULL
               AND c.status = 'submitted'
               AND c.created_at >= datetime('now', '-7 days')
               AND c.icad_number LIKE 'F%'"
        );

        foreach ($brigades as $brigade) {
            $region = $brigade['region'] ?? 1;
            $updated = self::updateIfNeeded($brigade['id'], $region);
            $results[] = [
                'brigade_id' => $brigade['id'],
                'brigade_name' => $brigade['name'],
                'updated' => $updated,
            ];
        }

        return $results;
    }
}
