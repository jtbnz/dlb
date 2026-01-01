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
                <button id="copy-last-btn" class="btn-small" style="display:none;">Copy Last Muster</button>
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

    <!-- Tab bar for multiple active callouts -->
    <div id="callout-tabs" class="callout-tabs" style="display:none;">
        <div class="tabs-container">
            <div id="tabs-list" class="tabs-list"></div>
            <button type="button" class="tab-button new-callout-tab" onclick="showNewCalloutModal()">+ New</button>
        </div>
    </div>

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

    <div id="recent-calls-section" class="recent-calls-section" style="display:none;">
        <h2>Recent Calls</h2>
        <div id="recent-calls-list" class="recent-calls-list">
            <!-- Recent calls loaded via JS -->
        </div>
    </div>

    <div id="history-panel" class="history-panel" style="display:none;">
        <a href="HISTORY_URL_PLACEHOLDER" id="history-link" class="btn btn-secondary">Browse All Recent Callouts</a>
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
                <div id="leave-section" class="leave-section" style="display:none;">
                    <div class="panel-header leave-header">
                        <h3>On Leave</h3>
                        <span id="leave-count" class="count-badge leave-badge">0</span>
                    </div>
                    <div id="leave-members" class="leave-members">
                        <!-- Leave members will be rendered here -->
                    </div>
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
        <div id="submitter-name-field" class="form-group" style="margin: 1rem 0;">
            <label for="submitter-name">Your Name</label>
            <input type="text" id="submitter-name" placeholder="Enter your name" required>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn" onclick="closeSubmitModal()">Cancel</button>
            <button type="button" class="btn btn-success" onclick="confirmSubmit()">Submit</button>
        </div>
    </div>
</div>

<!-- New Callout Modal (for adding while others are active) -->
<div id="new-callout-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <h2>Add New Callout</h2>
        <form id="modal-new-callout-form" onsubmit="handleModalNewCallout(event)">
            <div class="form-group">
                <input type="text" id="modal-new-icad" placeholder="ICAD Number (e.g., F4363832)" required>
            </div>
            <div class="form-group">
                <input type="datetime-local" id="modal-new-datetime" required>
            </div>
            <div class="form-group">
                <input type="text" id="modal-new-location" placeholder="Address / Location">
            </div>
            <div class="form-group">
                <input type="text" id="modal-new-call-type" placeholder="Call Type (e.g., Structure Fire)">
            </div>
            <div class="modal-buttons">
                <button type="button" class="btn" onclick="closeNewCalloutModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Start Callout</button>
            </div>
        </form>
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
