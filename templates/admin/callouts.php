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
            <button type="button" class="btn btn-danger" id="delete-btn" onclick="deleteCallout()">Delete</button>
            <button type="button" class="btn" onclick="closeCalloutModal()">Close</button>
            <button type="button" class="btn btn-primary" id="unlock-btn" style="display:none;" onclick="unlockCallout()">Unlock</button>
        </div>
    </div>
</div>

<!-- Add Member Modal -->
<div id="add-member-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <h2>Add Member to Callout</h2>
        <div class="form-group">
            <label>Member</label>
            <select id="add-member-select">
                <option value="">Select member...</option>
            </select>
        </div>
        <div class="form-group">
            <label>Truck</label>
            <select id="add-truck-select" onchange="updatePositionSelect()">
                <option value="">Select truck...</option>
            </select>
        </div>
        <div class="form-group">
            <label>Position</label>
            <select id="add-position-select">
                <option value="">Select position...</option>
            </select>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn" onclick="closeAddMemberModal()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="addMemberToCallout()">Add</button>
        </div>
    </div>
</div>

<!-- Move Member Modal -->
<div id="move-member-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <h2>Move Member</h2>
        <p id="move-member-name"></p>
        <div class="form-group">
            <label>Truck</label>
            <select id="move-truck-select" onchange="updateMovePositionSelect()">
                <option value="">Select truck...</option>
            </select>
        </div>
        <div class="form-group">
            <label>Position</label>
            <select id="move-position-select">
                <option value="">Select position...</option>
            </select>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn" onclick="closeMoveModal()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="moveMember()">Move</button>
        </div>
    </div>
</div>

<!-- Edit Info Modal -->
<div id="edit-info-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <h2>Edit Incident Details</h2>
        <div class="form-group">
            <label>Location</label>
            <input type="text" id="edit-location" placeholder="e.g., Pukekohe, Auckland">
        </div>
        <div class="form-group">
            <label>Call Type</label>
            <input type="text" id="edit-call-type" placeholder="e.g., Structure Fire">
        </div>
        <div class="form-group">
            <label>Duration</label>
            <input type="text" id="edit-duration" placeholder="e.g., 00:45:00">
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn" onclick="closeEditInfoModal()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="saveCalloutInfo()">Save</button>
        </div>
    </div>
</div>

<script>
const SLUG = '<?= $slug ?>';
const BASE = window.BASE_PATH || '';
let currentCalloutId = null;
let currentCallout = null;
let trucks = [];
let members = [];
let moveAttendanceId = null;

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

    const response = await fetch(`${BASE}/${SLUG}/admin/api/callouts?${params}`);
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
    const response = await fetch(`${BASE}/${SLUG}/admin/api/callouts/${id}`);
    const data = await response.json();
    currentCallout = data.callout;
    trucks = data.trucks || [];
    members = data.members || [];

    renderCalloutDetails();
    document.getElementById('unlock-btn').style.display = currentCallout.status !== 'active' ? 'inline-block' : 'none';
    document.getElementById('callout-modal').style.display = 'flex';
}

function renderCalloutDetails() {
    const callout = currentCallout;

    // Get list of assigned member IDs
    const assignedMemberIds = new Set();
    if (callout.attendance) {
        callout.attendance.forEach(a => assignedMemberIds.add(a.member_id));
    }

    let html = `
        <p><strong>ICAD:</strong> ${escapeHtml(callout.icad_number)}</p>
        <p><strong>Status:</strong> <span class="status-badge status-${callout.status}">${callout.status}</span></p>
        <p><strong>Created:</strong> ${formatDate(callout.created_at)}</p>
        ${callout.submitted_at ? `<p><strong>Submitted:</strong> ${formatDate(callout.submitted_at)} by ${escapeHtml(callout.submitted_by || 'Unknown')}</p>` : ''}
        <hr>
        <div class="callout-edit-header">
            <h3>Incident Details</h3>
            <button class="btn btn-small" onclick="showEditInfoModal()">Edit</button>
            ${callout.icad_number && callout.icad_number.startsWith('F') ? `<a href="https://sitrep.fireandemergency.nz/report/${encodeURIComponent(callout.icad_number)}" target="_blank" class="btn btn-small btn-primary">ICAD Report</a>` : ''}
        </div>
        <div class="incident-info">
            <p><strong>Location:</strong> ${escapeHtml(callout.location) || '<em>Not set</em>'}</p>
            <p><strong>Call Type:</strong> ${escapeHtml(callout.call_type) || '<em>Not set</em>'}</p>
            <p><strong>Duration:</strong> ${escapeHtml(callout.duration) || '<em>Not set</em>'}</p>
            ${callout.fenz_fetched_at ? `<p class="fenz-fetched"><small>Data fetched: ${formatDate(callout.fenz_fetched_at)}</small></p>` : '<p class="fenz-pending"><small>FENZ data not yet fetched</small></p>'}
        </div>
        <hr>
        <div class="callout-edit-header">
            <h3>Attendance</h3>
            <button class="btn btn-small btn-primary" onclick="showAddMemberModal()">+ Add Member</button>
        </div>
    `;

    if (callout.attendance_grouped && callout.attendance_grouped.length > 0) {
        callout.attendance_grouped.forEach(truck => {
            html += `<div class="attendance-truck"><h4>${escapeHtml(truck.truck_name)}</h4>`;
            Object.values(truck.positions).forEach(pos => {
                pos.members.forEach(m => {
                    const posLabel = pos.allow_multiple ? 'Standby' : escapeHtml(pos.position_name);
                    html += `
                        <div class="attendance-row">
                            <span class="attendance-position">${posLabel}:</span>
                            <span class="attendance-member">${escapeHtml(m.member_name)} (${escapeHtml(m.member_rank)})</span>
                            <div class="attendance-actions">
                                <button class="btn-small" onclick="showMoveModal(${m.attendance_id}, '${escapeHtml(m.member_name)}')">Move</button>
                                <button class="btn-small btn-danger" onclick="removeMember(${m.attendance_id})">Remove</button>
                            </div>
                        </div>
                    `;
                });
            });
            html += '</div>';
        });
    } else {
        html += '<p>No attendance recorded.</p>';
    }

    document.getElementById('callout-details').innerHTML = html;
}

