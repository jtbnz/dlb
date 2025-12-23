<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($title ?? 'Admin') ?> - <?= sanitize($brigade['name']) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body class="admin-body">
    <nav class="admin-nav">
        <div class="admin-nav-brand">
            <a href="/<?= sanitize($slug) ?>/admin/dashboard"><?= sanitize($brigade['name']) ?></a>
        </div>
        <div class="admin-nav-links">
            <a href="/<?= sanitize($slug) ?>/admin/dashboard" class="<?= strpos($_SERVER['REQUEST_URI'], '/dashboard') !== false ? 'active' : '' ?>">Dashboard</a>
            <a href="/<?= sanitize($slug) ?>/admin/members" class="<?= strpos($_SERVER['REQUEST_URI'], '/members') !== false ? 'active' : '' ?>">Members</a>
            <a href="/<?= sanitize($slug) ?>/admin/trucks" class="<?= strpos($_SERVER['REQUEST_URI'], '/trucks') !== false ? 'active' : '' ?>">Trucks</a>
            <a href="/<?= sanitize($slug) ?>/admin/callouts" class="<?= strpos($_SERVER['REQUEST_URI'], '/callouts') !== false ? 'active' : '' ?>">Callouts</a>
            <a href="/<?= sanitize($slug) ?>/admin/settings" class="<?= strpos($_SERVER['REQUEST_URI'], '/settings') !== false ? 'active' : '' ?>">Settings</a>
            <a href="/<?= sanitize($slug) ?>/admin/audit" class="<?= strpos($_SERVER['REQUEST_URI'], '/audit') !== false ? 'active' : '' ?>">Audit Log</a>
        </div>
        <form action="/<?= sanitize($slug) ?>/admin/logout" method="POST" class="admin-logout">
            <button type="submit">Logout</button>
        </form>
    </nav>
    <main class="admin-main">
        <?= $content ?>
    </main>
    <script src="/assets/js/admin.js"></script>
    <?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html>
