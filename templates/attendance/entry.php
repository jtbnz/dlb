<?php
$extraHead = '<script>window.BRIGADE_SLUG = "' . sanitize($slug) . '";</script>';

$content = <<<HTML
<div class="attendance-container">
    <header class="attendance-header">
        <div class="header-left">
            <h1>{$brigade['name']}</h1>
            <div class="icad-display">
                <span id="icad-label">ICAD:</span>
                <span id="icad-number">-</span>
                <button id="change-icad-btn" class="btn-small" style="display:none;">Change</button>
                <button id="copy-last-btn" class="btn-small" style="display:none;">Copy Last Call</button>
            </div>
        </div>
        <div class="header-right">
            <div id="sync-status" class="sync-status">
                <span class="status-dot"></span>
                <span class="status-text">Connecting...</span>
            </div>
            <span id="submitted-time" class="submitted-time" style="display:none;"></span>
            <button id="submit-btn" class="btn btn-success" disabled>Submit</button>
            <button id="close-btn" class="btn" style="display:none;">Close</button>
        </div>
    </header>

    <div id="no-callout" class="no-callout" style="display:none;">
        <h2>Start New Callout</h2>
        <form id="new-callout-form">
            <div class="form-row">
                <input type="text" id="new-icad" placeholder="ICAD Number (e.g., F4363832)" required>
                <input type="datetime-local" id="new-datetime" required>
            </div>
            <div class="form-row">
                <input type="text" id="new-location" placeholder="Address / Location">
                <input type="text" id="new-call-type" placeholder="Call Type (e.g., Structure Fire)">
            </div>
            <button type="submit" class="btn btn-primary">Start Callout</button>
        </form>
        <p id="callouts-this-year" class="callouts-count"></p>
    </div>

    <div id="history-panel" class="history-panel" style="display:none;">
        <h2>History</h2>
        <a href="HISTORY_URL_PLACEHOLDER" id="history-link" class="btn btn-secondary">Browse Recent Callouts</a>
    </div>

    <div id="attendance-area" class="attendance-area" style="display:none;">
        <div class="split-panel">
            <div class="panel members-panel">
                <div class="panel-header">
                    <h3>Available Members</h3>
                    <span id="member-count" class="count-badge">0</span>
                </div>
                <div id="available-members" class="available-members">
                    <!-- Available members will be rendered here -->
                </div>
            </div>
            <div class="panel trucks-panel">
                <div class="panel-header">
                    <h3>Trucks & Positions</h3>
                </div>
                <div id="trucks-container" class="trucks-container">
                    <!-- Trucks will be rendered here -->
                </div>
            </div>
        </div>
    </div>

    <div id="loading" class="loading">
        <div class="spinner"></div>
        <p>Loading...</p>
    </div>
</div>

<!-- ICAD Change Modal -->
<div id="icad-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <h2>Change ICAD Number</h2>
        <form id="change-icad-form">
            <input type="text" id="modal-icad" placeholder="Enter new ICAD Number" required>
            <div class="modal-buttons">
                <button type="button" class="btn" onclick="closeIcadModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Submit Confirmation Modal -->
<div id="submit-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <h2>Submit Attendance?</h2>
        <p>This will lock the attendance and send an email notification.</p>
        <div class="modal-buttons">
            <button type="button" class="btn" onclick="closeSubmitModal()">Cancel</button>
            <button type="button" class="btn btn-success" onclick="confirmSubmit()">Submit</button>
        </div>
    </div>
</div>
HTML;

$extraScripts = '<script src="' . base_path() . '/assets/js/attendance.js"></script>';

echo view('layouts/app', [
    'title' => $brigade['name'] . ' - Attendance',
    'content' => $content,
    'bodyClass' => 'attendance-page',
    'extraHead' => $extraHead,
    'extraScripts' => $extraScripts,
]);
