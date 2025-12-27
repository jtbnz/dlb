<?php
ob_start();
?>
<div class="admin-page">
    <div class="page-header">
        <h1>Members</h1>
        <button class="btn btn-primary" onclick="showAddMemberModal()">Add Member</button>
    </div>

    <div class="toolbar">
        <input type="text" id="member-search" placeholder="Search members..." onkeyup="filterMembers()">
        <button class="btn" onclick="showImportModal()">Import CSV</button>
    </div>

    <table class="data-table" id="members-table">
        <thead>
            <tr>
                <th>Display Name</th>
                <th>Rank</th>
                <th>First Name</th>
                <th>Last Name</th>
                <th>Email</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="members-tbody">
            <!-- Members loaded via JS -->
        </tbody>
    </table>
</div>

<!-- Add/Edit Member Modal -->
<div id="member-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <h2 id="member-modal-title">Add Member</h2>
        <form id="member-form">
            <input type="hidden" id="member-id">
            <div class="form-group">
                <label for="member-display-name">Display Name *</label>
                <input type="text" id="member-display-name" required>
                <small class="form-help">e.g., SO John Smith, QFF Jane Doe</small>
            </div>
            <div class="form-group">
                <label for="member-rank">Rank</label>
                <input type="text" id="member-rank">
                <small class="form-help">e.g., CFO, DCFO, SSO, SO, SFF, QFF, FF, RCFF</small>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="member-first-name">First Name</label>
                    <input type="text" id="member-first-name">
                </div>
                <div class="form-group">
                    <label for="member-last-name">Last Name</label>
                    <input type="text" id="member-last-name">
                </div>
            </div>
            <div class="form-group">
                <label for="member-email">Email</label>
                <input type="email" id="member-email">
            </div>
            <div class="form-group">
                <label for="member-joindate">Join Date</label>
                <input type="date" id="member-joindate">
                <small class="form-help">Used for seniority ordering (optional)</small>
            </div>
            <div class="modal-buttons">
                <button type="button" class="btn" onclick="closeMemberModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Import Modal -->
<div id="import-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <h2>Import Members from CSV</h2>
        <p>Format: Display Name, Rank, First Name, Last Name, Email (one per line)</p>
        <form id="import-form">
            <div class="form-group">
                <textarea id="import-csv" rows="10" placeholder="SO John Smith,SO,John,Smith,john@example.com&#10;QFF Jane Doe,QFF,Jane,Doe,jane@example.com"></textarea>
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" id="update-existing"> Update existing members
                </label>
            </div>
            <div class="modal-buttons">
                <button type="button" class="btn" onclick="closeImportModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Import</button>
            </div>
        </form>
    </div>
</div>

<style>
.form-row {
    display: flex;
    gap: 1rem;
}
.form-row .form-group {
    flex: 1;
}
</style>

<script>
const SLUG = '<?= $slug ?>';
const BASE = window.BASE_PATH || '';
let members = [];

async function loadMembers() {
    const response = await fetch(`${BASE}/${SLUG}/admin/api/members`);
    const data = await response.json();
    members = data.members;
    renderMembers();
}

function renderMembers() {
    const tbody = document.getElementById('members-tbody');
    const search = document.getElementById('member-search').value.toLowerCase();

    const filtered = members.filter(m =>
        (m.display_name || '').toLowerCase().includes(search) ||
        (m.rank || '').toLowerCase().includes(search) ||
        (m.first_name || '').toLowerCase().includes(search) ||
        (m.last_name || '').toLowerCase().includes(search) ||
        (m.email || '').toLowerCase().includes(search)
    );

    tbody.innerHTML = filtered.map(m => `
        <tr class="${m.is_active ? '' : 'inactive'}">
            <td>${escapeHtml(m.display_name || '')}</td>
            <td>${escapeHtml(m.rank || '')}</td>
            <td>${escapeHtml(m.first_name || '')}</td>
            <td>${escapeHtml(m.last_name || '')}</td>
            <td>${escapeHtml(m.email || '')}</td>
            <td>${m.is_active ? '<span class="status-badge status-active">Active</span>' : '<span class="status-badge status-inactive">Inactive</span>'}</td>
            <td>
                <button class="btn-small" onclick="editMember(${m.id})">Edit</button>
                ${m.is_active ?
                    `<button class="btn-small btn-danger" onclick="deactivateMember(${m.id})">Deactivate</button>` :
                    `<button class="btn-small btn-success" onclick="activateMember(${m.id})">Activate</button>`
                }
            </td>
        </tr>
    `).join('');
}

