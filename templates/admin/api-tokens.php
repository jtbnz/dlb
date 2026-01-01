<?php
ob_start();
?>
<div class="admin-page">
    <h1>API Tokens</h1>
    <p>Manage API tokens for external integrations like the Portal app.</p>

    <div class="settings-section">
        <h2>Create New Token</h2>
        <form id="create-token-form">
            <div class="form-group">
                <label for="token-name">Token Name</label>
                <input type="text" id="token-name" placeholder="e.g., Portal Integration" required>
            </div>
            <div class="form-group">
                <label>Permissions</label>
                <div class="permissions-grid">
                    <?php foreach ($permissions as $key => $label): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="permissions[]" value="<?= sanitize($key) ?>">
                        <?= sanitize($label) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label for="token-expires">Expires (optional)</label>
                <input type="date" id="token-expires">
                <small class="form-help">Leave blank for no expiration</small>
            </div>
            <button type="submit" class="btn btn-primary">Generate Token</button>
        </form>
    </div>

    <div id="new-token-display" class="settings-section" style="display: none;">
        <h2>New Token Created</h2>
        <div class="token-display-box">
            <p><strong>Important:</strong> Copy this token now. It will only be shown once!</p>
            <div class="token-value">
                <code id="new-token-value"></code>
                <button type="button" class="btn btn-small" onclick="copyToken()">Copy</button>
            </div>
        </div>
    </div>

    <div class="settings-section">
        <h2>Existing Tokens</h2>
        <div id="tokens-list">
            <p class="no-data">Loading...</p>
        </div>
    </div>

    <div class="settings-section">
        <h2>API Documentation</h2>
        <p>Use these endpoints with your API token:</p>
        <div class="api-docs">
            <h3>Authentication</h3>
            <pre>Authorization: Bearer your_token_here</pre>

            <h3>Endpoints</h3>
            <table class="api-table">
                <tr>
                    <td><code>POST</code></td>
                    <td><code>/<?= sanitize($slug) ?>/api/v1/musters</code></td>
                    <td>Create a new muster</td>
                </tr>
                <tr>
                    <td><code>GET</code></td>
                    <td><code>/<?= sanitize($slug) ?>/api/v1/musters</code></td>
                    <td>List musters</td>
                </tr>
                <tr>
                    <td><code>PUT</code></td>
                    <td><code>/<?= sanitize($slug) ?>/api/v1/musters/{id}/visibility</code></td>
                    <td>Update muster visibility</td>
                </tr>
                <tr>
                    <td><code>POST</code></td>
                    <td><code>/<?= sanitize($slug) ?>/api/v1/musters/{id}/attendance</code></td>
                    <td>Set member attendance</td>
                </tr>
                <tr>
                    <td><code>POST</code></td>
                    <td><code>/<?= sanitize($slug) ?>/api/v1/musters/{id}/attendance/bulk</code></td>
                    <td>Bulk set attendance</td>
                </tr>
                <tr>
                    <td><code>GET</code></td>
                    <td><code>/<?= sanitize($slug) ?>/api/v1/musters/{id}/attendance</code></td>
                    <td>Get muster attendance</td>
                </tr>
                <tr>
                    <td><code>GET</code></td>
                    <td><code>/<?= sanitize($slug) ?>/api/v1/members</code></td>
                    <td>List members</td>
                </tr>
                <tr>
                    <td><code>POST</code></td>
                    <td><code>/<?= sanitize($slug) ?>/api/v1/members</code></td>
                    <td>Create a member</td>
                </tr>
            </table>

            <h3>Example: Create Muster</h3>
            <pre>curl -X POST <?= config('app.url') ?>/<?= sanitize($slug) ?>/api/v1/musters \
  -H "Authorization: Bearer dlb_<?= sanitize($slug) ?>_..." \
  -H "Content-Type: application/json" \
  -d '{"call_date":"2025-02-03","call_time":"19:00","visible":false}'</pre>

            <h3>Example: Set Leave Status</h3>
            <pre>curl -X POST <?= config('app.url') ?>/<?= sanitize($slug) ?>/api/v1/musters/123/attendance \
  -H "Authorization: Bearer dlb_<?= sanitize($slug) ?>_..." \
  -H "Content-Type: application/json" \
  -d '{"member_id":45,"status":"L","notes":"Annual leave"}'</pre>
        </div>
    </div>
</div>

