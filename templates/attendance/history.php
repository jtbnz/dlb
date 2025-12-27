<?php
$basePath = base_path();
$extraHead = '<script>window.BRIGADE_SLUG = "' . sanitize($slug) . '";</script>';

$content = <<<HTML
<div class="history-container">
    <header class="history-header">
        <div class="header-left">
            <a href="{$basePath}/{$slug}/attendance" class="back-link">&larr; Back to Attendance</a>
            <h1>Recent Callouts</h1>
            <p class="subtitle">Last 30 days</p>
        </div>
    </header>

    <div id="loading" class="loading">
        <div class="spinner"></div>
        <p>Loading callouts...</p>
    </div>

    <div id="callouts-list" class="callouts-list" style="display:none;">
        <!-- Callouts loaded via JS -->
    </div>

    <div id="no-callouts" class="no-callouts" style="display:none;">
        <p>No callouts found in the last 30 days.</p>
    </div>
</div>

<!-- Callout Detail Modal -->
<div id="callout-modal" class="modal" style="display:none;">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h2 id="modal-title">Callout Details</h2>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div id="modal-body" class="modal-body">
            <!-- Details loaded via JS -->
        </div>
    </div>
</div>

<script>
const SLUG = window.BRIGADE_SLUG;
const BASE = window.BASE_PATH || '';

async function loadCallouts() {
    try {
        const response = await fetch(`\${BASE}/\${SLUG}/api/history`);
        const data = await response.json();

        document.getElementById('loading').style.display = 'none';

        if (!data.callouts || data.callouts.length === 0) {
            document.getElementById('no-callouts').style.display = 'block';
            return;
        }

        renderCallouts(data.callouts);
        document.getElementById('callouts-list').style.display = 'block';
    } catch (error) {
        console.error('Failed to load callouts:', error);
        document.getElementById('loading').innerHTML = '<p class="error">Failed to load callouts. Please try again.</p>';
    }
}

function formatNZDate(dateString) {
    // Database stores UTC time, append Z to parse as UTC
    const date = new Date(dateString.replace(' ', 'T') + 'Z');
    return {
        dateStr: date.toLocaleDateString('en-NZ', { timeZone: 'Pacific/Auckland', weekday: 'short', day: 'numeric', month: 'short' }),
        timeStr: date.toLocaleTimeString('en-NZ', { timeZone: 'Pacific/Auckland', hour: '2-digit', minute: '2-digit' }),
        fullDateStr: date.toLocaleDateString('en-NZ', { timeZone: 'Pacific/Auckland', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
    };
}

function renderTruckBadges(truckCrews) {
    if (!truckCrews || Object.keys(truckCrews).length === 0) {
        return '<span class="truck-badge">0 crew</span>';
    }
    return Object.entries(truckCrews)
        .map(([truck, count]) => `<span class="truck-badge">\${escapeHtml(truck)}: \${count}</span>`)
        .join('');
}

function renderCallouts(callouts) {
    const container = document.getElementById('callouts-list');

    container.innerHTML = callouts.map(c => {
        const { dateStr, timeStr } = formatNZDate(c.created_at);

        const location = c.location || 'Location pending...';
        const callType = c.call_type || '';
        const duration = c.duration || '';

        return `
            <div class="callout-card" onclick="viewCallout(\${c.id})">
                <div class="callout-header">
                    <span class="icad-number">\${escapeHtml(c.icad_number)}</span>
                    <div class="truck-badges">\${renderTruckBadges(c.truck_crews)}</div>
                </div>
                <div class="callout-meta">
                    <span class="date">\${dateStr}</span>
                    <span class="time">\${timeStr}</span>
                    \${duration ? `<span class="duration">\${escapeHtml(duration)}</span>` : ''}
                </div>
                \${callType ? `<div class="call-type">\${escapeHtml(callType)}</div>` : ''}
                <div class="location">\${escapeHtml(location)}</div>
            </div>
        `;
    }).join('');
}

async function viewCallout(id) {
    document.getElementById('modal-body').innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    document.getElementById('callout-modal').style.display = 'flex';

    try {
        const response = await fetch(`\${BASE}/\${SLUG}/api/history/\${id}`);
        const data = await response.json();

        if (data.error) {
            document.getElementById('modal-body').innerHTML = '<p class="error">' + escapeHtml(data.error) + '</p>';
            return;
        }

        renderCalloutDetail(data.callout);
    } catch (error) {
        document.getElementById('modal-body').innerHTML = '<p class="error">Failed to load callout details.</p>';
    }
}

function renderCalloutDetail(callout) {
    const { fullDateStr, timeStr } = formatNZDate(callout.created_at);

    document.getElementById('modal-title').textContent = callout.icad_number;

    let html = `
        <div class="callout-detail-header">
            <p><strong>Date:</strong> \${fullDateStr} at \${timeStr}</p>
            \${callout.location ? `<p><strong>Location:</strong> \${escapeHtml(callout.location)}</p>` : ''}
            \${callout.call_type ? `<p><strong>Type:</strong> \${escapeHtml(callout.call_type)}</p>` : ''}
            \${callout.duration ? `<p><strong>Duration:</strong> \${escapeHtml(callout.duration)}</p>` : ''}
            <p><a href="https://sitrep.fireandemergency.nz/report/\${encodeURIComponent(callout.icad_number)}" target="_blank" class="sitrep-link">View SITREP Report &rarr;</a></p>
        </div>
        <hr>
        <h3>Attendance</h3>
    `;

    if (callout.attendance_grouped && callout.attendance_grouped.length > 0) {
        callout.attendance_grouped.forEach(truck => {
            html += `<div class="attendance-truck"><h4>\${escapeHtml(truck.truck_name)}</h4>`;
            Object.values(truck.positions).forEach(pos => {
                pos.members.forEach(m => {
                    const posLabel = pos.allow_multiple ? 'Standby' : escapeHtml(pos.position_name);
                    html += `
                        <div class="attendance-row">
                            <span class="attendance-position">\${posLabel}:</span>
                            <span class="attendance-member">\${escapeHtml(m.member_name)} (\${escapeHtml(m.member_rank)})</span>
                        </div>
                    `;
                });
            });
            html += '</div>';
        });
    } else {
        html += '<p class="no-data">No attendance recorded.</p>';
    }

    document.getElementById('modal-body').innerHTML = html;
}

function closeModal() {
    document.getElementById('callout-modal').style.display = 'none';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

// Close modal on background click
document.getElementById('callout-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

loadCallouts();
</script>
HTML;

echo view('layouts/app', [
    'title' => $brigade['name'] . ' - Recent Callouts',
    'content' => $content,
    'bodyClass' => 'history-page',
    'extraHead' => $extraHead,
]);
