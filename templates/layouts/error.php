<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error <?= $code ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="error-page">
    <div class="error-container">
        <h1><?= $code ?></h1>
        <p><?= sanitize($message) ?></p>
        <a href="/" class="btn">Go Home</a>
    </div>
</body>
</html>