function closeCalloutModal() {
    document.getElementById('callout-modal').style.display = 'none';
    currentCalloutId = null;
    currentCallout = null;
}

function showAddMemberModal() {
    // Populate member dropdown with unassigned members only
    const assignedMemberIds = new Set();
    if (currentCallout.attendance) {
        currentCallout.attendance.forEach(a => assignedMemberIds.add(a.member_id));
    }

    const availableMembers = members.filter(m => m.is_active && !assignedMemberIds.has(m.id));

    const memberSelect = document.getElementById('add-member-select');
    memberSelect.innerHTML = '<option value="">Select member...</option>' +
        availableMembers.map(m => `<option value="${m.id}">${escapeHtml(m.name)} (${escapeHtml(m.rank)})</option>`).join('');

    // Populate truck dropdown
    const truckSelect = document.getElementById('add-truck-select');
    truckSelect.innerHTML = '<option value="">Select truck...</option>' +
        trucks.map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');

    document.getElementById('add-position-select').innerHTML = '<option value="">Select truck first...</option>';
    document.getElementById('add-member-modal').style.display = 'flex';
}

function closeAddMemberModal() {
    document.getElementById('add-member-modal').style.display = 'none';
}

function updatePositionSelect() {
    const truckId = parseInt(document.getElementById('add-truck-select').value);
    const positionSelect = document.getElementById('add-position-select');

    if (!truckId) {
        positionSelect.innerHTML = '<option value="">Select truck first...</option>';
        return;
    }

    const truck = trucks.find(t => t.id === truckId);
    if (!truck || !truck.positions) {
        positionSelect.innerHTML = '<option value="">No positions available</option>';
        return;
    }

    positionSelect.innerHTML = '<option value="">Select position...</option>' +
        truck.positions.map(p => `<option value="${p.id}">${escapeHtml(p.name)}${p.allow_multiple ? ' (Standby)' : ''}</option>`).join('');
}

async function addMemberToCallout() {
    const memberId = document.getElementById('add-member-select').value;
    const truckId = document.getElementById('add-truck-select').value;
    const positionId = document.getElementById('add-position-select').value;

    if (!memberId || !truckId || !positionId) {
        alert('Please select member, truck, and position');
        return;
    }

    try {
        const response = await fetch(`${BASE}/${SLUG}/admin/api/callouts/${currentCalloutId}/attendance`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ member_id: parseInt(memberId), truck_id: parseInt(truckId), position_id: parseInt(positionId) })
        });

        const data = await response.json();

        if (data.error) {
            alert(data.error);
            return;
        }

        currentCallout.attendance_grouped = data.attendance_grouped;
        // Update attendance array too
        await refreshCalloutData();
        renderCalloutDetails();
        closeAddMemberModal();
    } catch (error) {
        alert('Failed to add member');
    }
}

async function removeMember(attendanceId) {
    if (!confirm('Remove this member from the callout?')) return;

    try {
        const response = await fetch(`${BASE}/${SLUG}/admin/api/callouts/${currentCalloutId}/attendance/${attendanceId}`, {
            method: 'DELETE'
        });

        const data = await response.json();

        if (data.error) {
            alert(data.error);
            return;
        }

        currentCallout.attendance_grouped = data.attendance_grouped;
        await refreshCalloutData();
        renderCalloutDetails();
    } catch (error) {
        alert('Failed to remove member');
    }
}

