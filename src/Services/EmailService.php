<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Member;
use App\Models\Brigade;

class EmailService
{
    public function sendAttendanceEmail(array $brigade, array $callout): bool
    {
        // Check if email is properly configured
        $fromAddress = config('email.from_address');
        if (empty($fromAddress) || $fromAddress === 'attendance@example.com') {
            // Email not configured, skip silently
            error_log('Email not configured - skipping email send');
            return false;
        }

        $recipients = Brigade::getEmailRecipients($brigade);

        if (empty($recipients)) {
            return false;
        }

        $attendance = Attendance::findByCalloutGrouped($callout['id']);
        $allMembers = Member::findByBrigade($brigade['id']);
        $assignedIds = Attendance::getAssignedMemberIds($callout['id']);

        $notAttending = array_filter($allMembers, fn($m) => !in_array($m['id'], $assignedIds));

        $subject = "{$callout['icad_number']} - Callout Attendance - {$brigade['name']}";

        $body = $this->buildEmailBody($brigade, $callout, $attendance, $notAttending);

        $headers = [
            'From' => config('email.from_name') . ' <' . config('email.from_address') . '>',
            'Reply-To' => config('email.from_address'),
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ];

        $headerString = implode("\r\n", array_map(
            fn($k, $v) => "$k: $v",
            array_keys($headers),
            array_values($headers)
        ));

        $success = true;
        foreach ($recipients as $recipient) {
            if (!mail($recipient, $subject, $body, $headerString)) {
                $success = false;
            }
        }

        return $success;
    }

    private function buildEmailBody(array $brigade, array $callout, array $attendance, array $notAttending): string
    {
        $submittedAt = $callout['submitted_at'] ?? $callout['created_at'] ?? date('Y-m-d H:i:s');
        $submittedBy = $callout['submitted_by'] ?? 'Unknown';

        // Get leave members
        $leaveMembers = Attendance::findLeaveByCallout($callout['id']);

        $lines = [];
        $lines[] = "ICAD: {$callout['icad_number']}";
        $lines[] = "Date: " . date('d/m/Y H:i', strtotime($submittedAt));
        $lines[] = "Brigade: {$brigade['name']}";
        $lines[] = "";
        $lines[] = "ATTENDANCE";
        $lines[] = str_repeat("-", 40);

        foreach ($attendance as $truck) {
            $lines[] = "";
            $truckLabel = $truck['is_station'] ? "{$truck['truck_name']} (Standby)" : $truck['truck_name'];
            $lines[] = $truckLabel;

            foreach ($truck['positions'] as $position) {
                if ($position['allow_multiple']) {
                    // Standby - list all members
                    foreach ($position['members'] as $member) {
                        $lines[] = "  - {$member['member_name']} ({$member['member_rank']})";
                    }
                } else {
                    // Regular position
                    $member = $position['members'][0] ?? null;
                    if ($member) {
                        $lines[] = "  {$position['position_name']}: {$member['member_name']} ({$member['member_rank']})";
                    }
                }
            }
        }

        // Add leave members section
        if (!empty($leaveMembers)) {
            $lines[] = "";
            $lines[] = "ON LEAVE";
            $lines[] = str_repeat("-", 40);
            foreach ($leaveMembers as $member) {
                $name = $member['member_name'] ?? 'Unknown';
                $rank = $member['member_rank'] ?? '';
                $notes = !empty($member['notes']) ? " - {$member['notes']}" : '';
                $source = ($member['source'] ?? 'manual') === 'portal' ? ' (Portal)' : '';
                $lines[] = "  - {$name}" . ($rank ? " ({$rank})" : '') . $notes . $source;
            }
        }

        if (!empty($brigade['include_non_attendees']) && !empty($notAttending)) {
            // Filter out leave members from not attending list
            $leaveMemberIds = array_column($leaveMembers, 'member_id');
            $notAttending = array_filter($notAttending, fn($m) => !in_array($m['id'], $leaveMemberIds));

            if (!empty($notAttending)) {
                $lines[] = "";
                $lines[] = "NOT IN ATTENDANCE";
                $lines[] = str_repeat("-", 40);
                foreach ($notAttending as $member) {
                    $lines[] = "  - {$member['name']} ({$member['rank']})";
                }
            }
        }

        $lines[] = "";
        $lines[] = "ICAD Report: https://sitrep.fireandemergency.nz/report/{$callout['icad_number']}";
        $lines[] = "";
        $lines[] = "---";
        $lines[] = "Submitted by: {$submittedBy}";
        $lines[] = "Submitted at: " . date('d/m/Y H:i:s', strtotime($submittedAt));

        return implode("\n", $lines);
    }
}
