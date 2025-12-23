<?php
$basePath = base_path();
$content = <<<HTML
<div class="login-container">
    <h1>{$brigade['name']}</h1>
    <h2>Admin Login</h2>

    <form id="login-form" class="login-form">
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autofocus>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>
        <div id="login-error" class="error-message"></div>
        <button type="submit" class="btn btn-primary">Login</button>
    </form>

    <div class="login-back">
        <a href="{$basePath}/{$slug}">Back to Attendance</a>
    </div>
</div>

<script>
document.getElementById('login-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    const errorEl = document.getElementById('login-error');

    try {
        const response = await fetch(window.BASE_PATH + '/{$slug}/admin/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password })
        });

        const data = await response.json();

        if (data.success) {
            window.location.href = data.redirect;
        } else {
            errorEl.textContent = data.error || 'Invalid credentials';
        }
    } catch (err) {
        errorEl.textContent = 'Connection error. Please try again.';
    }
});
</script>
HTML;

echo view('layouts/app', [
    'title' => 'Admin Login - ' . $brigade['name'],
    'content' => $content,
    'bodyClass' => 'login-page',
]);