function showMoveModal(attendanceId, memberName) {
    moveAttendanceId = attendanceId;
    document.getElementById('move-member-name').textContent = `Moving: ${memberName}`;

    // Populate truck dropdown
    const truckSelect = document.getElementById('move-truck-select');
    truckSelect.innerHTML = '<option value="">Select truck...</option>' +
        trucks.map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');

    document.getElementById('move-position-select').innerHTML = '<option value="">Select truck first...</option>';
    document.getElementById('move-member-modal').style.display = 'flex';
}

function closeMoveModal() {
    document.getElementById('move-member-modal').style.display = 'none';
    moveAttendanceId = null;
}

function showEditInfoModal() {
    document.getElementById('edit-location').value = currentCallout.location || '';
    document.getElementById('edit-call-type').value = currentCallout.call_type || '';
    document.getElementById('edit-duration').value = currentCallout.duration || '';
    document.getElementById('edit-info-modal').style.display = 'flex';
}

function closeEditInfoModal() {
    document.getElementById('edit-info-modal').style.display = 'none';
}

async function saveCalloutInfo() {
    const location = document.getElementById('edit-location').value;
    const callType = document.getElementById('edit-call-type').value;
    const duration = document.getElementById('edit-duration').value;

    try {
        const response = await fetch(`${BASE}/${SLUG}/admin/api/callouts/${currentCalloutId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                location: location,
                call_type: callType,
                duration: duration
            })
        });

        const data = await response.json();

        if (data.error) {
            alert(data.error);
            return;
        }

        // Refresh callout data
        await refreshCalloutData();
        renderCalloutDetails();
        closeEditInfoModal();
    } catch (error) {
        alert('Failed to save incident details');
    }
}

function updateMovePositionSelect() {
    const truckId = parseInt(document.getElementById('move-truck-select').value);
    const positionSelect = document.getElementById('move-position-select');

    if (!truckId) {
        positionSelect.innerHTML = '<option value="">Select truck first...</option>';
        return;
    }

    const truck = trucks.find(t => t.id === truckId);
    if (!truck || !truck.positions) {
        positionSelect.innerHTML = '<option value="">No positions available</option>';
        return;
    }

    positionSelect.innerHTML = '<option value="">Select position...</option>' +
        truck.positions.map(p => `<option value="${p.id}">${escapeHtml(p.name)}${p.allow_multiple ? ' (Standby)' : ''}</option>`).join('');
}

async function moveMember() {
    const truckId = document.getElementById('move-truck-select').value;
    const positionId = document.getElementById('move-position-select').value;

    if (!truckId || !positionId) {
        alert('Please select truck and position');
        return;
    }

    try {
        const response = await fetch(`${BASE}/${SLUG}/admin/api/callouts/${currentCalloutId}/attendance/${moveAttendanceId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ truck_id: parseInt(truckId), position_id: parseInt(positionId) })
        });

        const data = await response.json();

        if (data.error) {
            alert(data.error);
            return;
        }

        currentCallout.attendance_grouped = data.attendance_grouped;
        await refreshCalloutData();
        renderCalloutDetails();
        closeMoveModal();
    } catch (error) {
        alert('Failed to move member');
    }
}

async function refreshCalloutData() {
    // Refresh the full callout data to sync attendance array
    const response = await fetch(`${BASE}/${SLUG}/admin/api/callouts/${currentCalloutId}`);
    const data = await response.json();
    currentCallout = data.callout;
}

async function unlockCallout() {
    if (!currentCalloutId) return;
    if (!confirm('Unlock this callout? It will become editable again.')) return;

    await fetch(`${BASE}/${SLUG}/admin/api/callouts/${currentCalloutId}/unlock`, { method: 'PUT' });
    await viewCallout(currentCalloutId);
    searchCallouts();
}

async function deleteCallout() {
    if (!currentCalloutId) return;
    if (!confirm('Are you sure you want to delete this callout? This will permanently remove all attendance records for this callout.')) return;
    if (!confirm('This cannot be undone. Are you absolutely sure?')) return;

    try {
        const response = await fetch(`${BASE}/${SLUG}/admin/api/callouts/${currentCalloutId}`, { method: 'DELETE' });
        const data = await response.json();

        if (data.error) {
            alert(data.error);
            return;
        }

        alert('Callout deleted successfully');
        closeCalloutModal();
        searchCallouts();
    } catch (error) {
        alert('Failed to delete callout');
    }
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

    window.location.href = `${BASE}/${SLUG}/admin/api/callouts/export?${params}`;
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
