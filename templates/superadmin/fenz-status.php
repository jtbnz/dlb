<?php
$basePath = base_path();
$content = <<<HTML
<div class="admin-container">
    <header class="admin-header">
        <h1>FENZ Data Fetch Status</h1>
        <div class="header-actions">
            <a href="{$basePath}/admin/dashboard" class="btn btn-secondary">Back to Dashboard</a>
            <a href="{$basePath}/admin/logout" class="btn btn-secondary">Logout</a>
        </div>
    </header>

    <main class="admin-main">
        <section class="admin-section">
            <div class="section-header">
                <h2>Current Status</h2>
                <button class="btn btn-primary" onclick="refreshStatus()">Refresh</button>
            </div>

            <div class="status-grid">
                <div class="status-card">
                    <h3>NZ Time</h3>
                    <div id="nz-time" class="status-value">Loading...</div>
                    <div id="nz-day" class="status-label"></div>
                </div>
            </div>
        </section>

        <section class="admin-section">
            <div class="section-header">
                <h2>Brigades with Pending Callouts</h2>
                <button class="btn btn-success" onclick="triggerFetchAll()">Fetch All</button>
            </div>

            <div id="pending-brigades" class="pending-brigades">
                <p class="loading">Loading...</p>
            </div>
        </section>

        <section class="admin-section">
            <div class="section-header">
                <h2>Fetch Rate Limits</h2>
            </div>

            <div id="fetch-status" class="fetch-status">
                <p class="loading">Loading...</p>
            </div>
        </section>

        <section class="admin-section">
            <div class="section-header">
                <h2>Recent Logs</h2>
            </div>

            <div id="logs-container" class="logs-container">
                <p class="loading">Loading logs...</p>
            </div>
        </section>
    </main>
</div>

