<?php
/**
 * Visitor Insights — JSON API (standalone dashboard app).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/../lib/Aggregate.php';

$site  = isset($_GET['site'])  ? (string) $_GET['site']  : 'all';
$range = isset($_GET['range']) ? (string) $_GET['range'] : '7d';

echo json_encode(vi_aggregate($cfg, $site, $range), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
