<?php $basePath = base_path(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title><?= sanitize($title ?? 'Brigade Attendance') ?></title>
    <link rel="manifest" href="<?= $basePath ?>/manifest.json">
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
            navigator.serviceWorker.register('<?= $basePath ?>/sw.js')
                .then(reg => console.log('SW registered'))
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
