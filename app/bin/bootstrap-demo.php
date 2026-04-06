#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use ManticoreDemo\Domain\Import\CsvImporter;
use ManticoreDemo\Infrastructure\Manticore\IndexManager;
use Manticoresearch\Client;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$settings = require $root . '/config/settings.php';

$client = new Client([
    'host' => $settings['manticore']['host'],
    'port' => $settings['manticore']['port'],
    'transport' => $settings['manticore']['transport'],
]);

$logPath = rtrim((string) ($settings['paths']['logs'] ?? ($root . '/storage/logs')), '/') . '/bootstrap.log';
if (!is_dir(dirname($logPath))) {
    mkdir(dirname($logPath), 0777, true);
}
$logger = new Logger('bootstrap');
$logger->pushHandler(new StreamHandler($logPath));

$indexManager = new IndexManager($client, $settings['table']);
$importer = new CsvImporter($client, $indexManager, $logger, $settings);

$type = 'csv';
$path = $root . '/storage/catalog/boardgames_part1.csv';

if (!is_file($path) || !is_readable($path)) {
    fwrite(STDERR, "Part 1 fixture is missing or unreadable: {$path}\n");
    exit(1);
}

/**
 * Count data rows in a CSV fixture (excluding header row).
 */
function countCsvRows(string $csvPath): int
{
    $handle = @fopen($csvPath, 'rb');
    if ($handle === false) {
        return 0;
    }
    $rows = 0;
    $isFirst = true;
    while (($data = fgetcsv($handle)) !== false) {
        if ($isFirst) {
            $isFirst = false;
            continue;
        }
        if ($data === [null] || $data === []) {
            continue;
        }
        $rows++;
    }
    fclose($handle);
    return $rows;
}

/**
 * Render import progress line.
 */
function reportProgress(int $processed, int $total): void
{
    $total = max(1, $total);
    $processed = max(0, min($processed, $total));
    $percent = (int) floor(($processed / $total) * 100);
    printf("Items imported: %3d%% (%d/%d)\n", $percent, $processed, $total);
}

$expectedRows = countCsvRows($path);
$lastReported = 0;
$progressShown = false;
if ($expectedRows > 0) {
    reportProgress(0, $expectedRows);
    $progressShown = true;
}
try {
    $indexManager->recreate();
    $result = $importer->importFile(
        $path,
        $type,
        true,
        false,
        static function (int $processed, int $total) use ($expectedRows, &$lastReported, &$progressShown): void {
            $targetTotal = $expectedRows > 0 ? $expectedRows : max(1, $total);
            if ($processed <= $lastReported) {
                return;
            }
            reportProgress($processed, $targetTotal);
            $lastReported = $processed;
            $progressShown = true;
        }
    );
    if (($result['status'] ?? '') !== 'ok') {
        fwrite(STDERR, "Bootstrap import failed.\n");
        exit(1);
    }
    $rows = (int) ($result['rows'] ?? 0);
    if ($rows > $lastReported) {
        $targetTotal = $expectedRows > 0 ? $expectedRows : $rows;
        reportProgress($rows, max(1, $targetTotal));
    } elseif (!$progressShown && $rows > 0) {
        $targetTotal = $expectedRows > 0 ? $expectedRows : $rows;
        reportProgress($rows, max(1, $targetTotal));
    }
    echo "Demo bootstrap complete: imported {$rows} rows from {$path}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Bootstrap failed: {$e->getMessage()}\n");
    exit(1);
}

