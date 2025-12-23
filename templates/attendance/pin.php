<?php
$content = <<<HTML
<div class="pin-container">
    <h1>{$brigade['name']}</h1>
    <p>Enter PIN to access attendance</p>

    <form id="pin-form" class="pin-form">
        <div class="pin-input-container">
            <input type="tel" id="pin" name="pin" maxlength="6" pattern="[0-9]*" inputmode="numeric" autocomplete="off" autofocus>
        </div>
        <div id="pin-error" class="error-message"></div>
        <button type="submit" class="btn btn-primary">Enter</button>
    </form>

    <div class="pin-admin-link">
        <a href="/{$slug}/admin">Admin Login</a>
    </div>
</div>

<script>
document.getElementById('pin-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const pin = document.getElementById('pin').value;
    const errorEl = document.getElementById('pin-error');

    try {
        const response = await fetch('/{$slug}/auth', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pin })
        });

        const data = await response.json();

        if (data.success) {
            window.location.href = data.redirect;
        } else {
            errorEl.textContent = data.error || 'Invalid PIN';
            document.getElementById('pin').value = '';
            document.getElementById('pin').focus();
        }
    } catch (err) {
        errorEl.textContent = 'Connection error. Please try again.';
    }
});
</script>
HTML;

echo view('layouts/app', [
    'title' => $brigade['name'] . ' - Enter PIN',
    'content' => $content,
    'bodyClass' => 'pin-page',
]);
