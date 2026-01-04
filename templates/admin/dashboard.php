<?php
ob_start();
?>
<div class="dashboard">
    <h1>Dashboard</h1>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Active Callouts</h3>
            <div class="stat-value"><?= $activeCallouts ?></div>
        </div>
        <div class="stat-card">
            <h3>Total Members</h3>
            <div class="stat-value"><?= count(\App\Models\Member::findByBrigade($brigade['id'])) ?></div>
        </div>
        <div class="stat-card">
            <h3>Trucks</h3>
            <div class="stat-value"><?= count(\App\Models\Truck::findByBrigade($brigade['id'])) ?></div>
        </div>
    </div>

    <div class="dashboard-section">
        <h2>Recent Callouts</h2>
        <?php if (empty($recentCallouts)): ?>
            <p class="no-data">No callouts yet.</p>
        <?php else: ?>
            <table class="data-table clickable-rows">
                <thead>
                    <tr>
                        <th>ICAD</th>
                        <th>Status</th>
                        <th>SMS</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentCallouts as $callout): ?>
                        <tr onclick="window.location.href='<?= base_path() ?>/<?= $slug ?>/admin/callouts?view=<?= $callout['id'] ?>'" style="cursor: pointer;">
                            <td><?= sanitize($callout['icad_number']) ?></td>
                            <td><span class="status-badge status-<?= $callout['status'] ?>"><?= ucfirst($callout['status']) ?></span></td>
                            <td><span class="sms-status <?= !empty($callout['sms_uploaded']) ? 'sms-uploaded' : 'sms-not-uploaded' ?>"><?= !empty($callout['sms_uploaded']) ? 'Uploaded' : 'Not Uploaded' ?></span></td>
                            <td><?= date('d/m/Y H:i', strtotime($callout['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="dashboard-section">
        <h2>Quick Actions</h2>
        <div class="quick-actions">
            <a href="<?= base_path() ?>/<?= $slug ?>/attendance" class="btn btn-primary" target="_blank">Open Attendance Page</a>
            <a href="<?= base_path() ?>/<?= $slug ?>/admin/members" class="btn">Manage Members</a>
            <a href="<?= base_path() ?>/<?= $slug ?>/admin/trucks" class="btn">Configure Trucks</a>
            <a href="<?= base_path() ?>/<?= $slug ?>/admin/settings" class="btn">Brigade Settings</a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

echo view('layouts/admin', [
    'title' => 'Dashboard',
    'brigade' => $brigade,
    'slug' => $slug,
    'content' => $content,
]);
