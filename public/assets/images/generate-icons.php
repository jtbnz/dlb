<?php
/**
 * Icon Generator Script
 *
 * Run this script to generate PNG icons from the SVG template.
 * Requires GD library with PNG support.
 *
 * Usage: php generate-icons.php
 */

$sizes = [192, 512];
$bgColor = [220, 38, 38]; // #dc2626

foreach ($sizes as $size) {
    // Create image
    $image = imagecreatetruecolor($size, $size);

    // Enable anti-aliasing
    imageantialias($image, true);

    // Background color
    $bg = imagecolorallocate($image, $bgColor[0], $bgColor[1], $bgColor[2]);
    $white = imagecolorallocate($image, 255, 255, 255);

    // Fill background
    imagefilledrectangle($image, 0, 0, $size, $size, $bg);

    // Draw rounded corners (simple approximation)
    $cornerRadius = $size * 0.125;

    // Draw a simple fire/flame shape
    $centerX = $size / 2;
    $centerY = $size / 2;

    // Main flame (simplified ellipse)
    $flameWidth = $size * 0.35;
    $flameHeight = $size * 0.5;
    $flameTop = $size * 0.15;

    imagefilledellipse($image, (int)$centerX, (int)($flameTop + $flameHeight/2), (int)$flameWidth, (int)$flameHeight, $white);

    // Inner flame (darker)
    $innerFlameWidth = $size * 0.2;
    $innerFlameHeight = $size * 0.3;
    imagefilledellipse($image, (int)$centerX, (int)($flameTop + $flameHeight/2 + $size*0.05), (int)$innerFlameWidth, (int)$innerFlameHeight, $bg);

    // Checklist/clipboard shape (bottom right)
    $clipboardX = $size * 0.55;
    $clipboardY = $size * 0.5;
    $clipboardW = $size * 0.35;
    $clipboardH = $size * 0.4;

    // Clipboard background (semi-transparent white approximation)
    $lightWhite = imagecolorallocatealpha($image, 255, 255, 255, 80);
    imagefilledrectangle($image, (int)$clipboardX, (int)$clipboardY, (int)($clipboardX + $clipboardW), (int)($clipboardY + $clipboardH), $lightWhite);

    // Clipboard lines
    $lineHeight = $size * 0.02;
    $lineY = $clipboardY + $size * 0.06;
    for ($i = 0; $i < 4; $i++) {
        $lineWidth = $clipboardW * (0.7 - $i * 0.1);
        imagefilledrectangle($image,
            (int)($clipboardX + $size * 0.03),
            (int)$lineY,
            (int)($clipboardX + $size * 0.03 + $lineWidth),
            (int)($lineY + $lineHeight),
            $white
        );
        $lineY += $size * 0.06;
    }

    // Save the image
    $filename = __DIR__ . "/icon-{$size}.png";
    imagepng($image, $filename);
    imagedestroy($image);

    echo "Generated: icon-{$size}.png\n";
}

echo "Done!\n";
