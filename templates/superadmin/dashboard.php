<?php
$basePath = base_path();
$content = <<<HTML
<div class="admin-container">
    <header class="admin-header">
        <h1>System Administration</h1>
        <div class="header-actions">
            <a href="{$basePath}/admin/fenz-status" class="btn btn-secondary">FENZ Status</a>
            <a href="{$basePath}/admin/logout" class="btn btn-secondary">Logout</a>
        </div>
    </header>

    <main class="admin-main">
        <section class="admin-section">
            <div class="section-header">
                <h2>Brigades</h2>
                <button class="btn btn-primary" onclick="showCreateModal()">Add Brigade</button>
            </div>

            <div id="brigades-list" class="brigades-grid">
                <p class="loading">Loading brigades...</p>
            </div>
        </section>
    </main>
</div>

<!-- Create Brigade Modal -->
<div id="create-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Brigade</h3>
            <button class="modal-close" onclick="closeCreateModal()">&times;</button>
        </div>
        <form id="create-form">
            <div class="form-group">
                <label for="create-name">Brigade Name *</label>
                <input type="text" id="create-name" required>
            </div>
            <div class="form-group">
                <label for="create-slug">URL Slug (auto-generated if blank)</label>
                <input type="text" id="create-slug" placeholder="e.g., my-brigade">
                <small>Used in URLs: {$basePath}/<strong>slug</strong>/</small>
            </div>
            <div class="form-group">
                <label for="create-username">Admin Username *</label>
                <input type="text" id="create-username" value="admin" required>
            </div>
            <div class="form-group">
                <label for="create-password">Admin Password *</label>
                <input type="password" id="create-password" required minlength="8">
                <small>Minimum 8 characters</small>
            </div>
            <div class="form-group">
                <label for="create-pin">Attendance PIN *</label>
                <input type="text" id="create-pin" value="1234" pattern="[0-9]{4,6}" required>
                <small>4-6 digits for member access</small>
            </div>
            <div id="create-error" class="error-message"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Brigade</button>
            </div>
        </form>
    </div>
</div>

<!-- Welcome Email Modal -->
<div id="welcome-modal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3>Brigade Created Successfully</h3>
            <button class="modal-close" onclick="closeWelcomeModal()">&times;</button>
        </div>
        <div class="welcome-content">
            <p class="success-message">The brigade has been created. Copy the following email to send to the brigade admin:</p>
            <div class="email-preview">
                <div class="email-field">
                    <label>Subject:</label>
                    <div class="field-row">
                        <input type="text" id="welcome-subject" readonly>
                        <button class="btn btn-small" onclick="copyField('welcome-subject')">Copy</button>
                    </div>
                </div>
                <div class="email-field">
                    <label>Body:</label>
                    <textarea id="welcome-body" readonly rows="18"></textarea>
                    <button class="btn btn-small copy-body-btn" onclick="copyField('welcome-body')">Copy Body</button>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="copyAllEmail()">Copy All to Clipboard</button>
                <button class="btn btn-primary" onclick="closeWelcomeModal()">Done</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Brigade Modal -->
<div id="edit-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Brigade</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form id="edit-form">
            <input type="hidden" id="edit-id">
            <div class="form-group">
                <label for="edit-name">Brigade Name</label>
                <input type="text" id="edit-name" required>
            </div>
            <div class="form-group">
                <label for="edit-slug">URL Slug</label>
                <input type="text" id="edit-slug" required>
            </div>
            <div class="form-group">
                <label for="edit-username">Admin Username</label>
                <input type="text" id="edit-username" required>
            </div>
            <div class="form-group">
                <label for="edit-password">New Admin Password (leave blank to keep current)</label>
                <input type="password" id="edit-password" minlength="8">
            </div>
            <div class="form-group">
                <label for="edit-pin">New Attendance PIN (leave blank to keep current)</label>
                <input type="text" id="edit-pin" pattern="[0-9]{4,6}">
            </div>
            <div id="edit-error" class="error-message"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-danger" onclick="deleteBrigade()">Delete Brigade</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<style>
