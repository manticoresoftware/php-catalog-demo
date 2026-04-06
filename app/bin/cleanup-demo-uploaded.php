#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use ManticoreDemo\Domain\Import\CsvImporter;
use ManticoreDemo\Infrastructure\Manticore\IndexManager;
use Manticoresearch\Client;
use Manticoresearch\Search;
use Psr\Log\NullLogger;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$settings = require $root . '/config/settings.php';

$baseMaxId = resolveBaseMaxId($root);
$itemCount = max(0, $baseMaxId);
$minRemaining = (int) round($itemCount / 2);
$tableName = (string) ($settings['table']['name'] ?? 'catalog_board_games');

$client = new Client([
    'host' => $settings['manticore']['host'],
    'port' => $settings['manticore']['port'],
    'transport' => $settings['manticore']['transport'],
]);

$indexManager = new IndexManager($client, $settings['table']);
$indexManager->ensure();

$table = $client->table($tableName);
$table->deleteDocuments([
    'range' => [
        'id' => ['gt' => $baseMaxId],
    ],
]);
$remaining = getTableTotal($client, $tableName);
echo "Cleanup complete for {$tableName}: removed records with id > {$baseMaxId}; remaining={$remaining}\n";

if ($remaining < $minRemaining) {
    $part1 = resolvePart1Fixture($root);
    $path = $part1['path'];
    $type = $part1['type'];
    if (!is_file($path) || !is_readable($path)) {
        fwrite(
            STDERR,
            "Cleanup fallback failed: part1 fixture is missing or unreadable: {$path}\n"
        );
        exit(1);
    }
    $importer = new CsvImporter($client, $indexManager, new NullLogger(), $settings);
    $indexManager->recreate();
    $result = $importer->importFile($path, $type, true);
    if (($result['status'] ?? '') !== 'ok') {
        fwrite(STDERR, "Cleanup fallback failed: bootstrap import failed.\n");
        exit(1);
    }
    $rows = (int) ($result['rows'] ?? 0);
    echo "Cleanup fallback triggered (remaining {$remaining} < {$minRemaining}): recreated table and imported {$rows} rows from {$path}\n";
}

function resolveBaseMaxId(string $root): int
{
    $paths = [
        ['path' => $root . '/storage/catalog/boardgames_part1.csv', 'type' => 'csv'],
        ['path' => $root . '/storage/catalog/boardgames_part1.xml', 'type' => 'xml'],
    ];

    foreach ($paths as $entry) {
        $path = (string) ($entry['path'] ?? '');
        $type = (string) ($entry['type'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            continue;
        }
        $count = countFixtureRows($path, $type);
        if ($count > 0) {
            return $count;
        }
    }

    return 0;
}

function countFixtureRows(string $path, string $type): int
{
    if ($type === 'csv') {
        $handle = @fopen($path, 'rb');
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

    if ($type === 'xml') {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path);
        if ($xml === false) {
            libxml_clear_errors();
            return 0;
        }
        return isset($xml->game) ? count($xml->game) : 0;
    }

    return 0;
}

function getTableTotal(Client $client, string $tableName): int
{
    $search = new Search($client);
    $search->setTable($tableName)
        ->search('*')
        ->limit(1);
    return (int) $search->get()->getTotal();
}

/**
 * @return array{path:string,type:string}
 */
function resolvePart1Fixture(string $root): array
{
    $type = 'csv';
    $path = $root . '/storage/catalog/boardgames_part1.csv';
    return ['path' => $path, 'type' => $type];
}

