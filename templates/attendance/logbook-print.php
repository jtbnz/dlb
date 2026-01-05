<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($brigade['name']) ?> - Logbook</title>
    <style>
        @page {
            size: A4;
            margin: 15mm;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.3;
            color: #000;
            background: white;
        }

        .print-header {
            text-align: center;
            margin-bottom: 10mm;
            border-bottom: 2px solid #000;
            padding-bottom: 5mm;
        }

        .print-header h1 {
            font-size: 16pt;
            margin-bottom: 2mm;
        }

        .print-header .date-range {
            font-size: 10pt;
            color: #333;
        }

        .logbook-entries {
            width: 100%;
        }

        .logbook-entry {
            display: table;
            width: 100%;
            border: 1px solid #000;
            border-bottom: none;
            page-break-inside: avoid;
        }

        .logbook-entry:last-child {
            border-bottom: 1px solid #000;
        }

        .entry-left {
            display: table-cell;
            width: 22mm;
            padding: 2mm;
            background: #f5f5f5;
            border-right: 1px solid #000;
            vertical-align: top;
        }

        .entry-date {
            font-weight: bold;
            font-size: 10pt;
        }

        .entry-time {
            font-size: 9pt;
        }

        .entry-callnum {
            font-size: 8pt;
            color: #666;
            margin-top: 1mm;
        }

        .entry-main {
            display: table-cell;
            vertical-align: top;
        }

        .entry-header {
            display: table;
            width: 100%;
            background: #eee;
            border-bottom: 1px solid #ccc;
        }

        .entry-details {
            display: table-cell;
            padding: 2mm;
            vertical-align: top;
        }

        .call-type {
            font-weight: bold;
            font-size: 10pt;
        }

        .call-location {
            font-size: 9pt;
            color: #333;
        }

        .entry-icad {
            display: table-cell;
            padding: 2mm;
            text-align: right;
            vertical-align: top;
            font-weight: bold;
            font-size: 10pt;
            white-space: nowrap;
        }

        .entry-trucks {
            padding: 2mm;
        }

        .trucks-grid {
            display: table;
            width: 100%;
        }

        .truck-section {
            display: table-cell;
            vertical-align: top;
            padding-right: 5mm;
            min-width: 40mm;
        }

        .truck-name {
            font-weight: bold;
            font-size: 9pt;
            border-bottom: 1px solid #ccc;
            margin-bottom: 1mm;
            padding-bottom: 1mm;
        }

        .personnel-row {
            font-size: 9pt;
            font-family: 'Courier New', monospace;
            white-space: nowrap;
        }

        .personnel-role {
            display: inline-block;
            width: 8mm;
            font-weight: bold;
        }

        .no-personnel {
            color: #999;
            font-style: italic;
            font-size: 9pt;
        }

        .print-footer {
            margin-top: 10mm;
            text-align: center;
            font-size: 8pt;
            color: #666;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        @media screen {
            body {
                max-width: 210mm;
                margin: 0 auto;
                padding: 10mm;
                background: #eee;
            }

            .print-container {
                background: white;
                padding: 15mm;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }

            .print-controls {
                position: fixed;
                top: 10px;
                right: 10px;
                background: white;
                padding: 10px;
                border-radius: 5px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.2);
                z-index: 100;
            }

            .print-controls button {
                padding: 8px 16px;
                font-size: 14px;
                cursor: pointer;
                background: #2563eb;
                color: white;
                border: none;
                border-radius: 4px;
            }

            .print-controls button:hover {
                background: #1d4ed8;
            }
        }
    </style>
</head>
<body>
    <div class="print-controls no-print">
        <button onclick="window.print()">Print / Save as PDF</button>
    </div>

    <div class="print-container">
        <div class="print-header">
            <h1><?= sanitize($brigade['name']) ?></h1>
            <div class="date-range">
                Logbook: <?= date('d/m/Y', strtotime($fromDate)) ?> - <?= date('d/m/Y', strtotime($toDate)) ?>
            </div>
        </div>

        <?php if (empty($callouts)): ?>
            <p class="no-personnel">No callouts found for this date range.</p>
        <?php else: ?>
            <div class="logbook-entries">
                <?php
                $callNumber = 0;
                foreach ($callouts as $callout):
                    $callNumber++;
                    $callDate = new DateTime($callout['created_at']);
                ?>
                <div class="logbook-entry">
                    <div class="entry-left">
                        <div class="entry-date"><?= $callDate->format('d/m/y') ?></div>
                        <div class="entry-time"><?= $callDate->format('H:i') ?></div>
                        <div class="entry-callnum">Call# <?= $callNumber ?></div>
                    </div>
                    <div class="entry-main">
                        <div class="entry-header">
                            <div class="entry-details">
                                <div class="call-type"><?= sanitize($callout['call_type'] ?: 'Unknown') ?></div>
                                <div class="call-location"><?= sanitize($callout['location'] ?: 'Location not specified') ?></div>
                            </div>
                            <div class="entry-icad"><?= sanitize($callout['icad_number']) ?></div>
                        </div>
                        <div class="entry-trucks">
                            <?php if (!empty($callout['trucks'])): ?>
                                <div class="trucks-grid">
                                    <?php foreach ($callout['trucks'] as $truck): ?>
                                        <?php if (!$truck['is_station']): ?>
                                        <div class="truck-section">
                                            <?php if ($truck['name']): ?>
                                            <div class="truck-name"><?= sanitize($truck['name']) ?></div>
                                            <?php endif; ?>
                                            <?php foreach ($truck['personnel'] as $person): ?>
                                            <div class="personnel-row">
                                                <span class="personnel-role"><?= sanitize($person['role']) ?></span>
                                                <?= sanitize($person['name']) ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="no-personnel">No personnel recorded</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="print-footer">
            Generated: <?= date('d/m/Y H:i') ?> | <?= sanitize($brigade['name']) ?> Digital Logbook
        </div>
    </div>
</body>
</html>