.admin-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #e5e7eb; }
.admin-header h1 { margin: 0; color: #1f2937; }
.section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.section-header h2 { margin: 0; }

.brigades-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
.brigade-card { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border: 1px solid #e5e7eb; }
.brigade-card h3 { margin: 0 0 10px 0; color: #1f2937; }
.brigade-card .slug { color: #6b7280; font-size: 14px; margin-bottom: 15px; }
.brigade-card .stats { display: flex; gap: 20px; margin-bottom: 15px; font-size: 14px; color: #4b5563; }
.brigade-card .actions { display: flex; gap: 10px; flex-wrap: wrap; }
.brigade-card .btn { font-size: 13px; padding: 6px 12px; }

.modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
.modal-content { background: white; border-radius: 8px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto; }
.modal-content.modal-large { max-width: 700px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 20px; border-bottom: 1px solid #e5e7eb; }
.modal-header h3 { margin: 0; }
.modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; }
.modal-content form { padding: 20px; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
.modal-actions .btn-danger { margin-right: auto; }

.welcome-content { padding: 20px; }
.welcome-content .success-message { color: #16a34a; font-weight: 500; margin-bottom: 20px; }
.email-preview { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 15px; }
.email-field { margin-bottom: 15px; }
.email-field:last-child { margin-bottom: 0; }
.email-field label { display: block; font-weight: 500; margin-bottom: 5px; color: #374151; }
.email-field .field-row { display: flex; gap: 10px; }
.email-field .field-row input { flex: 1; }
.email-field input, .email-field textarea { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 4px; font-family: monospace; font-size: 13px; background: white; }
.email-field textarea { resize: vertical; min-height: 300px; }
.email-field .copy-body-btn { margin-top: 10px; }
.btn-small { padding: 6px 12px; font-size: 12px; }

.btn { padding: 8px 16px; border-radius: 6px; border: none; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
.btn-primary { background: #3b82f6; color: white; }
.btn-primary:hover { background: #2563eb; }
.btn-secondary { background: #e5e7eb; color: #374151; }
.btn-secondary:hover { background: #d1d5db; }
.btn-danger { background: #ef4444; color: white; }
.btn-danger:hover { background: #dc2626; }

.form-group { margin-bottom: 15px; }
.form-group label { display: block; margin-bottom: 5px; font-weight: 500; color: #374151; }
.form-group input { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
.form-group small { color: #6b7280; font-size: 12px; }
.error-message { color: #dc2626; margin-top: 10px; }
.loading { color: #6b7280; }
</style>

<script>
const BASE = '{$basePath}';
let brigades = [];

async function loadBrigades() {
    try {
        const response = await fetch(BASE + '/admin/api/brigades');
        const data = await response.json();
        brigades = data.brigades || [];
        renderBrigades();
    } catch (error) {
        console.error('Failed to load brigades:', error);
        document.getElementById('brigades-list').innerHTML = '<p class="error-message">Failed to load brigades</p>';
    }
}

function renderBrigades() {
    const container = document.getElementById('brigades-list');

    if (brigades.length === 0) {
        container.innerHTML = '<p>No brigades yet. Click "Add Brigade" to create one.</p>';
        return;
    }

    container.innerHTML = brigades.map(b => `
        <div class="brigade-card">
            <h3>\${escapeHtml(b.name)}</h3>
            <div class="slug">\${BASE}/\${escapeHtml(b.slug)}/</div>
            <div class="stats">
                <span>Admin: \${escapeHtml(b.admin_username)}</span>
            </div>
            <div class="actions">
                <a href="\${BASE}/\${escapeHtml(b.slug)}/" class="btn btn-secondary" target="_blank">View Site</a>
                <a href="\${BASE}/\${escapeHtml(b.slug)}/admin" class="btn btn-secondary" target="_blank">Brigade Admin</a>
                <button class="btn btn-primary" onclick="showEditModal(\${b.id})">Edit</button>
            </div>
        </div>
    `).join('');
}

function showCreateModal() {
    document.getElementById('create-form').reset();
    document.getElementById('create-error').textContent = '';
    document.getElementById('create-modal').style.display = 'flex';
}

function closeCreateModal() {
    document.getElementById('create-modal').style.display = 'none';
}

function showEditModal(brigadeId) {
    const brigade = brigades.find(b => b.id === brigadeId);
    if (!brigade) return;

    document.getElementById('edit-id').value = brigade.id;
    document.getElementById('edit-name').value = brigade.name;
    document.getElementById('edit-slug').value = brigade.slug;
    document.getElementById('edit-username').value = brigade.admin_username;
    document.getElementById('edit-password').value = '';
    document.getElementById('edit-pin').value = '';
    document.getElementById('edit-error').textContent = '';
    document.getElementById('edit-modal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('edit-modal').style.display = 'none';
}

function showWelcomeModal(welcomeEmail) {
    document.getElementById('welcome-subject').value = welcomeEmail.subject;
    document.getElementById('welcome-body').value = welcomeEmail.body;
    document.getElementById('welcome-modal').style.display = 'flex';
}

function closeWelcomeModal() {
    document.getElementById('welcome-modal').style.display = 'none';
}

function copyField(fieldId) {
    const field = document.getElementById(fieldId);
    field.select();
    document.execCommand('copy');

    // Show feedback
    const btn = field.parentElement.querySelector('button') || field.nextElementSibling;
    const originalText = btn.textContent;
    btn.textContent = 'Copied!';
    setTimeout(() => { btn.textContent = originalText; }, 1500);
}

function copyAllEmail() {
    const subject = document.getElementById('welcome-subject').value;
    const body = document.getElementById('welcome-body').value;
    const fullEmail = 'Subject: ' + subject + '\\n\\n' + body;

    navigator.clipboard.writeText(fullEmail).then(() => {
        const btn = event.target;
        const originalText = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = originalText; }, 1500);
    }).catch(() => {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = fullEmail;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert('Email copied to clipboard!');
    });
}

document.getElementById('create-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const errorDiv = document.getElementById('create-error');
    errorDiv.textContent = '';

    try {
        const response = await fetch(BASE + '/admin/api/brigades', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name: document.getElementById('create-name').value,
                slug: document.getElementById('create-slug').value,
                admin_username: document.getElementById('create-username').value,
                admin_password: document.getElementById('create-password').value,
                pin: document.getElementById('create-pin').value
            })
        });

        const data = await response.json();

        if (data.success) {
            closeCreateModal();
            loadBrigades();

            // Show welcome email modal if available
            if (data.welcome_email) {
                showWelcomeModal(data.welcome_email);
            }
        } else {
            errorDiv.textContent = data.error || 'Failed to create brigade';
        }
    } catch (error) {
        errorDiv.textContent = 'An error occurred. Please try again.';
    }
});

document.getElementById('edit-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const brigadeId = document.getElementById('edit-id').value;
    const errorDiv = document.getElementById('edit-error');
    errorDiv.textContent = '';

    const updateData = {
        name: document.getElementById('edit-name').value,
        slug: document.getElementById('edit-slug').value,
        admin_username: document.getElementById('edit-username').value
    };

    const newPassword = document.getElementById('edit-password').value;
    if (newPassword) {
        updateData.admin_password = newPassword;
    }

    const newPin = document.getElementById('edit-pin').value;
    if (newPin) {
        updateData.pin = newPin;
    }

    try {
        const response = await fetch(BASE + '/admin/api/brigades/' + brigadeId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(updateData)
        });

        const data = await response.json();

        if (data.success) {
            closeEditModal();
            loadBrigades();
        } else {
            errorDiv.textContent = data.error || 'Failed to update brigade';
        }
    } catch (error) {
        errorDiv.textContent = 'An error occurred. Please try again.';
    }
});

async function deleteBrigade() {
    const brigadeId = document.getElementById('edit-id').value;
    const brigade = brigades.find(b => b.id == brigadeId);

    if (!confirm('Are you sure you want to delete "' + brigade.name + '"? This will permanently delete all members, trucks, callouts, and attendance records. This action cannot be undone.')) {
        return;
    }

    if (!confirm('This is your final warning. All data will be lost. Continue?')) {
        return;
    }

    try {
        const response = await fetch(BASE + '/admin/api/brigades/' + brigadeId, {
            method: 'DELETE'
        });

        const data = await response.json();

        if (data.success) {
            closeEditModal();
            loadBrigades();
        } else {
            document.getElementById('edit-error').textContent = data.error || 'Failed to delete brigade';
        }
    } catch (error) {
        document.getElementById('edit-error').textContent = 'An error occurred. Please try again.';
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Load brigades on page load
loadBrigades();
</script>
HTML;

echo view('layouts/app', [
    'title' => 'System Administration',
    'content' => $content,
    'bodyClass' => 'admin-page',
]);
