<?php
$basePath = base_path();
$content = <<<HTML
<div class="login-container">
    <h1>System Administration</h1>
    <h2>Super Admin Login</h2>

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
</div>

<script>
document.getElementById('login-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    const errorDiv = document.getElementById('login-error');

    try {
        const response = await fetch('{$basePath}/admin/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password })
        });

        const data = await response.json();

        if (data.success) {
            window.location.href = data.redirect;
        } else {
            errorDiv.textContent = data.error || 'Login failed';
        }
    } catch (error) {
        errorDiv.textContent = 'An error occurred. Please try again.';
    }
});
</script>
HTML;

echo view('layouts/app', [
    'title' => 'Super Admin Login',
    'content' => $content,
    'bodyClass' => 'login-page',
]);
