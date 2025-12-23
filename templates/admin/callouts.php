<?php
ob_start();
?>
<div class="admin-page">
    <div class="page-header">
        <h1>Callouts</h1>
    </div>

    <div class="toolbar">
        <input type="text" id="icad-search" placeholder="Search ICAD...">
        <select id="status-filter">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="submitted">Submitted</option>
            <option value="locked">Locked</option>
        </select>
        <input type="date" id="from-date">
        <input type="date" id="to-date">
        <button class="btn" onclick="searchCallouts()">Search</button>
        <div class="toolbar-spacer"></div>
        <button class="btn" onclick="exportCallouts('csv')">Export CSV</button>
        <button class="btn" onclick="exportCallouts('html')">Export HTML</button>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>ICAD</th>
                <th>Status</th>
                <th>Created</th>
                <th>Submitted</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="callouts-tbody">
            <!-- Callouts loaded via JS -->
        </tbody>
    </table>
</div>

<!-- Callout Detail Modal -->
<div id="callout-modal" class="modal" style="display:none;">
    <div class="modal-content modal-large">
        <h2>Callout Details</h2>
        <div id="callout-details">
            <!-- Details loaded via JS -->
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn" onclick="closeCalloutModal()">Close</button>
            <button type="button" class="btn btn-primary" id="unlock-btn" style="display:none;" onclick="unlockCallout()">Unlock</button>
        </div>
    </div>
</div>

<script>
const SLUG = '<?= $slug ?>';
let currentCalloutId = null;

async function searchCallouts() {
    const icad = document.getElementById('icad-search').value;
    const status = document.getElementById('status-filter').value;
    const fromDate = document.getElementById('from-date').value;
    const toDate = document.getElementById('to-date').value;

    const params = new URLSearchParams();
    if (icad) params.append('icad', icad);
    if (status) params.append('status', status);
    if (fromDate) params.append('from_date', fromDate);
    if (toDate) params.append('to_date', toDate);

    const response = await fetch(`/${SLUG}/admin/api/callouts?${params}`);
    const data = await response.json();
    renderCallouts(data.callouts);
}

function renderCallouts(callouts) {
    const tbody = document.getElementById('callouts-tbody');

    tbody.innerHTML = callouts.map(c => `
        <tr>
            <td>${escapeHtml(c.icad_number)}</td>
            <td><span class="status-badge status-${c.status}">${c.status}</span></td>
            <td>${formatDate(c.created_at)}</td>
            <td>${c.submitted_at ? formatDate(c.submitted_at) : '-'}</td>
            <td>
                <button class="btn-small" onclick="viewCallout(${c.id})">View</button>
            </td>
        </tr>
    `).join('');
}

async function viewCallout(id) {
    currentCalloutId = id;
    const response = await fetch(`/${SLUG}/admin/api/callouts/${id}`);
    const data = await response.json();
    const callout = data.callout;

    let html = `
        <p><strong>ICAD:</strong> ${escapeHtml(callout.icad_number)}</p>
        <p><strong>Status:</strong> <span class="status-badge status-${callout.status}">${callout.status}</span></p>
        <p><strong>Created:</strong> ${formatDate(callout.created_at)}</p>
        ${callout.submitted_at ? `<p><strong>Submitted:</strong> ${formatDate(callout.submitted_at)} by ${escapeHtml(callout.submitted_by || 'Unknown')}</p>` : ''}
        <p><a href="https://sitrep.fireandemergency.nz/report/${encodeURIComponent(callout.icad_number)}" target="_blank">View ICAD Report</a></p>
        <hr>
        <h3>Attendance</h3>
    `;

    if (callout.attendance_grouped && callout.attendance_grouped.length > 0) {
        callout.attendance_grouped.forEach(truck => {
            html += `<div class="attendance-truck"><h4>${escapeHtml(truck.truck_name)}</h4>`;
            Object.values(truck.positions).forEach(pos => {
                pos.members.forEach(m => {
                    if (pos.allow_multiple) {
                        html += `<p>Standby: ${escapeHtml(m.member_name)} (${escapeHtml(m.member_rank)})</p>`;
                    } else {
                        html += `<p>${escapeHtml(pos.position_name)}: ${escapeHtml(m.member_name)} (${escapeHtml(m.member_rank)})</p>`;
                    }
                });
            });
            html += '</div>';
        });
    } else {
        html += '<p>No attendance recorded.</p>';
    }

    document.getElementById('callout-details').innerHTML = html;
    document.getElementById('unlock-btn').style.display = callout.status !== 'active' ? 'inline-block' : 'none';
    document.getElementById('callout-modal').style.display = 'flex';
}

function closeCalloutModal() {
    document.getElementById('callout-modal').style.display = 'none';
    currentCalloutId = null;
}

async function unlockCallout() {
    if (!currentCalloutId) return;
    if (!confirm('Unlock this callout? It will become editable again.')) return;

    await fetch(`/${SLUG}/admin/api/callouts/${currentCalloutId}/unlock`, { method: 'PUT' });
    closeCalloutModal();
    searchCallouts();
}

function formatDate(dateStr) {
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-NZ') + ' ' + d.toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function exportCallouts(format) {
    const icad = document.getElementById('icad-search').value;
    const status = document.getElementById('status-filter').value;
    const fromDate = document.getElementById('from-date').value;
    const toDate = document.getElementById('to-date').value;

    const params = new URLSearchParams();
    params.append('format', format);
    if (icad) params.append('icad', icad);
    if (status) params.append('status', status);
    if (fromDate) params.append('from_date', fromDate);
    if (toDate) params.append('to_date', toDate);

    window.location.href = `/${SLUG}/admin/api/callouts/export?${params}`;
}

// Load on page load
searchCallouts();
</script>
<?php
$content = ob_get_clean();

echo view('layouts/admin', [
    'title' => 'Callouts',
    'brigade' => $brigade,
    'slug' => $slug,
    'content' => $content,
]);
