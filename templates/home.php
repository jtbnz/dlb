<?php
$content = <<<HTML
<div class="home-container">
    <h1>Brigade Attendance</h1>
    <p>Select your brigade or scan the QR code at your station.</p>

    <div class="brigade-list">
HTML;

foreach ($brigades as $brigade) {
    $content .= '<a href="/' . sanitize($brigade['slug']) . '" class="brigade-card">';
    $content .= '<h2>' . sanitize($brigade['name']) . '</h2>';
    $content .= '</a>';
}

$content .= <<<HTML
    </div>
</div>
HTML;

echo view('layouts/app', [
    'title' => 'Brigade Attendance',
    'content' => $content,
    'bodyClass' => 'home-page',
]);
