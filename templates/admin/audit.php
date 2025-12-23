<?php
ob_start();
?>
<div class="admin-page">
    <div class="page-header">
        <h1>Audit Log</h1>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Timestamp</th>
                <th>Action</th>
                <th>Details</th>
                <th>IP Address</th>
            </tr>
        </thead>
        <tbody id="audit-tbody">
            <!-- Logs loaded via JS -->
        </tbody>
    </table>

    <div class="pagination">
        <button class="btn" id="load-more" onclick="loadMore()">Load More</button>
    </div>
</div>

<script>
const SLUG = '<?= $slug ?>';
const BASE = window.BASE_PATH || '';
let offset = 0;
const limit = 50;

async function loadLogs(append = false) {
    const response = await fetch(`${BASE}/${SLUG}/admin/api/audit?limit=${limit}&offset=${offset}`);
    const data = await response.json();

    const tbody = document.getElementById('audit-tbody');

    const html = data.logs.map(log => `
        <tr>
            <td>${formatDate(log.created_at)}</td>
            <td><span class="action-badge">${escapeHtml(log.action)}</span></td>
            <td><code>${escapeHtml(log.details)}</code></td>
            <td>${escapeHtml(log.ip_address)}</td>
        </tr>
    `).join('');

    if (append) {
        tbody.innerHTML += html;
    } else {
        tbody.innerHTML = html;
    }

    document.getElementById('load-more').style.display = data.logs.length < limit ? 'none' : 'inline-block';
}

function loadMore() {
    offset += limit;
    loadLogs(true);
}

function formatDate(dateStr) {
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-NZ') + ' ' + d.toLocaleTimeString('en-NZ');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

loadLogs();
</script>
<?php
$content = ob_get_clean();

echo view('layouts/admin', [
    'title' => 'Audit Log',
    'brigade' => $brigade,
    'slug' => $slug,
    'content' => $content,
]);
