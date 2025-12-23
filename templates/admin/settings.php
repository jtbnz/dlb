<?php
ob_start();
?>
<div class="admin-page">
    <h1>Brigade Settings</h1>

    <div class="settings-section">
        <h2>General</h2>
        <form id="general-form">
            <div class="form-group">
                <label for="brigade-name">Brigade Name</label>
                <input type="text" id="brigade-name" required>
            </div>
            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    </div>

    <div class="settings-section">
        <h2>Email Recipients</h2>
        <p>These email addresses will receive attendance reports when a callout is submitted.</p>
        <div id="email-list" class="email-list"></div>
        <div class="add-email-form">
            <input type="email" id="new-email" placeholder="Enter email address">
            <button type="button" class="btn" onclick="addEmail()">Add</button>
        </div>
        <div class="form-group" style="margin-top: 1rem;">
            <label>
                <input type="checkbox" id="include-non-attendees" onchange="saveEmailSettings()">
                Include non-attendees in email
            </label>
        </div>
    </div>

    <div class="settings-section">
        <h2>Member Display</h2>
        <form id="member-order-form">
            <div class="form-group">
                <label for="member-order">Member Sort Order</label>
                <select id="member-order">
                    <option value="rank_name">By Rank, then Name (default)</option>
                    <option value="rank_joindate">By Rank, then Join Date</option>
                    <option value="alphabetical">Alphabetical</option>
                </select>
                <small class="form-help">Rank order: CFO, DCFO, SSO, SO, SFF, QFF, FF, RCFF</small>
            </div>
            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    </div>

    <div class="settings-section">
        <h2>Security</h2>

        <h3>Change Member PIN</h3>
        <form id="pin-form">
            <div class="form-group">
                <label for="new-pin">New PIN (4-6 digits)</label>
                <input type="tel" id="new-pin" pattern="[0-9]{4,6}" maxlength="6" required>
            </div>
            <button type="submit" class="btn btn-primary">Change PIN</button>
        </form>

        <h3>Change Admin Password</h3>
        <form id="password-form">
            <div class="form-group">
                <label for="current-password">Current Password</label>
                <input type="password" id="current-password" required>
            </div>
            <div class="form-group">
                <label for="new-password">New Password (min 8 characters)</label>
                <input type="password" id="new-password" minlength="8" required>
            </div>
            <button type="submit" class="btn btn-primary">Change Password</button>
        </form>
    </div>

    <div class="settings-section">
        <h2>QR Code</h2>
        <p>Print this QR code and display it at your station for easy access.</p>
        <div id="qr-container">
            <img id="qr-image" src="" alt="QR Code">
            <p id="qr-url"></p>
            <a href="<?= base_path() ?>/<?= $slug ?>/admin/api/qrcode/download" class="btn" style="margin-top: 1rem;">Download QR Code</a>
        </div>
    </div>

    <div class="settings-section">
        <h2>Backup & Restore</h2>
        <p>Download a backup of the entire database or restore from a previous backup.</p>
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1rem;">
            <a href="<?= base_path() ?>/<?= $slug ?>/admin/api/backup" class="btn">Download Backup</a>
            <button type="button" class="btn" onclick="document.getElementById('restore-file').click()">Restore from Backup</button>
            <input type="file" id="restore-file" accept=".sqlite,.db" style="display: none;" onchange="restoreBackup(this)">
        </div>
        <p class="warning-text" style="margin-top: 1rem; color: #c00;">
            Warning: Restoring a backup will replace all current data. An automatic backup will be created before restore.
        </p>
    </div>
</div>

<script>
const SLUG = '<?= $slug ?>';
const BASE = window.BASE_PATH || '';
let emailRecipients = [];

async function loadSettings() {
    const response = await fetch(`${BASE}/${SLUG}/admin/api/settings`);
    const data = await response.json();

    document.getElementById('brigade-name').value = data.name;
    emailRecipients = data.email_recipients || [];
    document.getElementById('include-non-attendees').checked = data.include_non_attendees;
    document.getElementById('member-order').value = data.member_order || 'rank_name';

    renderEmails();
    loadQRCode();
}

function renderEmails() {
    const container = document.getElementById('email-list');
    container.innerHTML = emailRecipients.map((email, i) => `
        <div class="email-item">
            <span>${escapeHtml(email)}</span>
            <button class="btn-tiny btn-danger" onclick="removeEmail(${i})">×</button>
        </div>
    `).join('') || '<p class="no-data">No email recipients configured.</p>';
}

async function addEmail() {
    const input = document.getElementById('new-email');
    const email = input.value.trim();

    if (!email || !email.includes('@')) {
        alert('Please enter a valid email address');
        return;
    }

    if (emailRecipients.includes(email)) {
        alert('Email already added');
        return;
    }

    emailRecipients.push(email);
    input.value = '';
    renderEmails();
    await saveEmailSettings();
}

async function removeEmail(index) {
    emailRecipients.splice(index, 1);
    renderEmails();
    await saveEmailSettings();
}

async function saveEmailSettings() {
    await fetch(`${BASE}/${SLUG}/admin/api/settings`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email_recipients: emailRecipients,
            include_non_attendees: document.getElementById('include-non-attendees').checked
        })
    });
}

document.getElementById('general-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const response = await fetch(`${BASE}/${SLUG}/admin/api/settings`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: document.getElementById('brigade-name').value })
    });

    if (response.ok) {
        alert('Settings saved');
    }
});

document.getElementById('member-order-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const response = await fetch(`${BASE}/${SLUG}/admin/api/settings`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ member_order: document.getElementById('member-order').value })
    });

    if (response.ok) {
        alert('Member order saved');
    }
});

document.getElementById('pin-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const response = await fetch(`${BASE}/${SLUG}/admin/api/settings/pin`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ pin: document.getElementById('new-pin').value })
    });

    const data = await response.json();
    if (data.success) {
        alert('PIN changed successfully');
        document.getElementById('new-pin').value = '';
    } else {
        alert(data.error || 'Failed to change PIN');
    }
});

document.getElementById('password-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const response = await fetch(`${BASE}/${SLUG}/admin/api/settings/password`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            current_password: document.getElementById('current-password').value,
            new_password: document.getElementById('new-password').value
        })
    });

    const data = await response.json();
    if (data.success) {
        alert('Password changed successfully');
        document.getElementById('current-password').value = '';
        document.getElementById('new-password').value = '';
    } else {
        alert(data.error || 'Failed to change password');
    }
});

async function loadQRCode() {
    const response = await fetch(`${BASE}/${SLUG}/admin/api/qrcode`);
    const data = await response.json();

    document.getElementById('qr-image').src = data.qr_image;
    document.getElementById('qr-url').textContent = data.url;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function restoreBackup(input) {
    if (!input.files || !input.files[0]) return;

    const file = input.files[0];

    if (!confirm('Are you sure you want to restore from this backup? This will replace all current data. An automatic backup will be created first.')) {
        input.value = '';
        return;
    }

    const formData = new FormData();
    formData.append('backup', file);

    try {
        const response = await fetch(`${BASE}/${SLUG}/admin/api/restore`, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            alert(data.message || 'Database restored successfully');
            window.location.reload();
        } else {
            alert(data.error || 'Failed to restore database');
        }
    } catch (error) {
        alert('Error restoring database: ' + error.message);
    }

    input.value = '';
}

loadSettings();
</script>
<?php
$content = ob_get_clean();

echo view('layouts/admin', [
    'title' => 'Settings',
    'brigade' => $brigade,
    'slug' => $slug,
    'content' => $content,
]);
