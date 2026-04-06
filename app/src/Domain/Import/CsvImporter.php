<?php

declare(strict_types=1);

namespace ManticoreDemo\Domain\Import;

use League\Csv\Exception as CsvException;
use League\Csv\Reader;
use League\Csv\Statement;
use ManticoreDemo\Infrastructure\Manticore\IndexManager;
use Manticoresearch\Client;
use Manticoresearch\Search;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class CsvImporter
{
    private const SAFE_IMPORT_ERROR = 'Import failed due to a server-side issue.';

    private array $indexConfig;

    public function __construct(
        private readonly Client $client,
        private readonly IndexManager $indexManager,
        private readonly LoggerInterface $logger,
        private readonly array $settings,
    ) {
        $this->indexConfig = $settings['table'];
        $this->refreshTaxonomyLookups();
    }

    private function resolveTaxonomyTableName(string $kind): string
    {
        $configured = $this->indexConfig['taxonomy_tables'] ?? [];
        foreach ($configured as $tableConfig) {
            $name = (string) ($tableConfig['name'] ?? '');
            if ($name !== '' && str_contains($name, $kind)) {
                return $name;
            }
        }
        return $kind === 'categories' ? 'catalog_categories' : 'catalog_tags';
    }

    private function loadTaxonomyMap(string $tableName): array
    {
        try {
            $search = new Search($this->client);
            $search->setTable($tableName)
                ->search('*')
                ->sort('id', 'asc')
                ->limit(1000);
            $resultSet = $search->get();
            $map = [];
            foreach ($resultSet as $hit) {
                $data = $hit->getData();
                $label = trim((string) ($data['label'] ?? ''));
                $id = (int) $hit->getId();
                if ($label === '' || $id <= 0) {
                    continue;
                }
                $map[$id] = $label;
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    private function buildLabelLookup(array $idToLabel): array
    {
        $lookup = [];
        foreach ($idToLabel as $id => $label) {
            $id = (int) $id;
            $key = $this->normalizeLabelKey((string) $label);
            if ($id > 0 && $key !== '') {
                $lookup[$key] = $id;
            }
        }
        return $lookup;
    }

    public function recreateIndex(): void
    {
        $this->indexManager->recreate();
        $this->refreshTaxonomyLookups();
    }

    /**
     * @return array{status:string,rows:int,error:?string}
     */
    public function importFile(
        string $path,
        string $type = 'csv',
        bool $allowTaxonomyGrowth = true,
        bool $appendAsNewIds = false,
        ?callable $progressCallback = null
    ): array
    {
        $result = [
            'status' => 'pending',
            'rows' => 0,
            'error' => null,
        ];

        try {
            $this->indexManager->ensure();
            $rows = $this->importFeed($path, $type, $allowTaxonomyGrowth, $appendAsNewIds, $progressCallback);
            $result['status'] = 'ok';
            $result['rows'] = $rows;
        } catch (Throwable $e) {
            $this->logger->error('Import failed', ['exception' => $e]);
            $result['status'] = 'error';
            $result['error'] = self::SAFE_IMPORT_ERROR;
        }

        return $result;
    }

    /**
     * @return array{categories: array<int,string>, tags: array<int,string>}
     */
    public function findUnknownTaxonomyInFeed(string $path, string $type = 'csv'): array
    {
        $records = $this->loadRecords($path, $type);
        $this->refreshTaxonomyLookups();
        return $this->findUnknownTaxonomyFromRecords($records);
    }

    private function importFeed(
        string $path,
        string $type,
        bool $allowTaxonomyGrowth,
        bool $appendAsNewIds,
        ?callable $progressCallback = null
    ): int
    {
        $records = $this->loadRecords($path, $type);
        $this->refreshTaxonomyLookups();
        if ($allowTaxonomyGrowth) {
            $this->syncTaxonomyTablesFromRecords($records);
        } else {
            $unknown = $this->findUnknownTaxonomyFromRecords($records);
            if ($unknown['categories'] !== [] || $unknown['tags'] !== []) {
                throw new RuntimeException('Feed contains taxonomy values outside the preloaded subset.');
            }
        }

        return $this->importRecords($records, $appendAsNewIds, $progressCallback);
    }

    private function loadRecords(string $path, string $type): array
    {
        $normalizedType = strtolower(trim($type));
        if ($normalizedType === 'csv') {
            return $this->loadCsvRecords($path);
        }
        if ($normalizedType === 'xml') {
            return $this->loadXmlRecords($path);
        }
        throw new RuntimeException('Unsupported feed type: ' . $type);
    }

    private function loadCsvRecords(string $path): array
    {
        try {
            $reader = Reader::from($path, 'r');
        } catch (CsvException $e) {
            throw new RuntimeException('Unable to read CSV: ' . $e->getMessage(), 0, $e);
        }
        $reader->setHeaderOffset(0);
        $stmt = new Statement();
        return iterator_to_array($stmt->process($reader), false);
    }

    private function loadXmlRecords(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Unable to read XML feed: file is missing or unreadable.');
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path);
        if ($xml === false) {
            $errors = array_map(
                static fn(\LibXMLError $error): string => trim($error->message),
                libxml_get_errors()
            );
            libxml_clear_errors();
            throw new RuntimeException('Unable to parse XML feed: ' . implode('; ', $errors));
        }
        $records = [];
        foreach ($xml->game as $gameNode) {
            $record = [];
            foreach ($gameNode->children() as $field => $value) {
                $record[(string) $field] = trim((string) $value);
            }
            if ($record !== []) {
                $records[] = $record;
            }
        }
        return $records;
    }

    private function importRecords(
        array $records,
        bool $appendAsNewIds = false,
        ?callable $progressCallback = null
    ): int
    {
        $table = $this->client->table($this->indexConfig['name']);
        $batchSize = $this->settings['import']['batch_size'] ?? 500;
        $batch = [];
        $processed = 0;
        $total = count($records);
        $nextId = $appendAsNewIds ? ($this->getMaxDocumentId() + 1) : 0;

        foreach ($records as $record) {
            $document = $this->mapRecord($record);
            if (!$document) {
                continue;
            }
            if ($appendAsNewIds) {
                $document['id'] = $nextId++;
            }
            $batch[] = $document;
            if (count($batch) >= $batchSize) {
                if ($appendAsNewIds) {
                $table->addDocuments($batch);
                } else {
                    $table->replaceDocuments($batch);
                }
                $processed += count($batch);
                if ($progressCallback !== null) {
                    $progressCallback($processed, $total);
                }
                $batch = [];
            }
        }

        if (!empty($batch)) {
            if ($appendAsNewIds) {
            $table->addDocuments($batch);
            } else {
                $table->replaceDocuments($batch);
            }
            $processed += count($batch);
            if ($progressCallback !== null) {
                $progressCallback($processed, $total);
            }
        }

        return $processed;
    }

    private function getMaxDocumentId(): int
    {
        try {
            $search = new Search($this->client);
            $search->setTable($this->indexConfig['name'])
                ->search('*')
                ->sort('id', 'desc')
                ->limit(1);
            $resultSet = $search->get();
            foreach ($resultSet as $hit) {
                return max(0, (int) $hit->getId());
            }
        } catch (Throwable) {
            return 0;
        }
        return 0;
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @return array{categories: array<int,string>, tags: array<int,string>}
     */
    private function findUnknownTaxonomyFromRecords(array $records): array
    {
        $unknownCategories = [];
        $unknownTags = [];
        $categoryLookup = $this->indexConfig['categories_lookup'] ?? [];
        $tagLookup = $this->indexConfig['tags_lookup'] ?? [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $categoryLabel = $this->extractPrimaryLabel((string) ($record['categories'] ?? ''));
            $categoryKey = $this->normalizeLabelKey($categoryLabel);
            if ($categoryLabel !== '' && !isset($categoryLookup[$categoryKey])) {
                $unknownCategories[$categoryKey] = $categoryLabel;
            }
            foreach ($this->extractLabels((string) ($record['tags'] ?? '')) as $tagLabel) {
                $tagKey = $this->normalizeLabelKey($tagLabel);
                if (!isset($tagLookup[$tagKey])) {
                    $unknownTags[$tagKey] = $tagLabel;
                }
            }
        }
        return [
            'categories' => array_values($unknownCategories),
            'tags' => array_values($unknownTags),
        ];
    }

    private function syncTaxonomyTablesFromRecords(array $records): void
    {
        $categoryTableName = $this->resolveTaxonomyTableName('categories');
        $tagTableName = $this->resolveTaxonomyTableName('tags');
        $categoryTable = $this->client->table($categoryTableName);
        $tagTable = $this->client->table($tagTableName);

        $categoryMap = $this->loadTaxonomyMap($categoryTableName);
        $tagMap = $this->loadTaxonomyMap($tagTableName);
        $categoryLookup = $this->buildLabelLookup($categoryMap);
        $tagLookup = $this->buildLabelLookup($tagMap);
        $nextCategoryId = $categoryMap === [] ? 1 : (max(array_map('intval', array_keys($categoryMap))) + 1);
        $nextTagId = $tagMap === [] ? 1 : (max(array_map('intval', array_keys($tagMap))) + 1);

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $categoryLabel = $this->extractPrimaryLabel((string) ($record['categories'] ?? ''));
            $categoryKey = $this->normalizeLabelKey($categoryLabel);
            if ($categoryLabel !== '' && !isset($categoryLookup[$categoryKey])) {
                $categoryMap[$nextCategoryId] = $categoryLabel;
                $categoryLookup[$categoryKey] = $nextCategoryId;
                $categoryTable->replaceDocument([
                    'label' => $categoryLabel,
                ], $nextCategoryId);
                $nextCategoryId++;
            }

            foreach ($this->extractLabels((string) ($record['tags'] ?? '')) as $tagLabel) {
                $tagKey = $this->normalizeLabelKey($tagLabel);
                if (isset($tagLookup[$tagKey])) {
                    continue;
                }
                $tagMap[$nextTagId] = $tagLabel;
                $tagLookup[$tagKey] = $nextTagId;
                $tagTable->replaceDocument([
                    'label' => $tagLabel,
                ], $nextTagId);
                $nextTagId++;
            }
        }

        $this->indexConfig['categories_map'] = $categoryMap;
        $this->indexConfig['tags_map'] = $tagMap;
        $this->indexConfig['categories_lookup'] = $categoryLookup;
        $this->indexConfig['tags_lookup'] = $tagLookup;
    }

    private function mapRecord(array $record): ?array
    {
        if (empty($record['id']) || empty($record['title'])) {
            return null;
        }
        $categoryId = $this->mapSingle($record['categories'] ?? '', $this->indexConfig['categories_lookup'] ?? []);
        $tagIds = $this->mapMulti($record['tags'] ?? '', $this->indexConfig['tags_lookup'] ?? []);
        $createdAt = strtotime($record['created_at'] ?? 'now');
        $updatedAt = strtotime($record['updated_at'] ?? 'now');

        return [
            'id' => (int) $record['id'],
            'title' => $record['title'],
            'description' => $record['description'] ?? '',
            'category_id' => $categoryId,
            'tag_id' => $tagIds,
            'price' => (float) ($record['price'] ?? 0),
            'player_count_min' => (int) ($record['player_count_min'] ?? 1),
            'player_count_max' => (int) ($record['player_count_max'] ?? 4),
            'play_time_minutes' => (int) ($record['play_time_minutes'] ?? 60),
            'publisher' => $record['publisher'] ?? '',
            'designer' => $record['designer'] ?? '',
            'release_year' => (int) ($record['release_year'] ?? date('Y')),
            'image_url' => $record['image_url'] ?? '',
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    private function mapSingle(string $value, array $labelLookup): int
    {
        $label = $this->extractPrimaryLabel($value);
        $key = $this->normalizeLabelKey($label);
        if ($key !== '' && isset($labelLookup[$key])) {
            return (int) $labelLookup[$key];
        }
        return 0;
    }

    private function mapMulti(string $value, array $labelLookup): array
    {
        $result = [];
        foreach ($this->extractLabels($value) as $label) {
            $key = $this->normalizeLabelKey($label);
            if (!isset($labelLookup[$key])) {
                continue;
            }
            $result[] = (int) $labelLookup[$key];
        }
        return array_values(array_unique($result));
    }

    private function extractPrimaryLabel(string $value): string
    {
        $labels = $this->extractLabels($value);
        return $labels[0] ?? '';
    }

    private function extractLabels(string $value): array
    {
        $parts = preg_split('/[|,]/', $value) ?: [];
        $result = [];
        foreach ($parts as $part) {
            $label = $this->toCanonicalLabel((string) $part);
            if ($label === '') {
                continue;
            }
            $result[] = $label;
        }
        return array_values(array_unique($result));
    }

    private function normalizeLabelKey(string $raw): string
    {
        $label = strtolower(trim($raw));
        $label = preg_replace('/[\-_]+/', ' ', $label) ?? '';
        $label = preg_replace('/\s+/', ' ', $label) ?? '';
        $label = preg_replace('/[^a-z0-9 ]+/', '', $label) ?? '';
        return trim($label);
    }

    private function toCanonicalLabel(string $raw): string
    {
        $key = $this->normalizeLabelKey($raw);
        if ($key === '') {
            return '';
        }
        return ucwords($key);
    }

    private function refreshTaxonomyLookups(): void
    {
        $categoryTableName = $this->resolveTaxonomyTableName('categories');
        $tagTableName = $this->resolveTaxonomyTableName('tags');
        $this->indexConfig['categories_map'] = $this->loadTaxonomyMap($categoryTableName);
        $this->indexConfig['tags_map'] = $this->loadTaxonomyMap($tagTableName);
        $this->indexConfig['categories_lookup'] = $this->buildLabelLookup($this->indexConfig['categories_map']);
        $this->indexConfig['tags_lookup'] = $this->buildLabelLookup($this->indexConfig['tags_map']);
    }

}