<style>
.admin-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #e5e7eb; flex-wrap: wrap; gap: 10px; }
.admin-header h1 { margin: 0; color: #1f2937; }
.header-actions { display: flex; gap: 10px; }
.section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.section-header h2 { margin: 0; }
.admin-section { margin-bottom: 40px; }

.status-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; }
.status-card { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border: 1px solid #e5e7eb; text-align: center; }
.status-card h3 { margin: 0 0 10px 0; color: #6b7280; font-size: 14px; text-transform: uppercase; }
.status-value { font-size: 24px; font-weight: bold; color: #1f2937; }
.status-label { color: #6b7280; font-size: 14px; margin-top: 5px; }

.pending-brigades { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px; }
.pending-card { background: white; border-radius: 8px; padding: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
.pending-card .info h4 { margin: 0; color: #1f2937; }
.pending-card .info .count { color: #f59e0b; font-size: 14px; }
.pending-card .info .region { color: #6b7280; font-size: 12px; }

.fetch-status { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; }
.fetch-card { background: white; border-radius: 8px; padding: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border: 1px solid #e5e7eb; }
.fetch-card h4 { margin: 0 0 10px 0; color: #1f2937; }
.fetch-card .detail { font-size: 13px; color: #6b7280; margin: 5px 0; }
.fetch-card .can-fetch { color: #10b981; font-weight: 500; }
.fetch-card .rate-limited { color: #ef4444; font-weight: 500; }

.logs-container { background: #1f2937; color: #e5e7eb; border-radius: 8px; padding: 20px; max-height: 500px; overflow-y: auto; font-family: monospace; font-size: 12px; line-height: 1.5; }
.log-line { margin: 2px 0; white-space: pre-wrap; word-break: break-all; }
.log-line.error { color: #f87171; }
.log-line.warning { color: #fbbf24; }
.log-line.success { color: #34d399; }

.btn { padding: 8px 16px; border-radius: 6px; border: none; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
.btn-primary { background: #3b82f6; color: white; }
.btn-primary:hover { background: #2563eb; }
.btn-secondary { background: #e5e7eb; color: #374151; }
.btn-secondary:hover { background: #d1d5db; }
.btn-success { background: #10b981; color: white; }
.btn-success:hover { background: #059669; }
.btn-small { padding: 4px 10px; font-size: 12px; }

.loading { color: #6b7280; }
.error-message { color: #dc2626; }
.no-data { color: #6b7280; font-style: italic; }
</style>

<script>
const BASE = '{$basePath}';
let statusData = null;

async function loadStatus() {
    try {
        const response = await fetch(BASE + '/admin/api/fenz-status');
        statusData = await response.json();
        renderStatus();
    } catch (error) {
        console.error('Failed to load status:', error);
    }
}

function renderStatus() {
    // NZ Time
    document.getElementById('nz-time').textContent = statusData.current_nz_time || 'Unknown';
    document.getElementById('nz-day').textContent = statusData.current_nz_day || '';

    // Pending brigades
    const pendingContainer = document.getElementById('pending-brigades');
    if (!statusData.pending_brigades || statusData.pending_brigades.length === 0) {
        pendingContainer.innerHTML = '<p class="no-data">No brigades with pending callouts</p>';
    } else {
        pendingContainer.innerHTML = statusData.pending_brigades.map(b => `
            <div class="pending-card">
                <div class="info">
                    <h4>\${escapeHtml(b.name)}</h4>
                    <div class="count">\${b.pending_count} pending callouts</div>
                    <div class="region">Region: \${b.region || 1}</div>
                </div>
                <button class="btn btn-primary btn-small" onclick="triggerFetch(\${b.id})">Fetch Now</button>
            </div>
        `).join('');
    }

    // Fetch status
    const fetchContainer = document.getElementById('fetch-status');
    const fetchStatus = statusData.fetch_status || {};
    const brigadeIds = Object.keys(fetchStatus);

    if (brigadeIds.length === 0) {
        fetchContainer.innerHTML = '<p class="no-data">No fetch history</p>';
    } else {
        fetchContainer.innerHTML = brigadeIds.map(id => {
            const s = fetchStatus[id];
            return `
                <div class="fetch-card">
                    <h4>Brigade ID: \${id}</h4>
                    <div class="detail">Last fetch: \${s.last_fetch_formatted}</div>
                    <div class="detail">Next available: \${new Date(s.next_fetch_available * 1000).toLocaleString()}</div>
                    <div class="detail \${s.can_fetch_now ? 'can-fetch' : 'rate-limited'}">
                        \${s.can_fetch_now ? 'Can fetch now' : 'Rate limited'}
                    </div>
                </div>
            `;
        }).join('');
    }

    // Logs
    renderLogs();
}

function renderLogs() {
    const container = document.getElementById('logs-container');
    const logs = statusData.logs || [];

    if (logs.length === 0) {
        container.innerHTML = '<p class="no-data">No logs yet</p>';
        return;
    }

    container.innerHTML = logs.map(line => {
        let className = 'log-line';
        if (line.includes('ERROR') || line.includes('Could not find')) {
            className += ' error';
        } else if (line.includes('WARNING')) {
            className += ' warning';
        } else if (line.includes('Updated') || line.includes('Completed')) {
            className += ' success';
        }
        return `<div class="\${className}">\${escapeHtml(line)}</div>`;
    }).join('');

    // Scroll to bottom
    container.scrollTop = container.scrollHeight;
}

async function triggerFetch(brigadeId) {
    try {
        const response = await fetch(BASE + '/admin/api/fenz-trigger', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ brigade_id: brigadeId })
        });

        const data = await response.json();

        if (data.success) {
            alert('Fetch completed. Updated: ' + data.updated + ' callouts');
            loadStatus();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    } catch (error) {
        alert('Failed to trigger fetch');
    }
}

async function triggerFetchAll() {
    if (!confirm('This will fetch FENZ data for all brigades with pending callouts. Continue?')) {
        return;
    }

    try {
        const response = await fetch(BASE + '/admin/api/fenz-trigger', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ brigade_id: 0 })
        });

        const data = await response.json();

        if (data.success) {
            const results = data.results || [];
            const totalUpdated = results.reduce((sum, r) => sum + r.updated, 0);
            alert('Fetch completed. Total updated: ' + totalUpdated + ' callouts across ' + results.length + ' brigades');
            loadStatus();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    } catch (error) {
        alert('Failed to trigger fetch');
    }
}

function refreshStatus() {
    loadStatus();
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Load status on page load
loadStatus();

// Auto-refresh every 30 seconds
setInterval(loadStatus, 30000);
</script>
HTML;

echo view('layouts/app', [
    'title' => 'FENZ Status - System Administration',
    'content' => $content,
    'bodyClass' => 'admin-page',
]);
