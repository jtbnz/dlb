<?php

namespace App\Models;

class Member
{
    // Rank order from highest to lowest
    public const RANK_ORDER = [
        'CFO' => 1,
        'DCFO' => 2,
        'SSO' => 3,
        'SO' => 4,
        'SFF' => 5,
        'QFF' => 6,
        'FF' => 7,
        'RCFF' => 8,
        'OS' => 9,
    ];

    public static function findById(int $id): ?array
    {
        return db()->queryOne("SELECT * FROM members WHERE id = ?", [$id]);
    }

    public static function findByBrigade(int $brigadeId, bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM members WHERE brigade_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY last_name, first_name";

        $members = db()->query($sql, [$brigadeId]);

        // Sort by rank (highest first), then by last_name
        return self::sortMembers($members, 'rank_surname');
    }

    /**
     * Get members ordered by brigade preference
     * @param int $brigadeId
     * @param string $orderBy One of: rank_name, rank_joindate, alphabetical
     * @param bool $activeOnly
     * @return array
     */
    public static function findByBrigadeOrdered(int $brigadeId, string $orderBy = 'rank_name', bool $activeOnly = true): array
    {
        $members = self::findByBrigade($brigadeId, $activeOnly);

        return self::sortMembers($members, $orderBy);
    }

    /**
     * Sort members array by specified order
     */
    public static function sortMembers(array $members, string $orderBy): array
    {
        usort($members, function ($a, $b) use ($orderBy) {
            switch ($orderBy) {
                case 'rank_joindate':
                    // First by rank, then by join date (earliest first)
                    $rankA = self::getRankOrder($a['rank'] ?? '');
                    $rankB = self::getRankOrder($b['rank'] ?? '');
                    if ($rankA !== $rankB) {
                        return $rankA - $rankB;
                    }
                    // Sort by join date (null dates go last)
                    $dateA = $a['join_date'] ?? null;
                    $dateB = $b['join_date'] ?? null;
                    if ($dateA === null && $dateB === null) {
                        return strcasecmp($a['last_name'] ?? '', $b['last_name'] ?? '');
                    }
                    if ($dateA === null) return 1;
                    if ($dateB === null) return -1;
                    return strcmp($dateA, $dateB);

                case 'alphabetical':
                    return strcasecmp($a['display_name'] ?? '', $b['display_name'] ?? '');

                case 'rank_surname':
                default:
                    // First by rank (highest first), then by last_name (ascending)
                    $rankA = self::getRankOrder($a['rank'] ?? '');
                    $rankB = self::getRankOrder($b['rank'] ?? '');
                    if ($rankA !== $rankB) {
                        return $rankA - $rankB;
                    }
                    // Then by last name
                    $lastNameCmp = strcasecmp($a['last_name'] ?? '', $b['last_name'] ?? '');
                    if ($lastNameCmp !== 0) {
                        return $lastNameCmp;
                    }
                    // Then by first name
                    return strcasecmp($a['first_name'] ?? '', $b['first_name'] ?? '');

                case 'rank_name':
                    // First by rank, then by display_name
                    $rankA = self::getRankOrder($a['rank'] ?? '');
                    $rankB = self::getRankOrder($b['rank'] ?? '');
                    if ($rankA !== $rankB) {
                        return $rankA - $rankB;
                    }
                    return strcasecmp($a['display_name'] ?? '', $b['display_name'] ?? '');
            }
        });

        return $members;
    }

    /**
     * Get the sort order for a rank (lower = higher priority)
     */
    public static function getRankOrder(string $rank): int
    {
        // Normalize the rank to check against known ranks
        $normalizedRank = strtoupper(trim($rank));

        // Check direct match first
        if (isset(self::RANK_ORDER[$normalizedRank])) {
            return self::RANK_ORDER[$normalizedRank];
        }

        // Check if the rank contains a known rank abbreviation
        foreach (self::RANK_ORDER as $abbrev => $order) {
            if (stripos($rank, $abbrev) !== false) {
                return $order;
            }
        }

        // Check common full names
        $fullNames = [
            'Chief Fire Officer' => 1,
            'Deputy Chief Fire Officer' => 2,
            'Senior Station Officer' => 3,
            'Station Officer' => 4,
            'Senior Firefighter' => 5,
            'Qualified Firefighter' => 6,
            'Firefighter' => 7,
            'Probationary Firefighter' => 7,
            'Recruit Firefighter' => 8,
            'RCFF' => 8,
            'Operational Support' => 9,
            'OS' => 9,
        ];

        foreach ($fullNames as $name => $order) {
            if (stripos($rank, $name) !== false) {
                return $order;
            }
        }

        // Unknown ranks go last
        return 99;
    }

    public static function create(array $data): int
    {
        return db()->insert('members', $data);
    }

    public static function update(int $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return db()->update('members', $data, 'id = ?', [$id]);
    }

    public static function deactivate(int $id): int
    {
        return self::update($id, ['is_active' => 0]);
    }

    public static function activate(int $id): int
    {
        return self::update($id, ['is_active' => 1]);
    }

    /**
     * Import members from CSV
     * Format: Display Name, Rank, First Name, Last Name, Email
     */
    public static function importCsv(int $brigadeId, string $csvContent, bool $updateExisting = false): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $csvContent)));
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($lines as $lineNum => $line) {
            // Skip header row if present
            if ($lineNum === 0 && (stripos($line, 'display') !== false || stripos($line, 'name') !== false || stripos($line, 'rank') !== false)) {
                continue;
            }

            $parts = str_getcsv($line);
            if (count($parts) < 1 || empty(trim($parts[0]))) {
                $skipped++;
                continue;
            }

            // CSV format: Display Name, Rank, First Name, Last Name, Email
            $displayName = trim($parts[0]);
            $rank = isset($parts[1]) ? trim($parts[1]) : '';
            $firstName = isset($parts[2]) ? trim($parts[2]) : '';
            $lastName = isset($parts[3]) ? trim($parts[3]) : '';
            $email = isset($parts[4]) ? trim($parts[4]) : '';

            // Check if member exists by display_name
            $existing = db()->queryOne(
                "SELECT id FROM members WHERE brigade_id = ? AND display_name = ?",
                [$brigadeId, $displayName]
            );

            if ($existing) {
                if ($updateExisting) {
                    $updateData = [
                        'rank' => $rank,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email,
                        'is_active' => 1,
                    ];
                    self::update($existing['id'], $updateData);
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                $memberData = [
                    'brigade_id' => $brigadeId,
                    'display_name' => $displayName,
                    'rank' => $rank,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'is_active' => 1,
                ];
                self::create($memberData);
                $imported++;
            }
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Parse various date formats into Y-m-d
     */
    private static function parseDate(string $dateStr): ?string
    {
        if (empty($dateStr)) {
            return null;
        }

        // Try common formats
        $formats = [
            'Y-m-d',      // 2020-01-15
            'd/m/Y',      // 15/01/2020
            'd-m-Y',      // 15-01-2020
            'm/d/Y',      // 01/15/2020
            'd M Y',      // 15 Jan 2020
            'd F Y',      // 15 January 2020
            'j/n/Y',      // 5/1/2020
            'j-n-Y',      // 5-1-2020
        ];

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $dateStr);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        // Try strtotime as fallback
        $timestamp = strtotime($dateStr);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }
}
