<?php

namespace App\Services;

use App\Models\Callout;

class FenzFetcher
{
    private const FENZ_URL = 'https://www.fireandemergency.nz/mi_NZ/incidents-and-news/incident-reports/incidents/';
    private const CACHE_DIR = __DIR__ . '/../../data/fenz_cache/';
    private const FETCH_COOLDOWN = 3600; // 1 hour between fetches per brigade

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

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Mozilla/5.0 (compatible; BrigadeAttendance/1.0)',
            ],
        ]);

        $html = @file_get_contents($url, false, $context);

        if ($html === false) {
            error_log("FenzFetcher: Failed to fetch {$url}");
            return [];
        }

        return self::parseIncidents($html);
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
        // Check rate limit
        if (!self::shouldFetch($brigadeId)) {
            return 0;
        }

        // Mark that we're attempting a fetch
        self::markFetched($brigadeId);

        // Get unfetched callouts from last 7 days
        $unfetched = Callout::findUnfetchedByBrigade($brigadeId);

        if (empty($unfetched)) {
            return 0;
        }

        // Collect ICAD numbers we need to find
        $icadNumbers = [];
        foreach ($unfetched as $callout) {
            $icadNumbers[$callout['icad_number']] = $callout['id'];
        }

        // Fetch today's incidents
        $day = self::getNzDayName();
        $incidents = self::fetchIncidentData($region, $day);

        // Also fetch yesterday if early morning
        if (self::isNzEarlyMorning()) {
            $yesterdayIncidents = self::fetchIncidentData($region, self::getNzYesterdayName());
            $incidents = array_merge($yesterdayIncidents, $incidents);
        }

        // Match and update callouts
        $updated = 0;
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
            }
        }

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