function filterMembers() {
    renderMembers();
}

function showAddMemberModal() {
    document.getElementById('member-modal-title').textContent = 'Add Member';
    document.getElementById('member-id').value = '';
    document.getElementById('member-display-name').value = '';
    document.getElementById('member-rank').value = '';
    document.getElementById('member-first-name').value = '';
    document.getElementById('member-last-name').value = '';
    document.getElementById('member-email').value = '';
    document.getElementById('member-joindate').value = '';
    document.getElementById('member-modal').style.display = 'flex';
}

function editMember(id) {
    const member = members.find(m => m.id === id);
    if (!member) return;

    document.getElementById('member-modal-title').textContent = 'Edit Member';
    document.getElementById('member-id').value = id;
    document.getElementById('member-display-name').value = member.display_name || '';
    document.getElementById('member-rank').value = member.rank || '';
    document.getElementById('member-first-name').value = member.first_name || '';
    document.getElementById('member-last-name').value = member.last_name || '';
    document.getElementById('member-email').value = member.email || '';
    document.getElementById('member-joindate').value = member.join_date || '';
    document.getElementById('member-modal').style.display = 'flex';
}

function closeMemberModal() {
    document.getElementById('member-modal').style.display = 'none';
}

document.getElementById('member-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const id = document.getElementById('member-id').value;
    const displayName = document.getElementById('member-display-name').value;
    const rank = document.getElementById('member-rank').value;
    const firstName = document.getElementById('member-first-name').value;
    const lastName = document.getElementById('member-last-name').value;
    const email = document.getElementById('member-email').value;
    const joinDate = document.getElementById('member-joindate').value;

    const url = id ? `${BASE}/${SLUG}/admin/api/members/${id}` : `${BASE}/${SLUG}/admin/api/members`;
    const method = id ? 'PUT' : 'POST';

    await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            display_name: displayName,
            rank,
            first_name: firstName,
            last_name: lastName,
            email,
            join_date: joinDate || null
        })
    });

    closeMemberModal();
    loadMembers();
});

async function deactivateMember(id) {
    if (!confirm('Deactivate this member?')) return;

    await fetch(`${BASE}/${SLUG}/admin/api/members/${id}`, { method: 'DELETE' });
    loadMembers();
}

async function activateMember(id) {
    await fetch(`${BASE}/${SLUG}/admin/api/members/${id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ is_active: 1 })
    });
    loadMembers();
}

function showImportModal() {
    document.getElementById('import-csv').value = '';
    document.getElementById('update-existing').checked = false;
    document.getElementById('import-modal').style.display = 'flex';
}

function closeImportModal() {
    document.getElementById('import-modal').style.display = 'none';
}

document.getElementById('import-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const csv = document.getElementById('import-csv').value;
    const updateExisting = document.getElementById('update-existing').checked;

    const response = await fetch(`${BASE}/${SLUG}/admin/api/members/import`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csv, update_existing: updateExisting })
    });

    const result = await response.json();
    alert(`Imported: ${result.imported}, Updated: ${result.updated}, Skipped: ${result.skipped}`);

    closeImportModal();
    loadMembers();
});

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

loadMembers();
</script>
<?php
$content = ob_get_clean();

echo view('layouts/admin', [
    'title' => 'Members',
    'brigade' => $brigade,
    'slug' => $slug,
    'content' => $content,
]);
