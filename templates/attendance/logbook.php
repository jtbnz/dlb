<?php
ob_start();
?>
<div class="logbook-container">
    <div class="logbook-header">
        <div class="logbook-title">
            <h1><?= sanitize($brigade['name']) ?> - Logbook</h1>
        </div>
        <div class="logbook-controls">
            <select id="date-range" onchange="updateDateRange()">
                <option value="month" <?= $range === 'month' ? 'selected' : '' ?>>Current Month</option>
                <option value="3months" <?= $range === '3months' ? 'selected' : '' ?>>Last 3 Months</option>
                <option value="6months" <?= $range === '6months' ? 'selected' : '' ?>>Last 6 Months</option>
                <option value="year" <?= $range === 'year' ? 'selected' : '' ?>>Current Year</option>
                <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom Range</option>
            </select>
            <div id="custom-dates" class="custom-dates" style="<?= $range === 'custom' ? '' : 'display:none;' ?>">
                <input type="date" id="from-date" value="<?= $fromDate ?>">
                <span>to</span>
                <input type="date" id="to-date" value="<?= $toDate ?>">
                <button class="btn btn-small" onclick="applyCustomRange()">Apply</button>
            </div>
            <a href="<?= base_path() ?>/<?= $slug ?>/logbook/pdf?range=<?= $range ?>&from=<?= $fromDate ?>&to=<?= $toDate ?>" target="_blank" class="btn btn-primary">Print / PDF</a>
        </div>
    </div>

    <div class="logbook-date-range">
        Showing: <?= date('d/m/Y', strtotime($fromDate)) ?> - <?= date('d/m/Y', strtotime($toDate)) ?>
        (<?= count($callouts) ?> call<?= count($callouts) !== 1 ? 's' : '' ?>)
    </div>

    <?php if (empty($callouts)): ?>
        <div class="no-data">
            <p>No callouts found for this date range.</p>
        </div>
    <?php else: ?>
        <div class="logbook-entries">
            <?php
            $callNumber = 0;
            foreach ($callouts as $callout):
                $callNumber++;
                $callDate = new DateTime($callout['created_at']);
            ?>
            <div class="logbook-entry">
                <div class="entry-left">
                    <div class="entry-date"><?= $callDate->format('d/m/y') ?></div>
                    <div class="entry-time"><?= $callDate->format('H:i') ?></div>
                    <div class="entry-callnum">Call# <?= $callNumber ?></div>
                </div>
                <div class="entry-main">
                    <div class="entry-header">
                        <div class="entry-details">
                            <span class="call-type"><?= sanitize($callout['call_type'] ?: 'Unknown') ?></span>
                            <span class="call-location"><?= sanitize($callout['location'] ?: 'Location not specified') ?></span>
                        </div>
                        <div class="entry-icad"><?= sanitize($callout['icad_number']) ?></div>
                    </div>
                    <div class="entry-trucks">
                        <?php if (!empty($callout['trucks'])): ?>
                            <?php foreach ($callout['trucks'] as $truck): ?>
                                <?php if (!$truck['is_station']): ?>
                                <div class="truck-section">
                                    <?php if ($truck['name']): ?>
                                    <div class="truck-name"><?= sanitize($truck['name']) ?></div>
                                    <?php endif; ?>
                                    <div class="truck-personnel">
                                        <?php foreach ($truck['personnel'] as $person): ?>
                                        <div class="personnel-row">
                                            <span class="personnel-role"><?= sanitize($person['role']) ?></span>
                                            <span class="personnel-name"><?= sanitize($person['name']) ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-personnel">No personnel recorded</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
const BASE = window.BASE_PATH || '';
const SLUG = '<?= $slug ?>';

function updateDateRange() {
    const range = document.getElementById('date-range').value;
    const customDates = document.getElementById('custom-dates');

    if (range === 'custom') {
        customDates.style.display = 'flex';
    } else {
        customDates.style.display = 'none';
        window.location.href = `${BASE}/${SLUG}/logbook?range=${range}`;
    }
}

function applyCustomRange() {
    const from = document.getElementById('from-date').value;
    const to = document.getElementById('to-date').value;
    window.location.href = `${BASE}/${SLUG}/logbook?range=custom&from=${from}&to=${to}`;
}
</script>
<?php
$content = ob_get_clean();

$extraHead = <<<HTML
<style>
.logbook-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 1rem;
}

.logbook-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1rem;
}

.logbook-title h1 {
    margin: 0;
    font-size: 1.5rem;
}

.logbook-controls {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.custom-dates {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.logbook-date-range {
    color: var(--gray-600);
    margin-bottom: 1.5rem;
    font-size: 0.875rem;
}

.logbook-entries {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.logbook-entry {
    display: flex;
    border: 1px solid var(--gray-300);
    border-bottom: none;
    background: white;
}

.logbook-entry:last-child {
    border-bottom: 1px solid var(--gray-300);
}

.entry-left {
    width: 100px;
    min-width: 100px;
    padding: 0.75rem;
    background: var(--gray-50);
    border-right: 1px solid var(--gray-300);
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
}

.entry-date {
    font-weight: 600;
    font-size: 0.9rem;
}

.entry-time {
    font-size: 0.85rem;
    color: var(--gray-600);
}

.entry-callnum {
    font-size: 0.75rem;
    color: var(--gray-500);
    margin-top: 0.25rem;
}

.entry-main {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.entry-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 0.5rem 0.75rem;
    background: var(--gray-100);
    border-bottom: 1px solid var(--gray-200);
    gap: 1rem;
}

.entry-details {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    flex: 1;
    min-width: 0;
}

.call-type {
    font-weight: 600;
    font-size: 0.9rem;
}

.call-location {
    font-size: 0.85rem;
    color: var(--gray-600);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.entry-icad {
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--primary);
    white-space: nowrap;
}

.entry-trucks {
    display: flex;
    flex-wrap: wrap;
    padding: 0.5rem;
    gap: 1rem;
}

.truck-section {
    min-width: 180px;
    flex: 1;
    max-width: 250px;
}

.truck-name {
    font-weight: 600;
    font-size: 0.85rem;
    margin-bottom: 0.25rem;
    color: var(--gray-700);
    border-bottom: 1px solid var(--gray-200);
    padding-bottom: 0.25rem;
}

.truck-personnel {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
}

.personnel-row {
    display: flex;
    gap: 0.5rem;
    font-size: 0.8rem;
    font-family: monospace;
}

.personnel-role {
    width: 30px;
    font-weight: 600;
    color: var(--gray-500);
}

.personnel-name {
    color: var(--gray-800);
}

.no-personnel {
    color: var(--gray-400);
    font-style: italic;
    font-size: 0.85rem;
}

.no-data {
    text-align: center;
    padding: 3rem;
    color: var(--gray-500);
}

@media (max-width: 640px) {
    .logbook-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .entry-left {
        width: 80px;
        min-width: 80px;
    }

    .entry-trucks {
        flex-direction: column;
    }

    .truck-section {
        max-width: 100%;
    }
}
</style>
HTML;

echo view('layouts/app', [
    'title' => $brigade['name'] . ' - Logbook',
    'content' => $content,
    'bodyClass' => 'logbook-page',
    'extraHead' => $extraHead,
]);
