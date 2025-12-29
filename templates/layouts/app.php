<?php $basePath = base_path(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#dc2626">
    <title><?= sanitize($title ?? 'Brigade Attendance') ?></title>
    <link rel="manifest" href="<?= $basePath ?>/manifest.json">
    <link rel="apple-touch-icon" href="<?= $basePath ?>/assets/images/icon-192.png">
    <link rel="icon" type="image/svg+xml" href="<?= $basePath ?>/assets/images/icon.svg">
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/app.css">
    <script>window.BASE_PATH = '<?= $basePath ?>';</script>
    <?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body class="<?= $bodyClass ?? '' ?>">
    <?= $content ?>
    <?php if (isset($extraScripts)) echo $extraScripts; ?>
    <script>
        // Register service worker for PWA
        if ('serviceWorker' in navigator) {
            // Force update check on every page load
            navigator.serviceWorker.register('<?= $basePath ?>/sw.js', { updateViaCache: 'none' })
                .then(reg => {
                    console.log('SW registered');
                    // Check for updates
                    reg.update();
                })
                .catch(err => console.log('SW registration failed:', err));

            // Sync when back online
            window.addEventListener('online', () => {
                navigator.serviceWorker.ready.then(reg => {
                    reg.active.postMessage('sync');
                });
            });
        }
    </script>
</body>
</html>