<style>
.permissions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0.5rem;
    margin-top: 0.5rem;
}
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem;
    background: #f5f5f5;
    border-radius: 4px;
    cursor: pointer;
}
.checkbox-label:hover {
    background: #eee;
}
.token-display-box {
    background: #fffbe6;
    border: 1px solid #ffe58f;
    padding: 1rem;
    border-radius: 4px;
}
.token-value {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-top: 1rem;
    padding: 1rem;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow-x: auto;
}
.token-value code {
    flex: 1;
    word-break: break-all;
    font-family: monospace;
    font-size: 0.9rem;
}
.token-item {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 1rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 1rem;
    background: #fff;
}
.token-info {
    flex: 1;
}
.token-info h3 {
    margin: 0 0 0.5rem 0;
    font-size: 1rem;
}
.token-info .permissions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
    margin: 0.5rem 0;
}
.token-info .permission-badge {
    background: #e6f7ff;
    color: #1890ff;
    padding: 0.125rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
}
.token-info .meta {
    font-size: 0.85rem;
    color: #666;
}
.token-actions {
    display: flex;
    gap: 0.5rem;
}
.btn-danger {
    background: #ff4d4f;
    color: white;
}
.btn-danger:hover {
    background: #cf1322;
}
.api-docs {
    background: #f9f9f9;
    padding: 1rem;
    border-radius: 4px;
}
.api-docs h3 {
    margin-top: 1rem;
    margin-bottom: 0.5rem;
    font-size: 1rem;
}
.api-docs pre {
    background: #1e1e1e;
    color: #d4d4d4;
    padding: 1rem;
    border-radius: 4px;
    overflow-x: auto;
    font-size: 0.85rem;
}
.api-table {
    width: 100%;
    border-collapse: collapse;
}
.api-table td {
    padding: 0.5rem;
    border-bottom: 1px solid #ddd;
}
.api-table code {
    background: #eee;
    padding: 0.125rem 0.25rem;
    border-radius: 2px;
}
</style>

<script>
const SLUG = '<?= sanitize($slug) ?>';
const BASE = window.BASE_PATH || '';

async function loadTokens() {
    const response = await fetch(`${BASE}/${SLUG}/admin/api/tokens`);
    const data = await response.json();

    const container = document.getElementById('tokens-list');

    if (!data.tokens || data.tokens.length === 0) {
        container.innerHTML = '<p class="no-data">No API tokens created yet.</p>';
        return;
    }

    container.innerHTML = data.tokens.map(token => `
        <div class="token-item" data-id="${token.id}">
            <div class="token-info">
                <h3>${escapeHtml(token.name)}</h3>
                <div class="permissions">
                    ${(token.permissions_array || []).map(p => `
                        <span class="permission-badge">${escapeHtml(data.permissions[p] || p)}</span>
                    `).join('')}
                </div>
                <div class="meta">
                    Created: ${new Date(token.created_at).toLocaleDateString()}
                    ${token.last_used_at ? ` | Last used: ${new Date(token.last_used_at).toLocaleDateString()}` : ' | Never used'}
                    ${token.expires_at ? ` | Expires: ${new Date(token.expires_at).toLocaleDateString()}` : ''}
                </div>
            </div>
            <div class="token-actions">
                <button class="btn btn-danger" onclick="revokeToken(${token.id}, '${escapeHtml(token.name)}')">Revoke</button>
            </div>
        </div>
    `).join('');
}

document.getElementById('create-token-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const name = document.getElementById('token-name').value;
    const permissions = Array.from(document.querySelectorAll('input[name="permissions[]"]:checked'))
        .map(cb => cb.value);
    const expiresAt = document.getElementById('token-expires').value || null;

    if (permissions.length === 0) {
        alert('Please select at least one permission');
        return;
    }

    const response = await fetch(`${BASE}/${SLUG}/admin/api/tokens`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, permissions, expires_at: expiresAt })
    });

    const data = await response.json();

    if (data.success) {
        // Show the new token
        document.getElementById('new-token-value').textContent = data.token;
        document.getElementById('new-token-display').style.display = 'block';

        // Reset form
        document.getElementById('create-token-form').reset();

        // Refresh token list
        loadTokens();

        // Scroll to new token display
        document.getElementById('new-token-display').scrollIntoView({ behavior: 'smooth' });
    } else {
        alert(data.error || 'Failed to create token');
    }
});

async function revokeToken(id, name) {
    if (!confirm(`Are you sure you want to revoke the token "${name}"? This action cannot be undone.`)) {
        return;
    }

    const response = await fetch(`${BASE}/${SLUG}/admin/api/tokens/${id}`, {
        method: 'DELETE'
    });

    const data = await response.json();

    if (data.success) {
        loadTokens();
    } else {
        alert(data.error || 'Failed to revoke token');
    }
}

function copyToken() {
    const token = document.getElementById('new-token-value').textContent;
    navigator.clipboard.writeText(token).then(() => {
        alert('Token copied to clipboard!');
    }).catch(() => {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = token;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert('Token copied to clipboard!');
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

loadTokens();
</script>
<?php
$content = ob_get_clean();

echo view('layouts/admin', [
    'title' => 'API Tokens',
    'brigade' => $brigade,
    'slug' => $slug,
    'content' => $content,
]);
