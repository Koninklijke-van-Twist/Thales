<?php

/**
 * Elke nacht aangeroepen door het bestaande serverscript (GET vanaf localhost, vertrouwd in logincheck.php).
 * SharePoint-delta + dag-index, rapporten parsen, BC-cache via Mímir, aggregaten. Hervat zichzelf bij een volgend run.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/thales_lib.php';

ignore_user_abort(true);
set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) {
    ob_end_flush();
}

$summary = thales_nightly();
foreach ($summary as $key => $value) {
    echo $key . '=' . (is_scalar($value) ? (string) $value : json_encode($value)) . "\n";
}
