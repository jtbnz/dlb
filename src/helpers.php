<?php

function config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/../config/config.php';
    }

    if ($key === null) {
        return $config;
    }

    $keys = explode('.', $key);
    $value = $config;

    foreach ($keys as $k) {
        if (!isset($value[$k])) {
            return $default;
        }
        $value = $value[$k];
    }

    return $value;
}

function db(): \App\Services\Database
{
    static $db = null;

    if ($db === null) {
        $db = new \App\Services\Database(config('database.path'));
    }

    return $db;
}

function view(string $template, array $data = []): string
{
    extract($data);

    ob_start();
    require __DIR__ . '/../templates/' . $template . '.php';
    return ob_get_clean();
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function redirect(string $path): void
{
    $basePath = config('app.base_path', '');
    $url = $basePath . '/' . ltrim($path, '/');
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void
{
    $token = null;
    
    // Check for CSRF token in various locations
    if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    } elseif (isset($_POST['csrf_token'])) {
        $token = $_POST['csrf_token'];
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['csrf_token'] ?? null;
    }
    
    if (!$token || !verify_csrf($token)) {
        json_response(['error' => 'Invalid CSRF token'], 403);
    }
}

function sanitize(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function base_url(string $path = ''): string
{
    return config('app.url') . '/' . ltrim($path, '/');
}

function base_path(): string
{
    return config('app.base_path', '');
}

function url(string $path = ''): string
{
    $basePath = base_path();
    return $basePath . '/' . ltrim($path, '/');
}

function audit_log(int $brigadeId, ?int $calloutId, string $action, array $details = []): void
{
    db()->insert('audit_log', [
        'brigade_id' => $brigadeId,
        'callout_id' => $calloutId,
        'action' => $action,
        'details' => json_encode($details),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}
