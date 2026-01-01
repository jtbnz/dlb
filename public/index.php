<?php

declare(strict_types=1);

session_start();

// Autoload
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

require_once __DIR__ . '/../src/helpers.php';

// Initialize database
db()->initializeSchema();
db()->migrate();
db()->createDemoBrigade();

// Simple router
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Remove query string
$path = parse_url($requestUri, PHP_URL_PATH);

// Strip base path for subdirectory deployment
$basePath = config('app.base_path', '');
if ($basePath && strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}

$path = rtrim($path, '/') ?: '/';

// Route matching
$routes = [
    // Public routes
    ['GET', '/', 'HomeController@index'],

    // Super Admin routes (must come before brigade routes)
    ['GET', '/admin', 'SuperAdminController@showLogin'],
    ['POST', '/admin/login', 'SuperAdminController@login'],
    ['GET', '/admin/logout', 'SuperAdminController@logout'],
    ['GET', '/admin/dashboard', 'SuperAdminController@dashboard'],
    ['GET', '/admin/api/brigades', 'SuperAdminController@apiGetBrigades'],
    ['POST', '/admin/api/brigades', 'SuperAdminController@apiCreateBrigade'],
    ['PUT', '/admin/api/brigades/([0-9]+)', 'SuperAdminController@apiUpdateBrigade'],
    ['DELETE', '/admin/api/brigades/([0-9]+)', 'SuperAdminController@apiDeleteBrigade'],
    ['GET', '/admin/fenz-status', 'SuperAdminController@fenzStatus'],
    ['GET', '/admin/api/fenz-status', 'SuperAdminController@apiFenzStatus'],
    ['POST', '/admin/api/fenz-trigger', 'SuperAdminController@apiFenzTrigger'],

    // Attendance entry (PIN required) - must come before catch-all brigade route
    ['GET', '/([a-z0-9-]+)/attendance', 'AttendanceController@index'],
    ['GET', '/([a-z0-9-]+)/history', 'AttendanceController@history'],

    // Brigade PIN entry and auth
    ['GET', '/([a-z0-9-]+)', 'AuthController@showPin'],
    ['POST', '/([a-z0-9-]+)/auth', 'AuthController@verifyPin'],

    // API v1 routes (token auth for external integrations)
    ['POST', '/([a-z0-9-]+)/api/v1/musters', 'ApiController@createMuster'],
    ['GET', '/([a-z0-9-]+)/api/v1/musters', 'ApiController@listMusters'],
    ['PUT', '/([a-z0-9-]+)/api/v1/musters/([0-9]+)/visibility', 'ApiController@updateVisibility'],
    ['POST', '/([a-z0-9-]+)/api/v1/musters/([0-9]+)/attendance', 'ApiController@setAttendance'],
    ['POST', '/([a-z0-9-]+)/api/v1/musters/([0-9]+)/attendance/bulk', 'ApiController@bulkSetAttendance'],
    ['GET', '/([a-z0-9-]+)/api/v1/musters/([0-9]+)/attendance', 'ApiController@getAttendance'],
    ['GET', '/([a-z0-9-]+)/api/v1/members', 'ApiController@listMembers'],
    ['POST', '/([a-z0-9-]+)/api/v1/members', 'ApiController@createMember'],

    // Attendance API routes
    ['GET', '/([a-z0-9-]+)/api/callout/active', 'AttendanceController@getActive'],
    ['GET', '/([a-z0-9-]+)/api/callout/last-attendance', 'AttendanceController@getLastCallAttendance'],
    ['POST', '/([a-z0-9-]+)/api/callout', 'AttendanceController@createCallout'],
    ['PUT', '/([a-z0-9-]+)/api/callout/([0-9]+)', 'AttendanceController@updateCallout'],
    ['POST', '/([a-z0-9-]+)/api/callout/([0-9]+)/submit', 'AttendanceController@submitCallout'],
    ['POST', '/([a-z0-9-]+)/api/callout/([0-9]+)/copy-last', 'AttendanceController@copyLastCall'],
    ['GET', '/([a-z0-9-]+)/api/members', 'AttendanceController@getMembers'],
    ['GET', '/([a-z0-9-]+)/api/trucks', 'AttendanceController@getTrucks'],
    ['POST', '/([a-z0-9-]+)/api/attendance', 'AttendanceController@addAttendance'],
    ['DELETE', '/([a-z0-9-]+)/api/attendance/([0-9]+)', 'AttendanceController@removeAttendance'],
    ['GET', '/([a-z0-9-]+)/api/sse/callout/([0-9]+)', 'SSEController@stream'],
    ['GET', '/([a-z0-9-]+)/api/history', 'AttendanceController@apiGetHistory'],
    ['GET', '/([a-z0-9-]+)/api/history/([0-9]+)', 'AttendanceController@apiGetHistoryDetail'],

    // Admin routes
    ['GET', '/([a-z0-9-]+)/admin', 'AdminController@showLogin'],
    ['POST', '/([a-z0-9-]+)/admin/login', 'AdminController@login'],
    ['POST', '/([a-z0-9-]+)/admin/logout', 'AdminController@logout'],
    ['GET', '/([a-z0-9-]+)/admin/dashboard', 'AdminController@dashboard'],

    // Admin Members
    ['GET', '/([a-z0-9-]+)/admin/members', 'AdminController@members'],
    ['GET', '/([a-z0-9-]+)/admin/api/members', 'AdminController@apiGetMembers'],
    ['POST', '/([a-z0-9-]+)/admin/api/members', 'AdminController@apiCreateMember'],
    ['PUT', '/([a-z0-9-]+)/admin/api/members/([0-9]+)', 'AdminController@apiUpdateMember'],
    ['DELETE', '/([a-z0-9-]+)/admin/api/members/([0-9]+)', 'AdminController@apiDeleteMember'],
    ['POST', '/([a-z0-9-]+)/admin/api/members/import', 'AdminController@apiImportMembers'],

    // Admin Trucks
    ['GET', '/([a-z0-9-]+)/admin/trucks', 'AdminController@trucks'],
    ['GET', '/([a-z0-9-]+)/admin/api/trucks', 'AdminController@apiGetTrucks'],
    ['POST', '/([a-z0-9-]+)/admin/api/trucks', 'AdminController@apiCreateTruck'],
    ['PUT', '/([a-z0-9-]+)/admin/api/trucks/([0-9]+)', 'AdminController@apiUpdateTruck'],
    ['DELETE', '/([a-z0-9-]+)/admin/api/trucks/([0-9]+)', 'AdminController@apiDeleteTruck'],
    ['PUT', '/([a-z0-9-]+)/admin/api/trucks/reorder', 'AdminController@apiReorderTrucks'],
    ['POST', '/([a-z0-9-]+)/admin/api/trucks/([0-9]+)/positions', 'AdminController@apiCreatePosition'],
    ['PUT', '/([a-z0-9-]+)/admin/api/positions/([0-9]+)', 'AdminController@apiUpdatePosition'],
    ['DELETE', '/([a-z0-9-]+)/admin/api/positions/([0-9]+)', 'AdminController@apiDeletePosition'],

    // Admin Callouts
    ['GET', '/([a-z0-9-]+)/admin/callouts', 'AdminController@callouts'],
    ['GET', '/([a-z0-9-]+)/admin/api/callouts', 'AdminController@apiGetCallouts'],
    ['GET', '/([a-z0-9-]+)/admin/api/callouts/([0-9]+)', 'AdminController@apiGetCallout'],
    ['PUT', '/([a-z0-9-]+)/admin/api/callouts/([0-9]+)', 'AdminController@apiUpdateCallout'],
    ['PUT', '/([a-z0-9-]+)/admin/api/callouts/([0-9]+)/unlock', 'AdminController@apiUnlockCallout'],
    ['DELETE', '/([a-z0-9-]+)/admin/api/callouts/([0-9]+)', 'AdminController@apiDeleteCallout'],
    ['GET', '/([a-z0-9-]+)/admin/api/callouts/export', 'AdminController@apiExportCallouts'],
    ['POST', '/([a-z0-9-]+)/admin/api/callouts/([0-9]+)/attendance', 'AdminController@apiAddCalloutAttendance'],
    ['DELETE', '/([a-z0-9-]+)/admin/api/callouts/([0-9]+)/attendance/([0-9]+)', 'AdminController@apiRemoveCalloutAttendance'],
    ['PUT', '/([a-z0-9-]+)/admin/api/callouts/([0-9]+)/attendance/([0-9]+)', 'AdminController@apiMoveCalloutAttendance'],

    // Admin Settings
    ['GET', '/([a-z0-9-]+)/admin/settings', 'AdminController@settings'],
    ['GET', '/([a-z0-9-]+)/admin/api/settings', 'AdminController@apiGetSettings'],
    ['PUT', '/([a-z0-9-]+)/admin/api/settings', 'AdminController@apiUpdateSettings'],
    ['PUT', '/([a-z0-9-]+)/admin/api/settings/pin', 'AdminController@apiUpdatePin'],
    ['PUT', '/([a-z0-9-]+)/admin/api/settings/password', 'AdminController@apiUpdatePassword'],
    ['GET', '/([a-z0-9-]+)/admin/api/qrcode', 'AdminController@apiGetQRCode'],
    ['GET', '/([a-z0-9-]+)/admin/api/qrcode/download', 'AdminController@apiDownloadQRCode'],
    ['GET', '/([a-z0-9-]+)/admin/api/backup', 'AdminController@apiBackup'],
    ['POST', '/([a-z0-9-]+)/admin/api/restore', 'AdminController@apiRestore'],

    // Admin Audit
    ['GET', '/([a-z0-9-]+)/admin/audit', 'AdminController@audit'],
    ['GET', '/([a-z0-9-]+)/admin/api/audit', 'AdminController@apiGetAudit'],

    // Admin API Tokens
    ['GET', '/([a-z0-9-]+)/admin/api-tokens', 'AdminController@apiTokens'],
    ['GET', '/([a-z0-9-]+)/admin/api/tokens', 'AdminController@apiGetTokens'],
    ['POST', '/([a-z0-9-]+)/admin/api/tokens', 'AdminController@apiCreateToken'],
    ['PUT', '/([a-z0-9-]+)/admin/api/tokens/([0-9]+)', 'AdminController@apiUpdateToken'],
    ['DELETE', '/([a-z0-9-]+)/admin/api/tokens/([0-9]+)', 'AdminController@apiRevokeToken'],
];

$matched = false;

foreach ($routes as [$method, $pattern, $handler]) {
    $regex = '#^' . $pattern . '$#';

    if ($requestMethod === $method && preg_match($regex, $path, $matches)) {
        array_shift($matches); // Remove full match

        [$controllerName, $action] = explode('@', $handler);
        $controllerClass = "App\\Controllers\\{$controllerName}";

        if (!class_exists($controllerClass)) {
            http_response_code(500);
            echo "Controller not found: {$controllerClass}";
            exit;
        }

        $controller = new $controllerClass();

        if (!method_exists($controller, $action)) {
            http_response_code(500);
            echo "Action not found: {$action}";
            exit;
        }

        try {
            $controller->$action(...$matches);
        } catch (\Exception $e) {
            if (config('app.debug')) {
                http_response_code(500);
                echo "<pre>Error: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "</pre>";
            } else {
                http_response_code(500);
                echo "An error occurred";
            }
        }

        $matched = true;
        break;
    }
}

if (!$matched) {
    http_response_code(404);
    echo view('layouts/error', ['code' => 404, 'message' => 'Page not found']);
}
