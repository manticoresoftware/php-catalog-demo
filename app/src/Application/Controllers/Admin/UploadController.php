<?php

declare(strict_types=1);

namespace ManticoreDemo\Application\Controllers\Admin;

use ManticoreDemo\Domain\Import\CsvImporter;
use Manticoresearch\Client;
use Manticoresearch\Search;
use Manticoresearch\Table;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\UploadedFile;
use Slim\Views\Twig;
use Throwable;

class UploadController
{
    private const PART1_CSV_FILENAME = 'boardgames_part1.csv';
    private const PART1_XML_FILENAME = 'boardgames_part1.xml';
    private const PART2_CSV_FILENAME = 'boardgames_part2.csv';
    private const PART2_XML_FILENAME = 'boardgames_part2.xml';

    private readonly Table $table;
    private readonly string $tableName;
    private readonly array $categoryMap;
    private readonly array $tagMap;
    private readonly array $categoryLookup;
    private readonly array $tagLookup;

    public function __construct(
        private readonly Twig $view,
        private readonly CsvImporter $importer,
        private readonly Client $client,
        private readonly array $settings,
    ) {
        $this->tableName = $settings['table']['name'];
        $this->table = $this->client->table($this->tableName);
        $categoryTableName = $this->resolveTaxonomyTableName('categories');
        $tagTableName = $this->resolveTaxonomyTableName('tags');
        $this->categoryMap = $this->loadTaxonomyMap($categoryTableName);
        $this->tagMap = $this->loadTaxonomyMap($tagTableName);
        $this->categoryLookup = $this->buildLabelLookup($this->categoryMap);
        $this->tagLookup = $this->buildLabelLookup($this->tagMap);
    }

    private function resolveTaxonomyTableName(string $kind): string
    {
        $configured = $this->settings['table']['taxonomy_tables'] ?? [];
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

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return $this->view->render($response, 'admin/upload.twig', [
            'flash' => $flash,
            'prepared_files' => $this->resolvePreparedImportFiles(),
            'base_max_id' => $this->resolveBaseMaxId(),
            'import_batch_size' => max(1, (int) ($this->settings['import']['batch_size'] ?? 100)),
            'manage_items_enabled' => $this->isManageItemsEnabled(),
        ]);
    }

    public function showItems(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->isManageItemsEnabled()) {
            return $this->denyManageItemsAccess($response);
        }

        $query = $request->getQueryParams();
        $itemPage = isset($query['page']) ? max(1, (int) $query['page']) : 1;
        $editId = isset($query['edit_id']) ? (int) $query['edit_id'] : 0;
        $itemSort = $this->normalizeItemSort((string) ($query['sort'] ?? 'created_at'));
        $itemDir = $this->normalizeSortDir((string) ($query['dir'] ?? 'desc'));

        $itemsData = $this->recentItems($itemPage, 15, $itemSort, $itemDir);
        $editItem = $editId > 0 ? $this->findItemById($editId) : null;
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return $this->view->render($response, 'admin/items.twig', [
            'items' => $itemsData['entries'],
            'itemPagination' => $itemsData['pagination'],
            'itemForm' => $editItem ?? $this->emptyItemForm(),
            'flash' => $flash,
            'itemPage' => $itemPage,
            'editing' => $editItem !== null,
            'itemSort' => $itemSort,
            'itemDir' => $itemDir,
        ]);
    }

    public function resetUploadedData(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $baseMaxId = $this->resolveBaseMaxId();

        try {
            $this->table->deleteDocuments([
                'range' => [
                    'id' => ['gt' => $baseMaxId],
                ],
            ]);
        } catch (Throwable) {
            return $this->redirectWithFlash(
                $response,
                '/admin/upload',
                'Failed to reset uploaded data. Please check server logs and try again.',
                'error'
            );
        }

        $this->clearManageItemsEnabled();

        return $this->redirectWithFlash(
            $response,
            '/admin/upload',
            sprintf('Uploaded demo data reset successfully (removed records with id > %d).', $baseMaxId),
            'success'
        );
    }

    private function resolveBaseMaxId(): int
    {
        $paths = [
            ['path' => $this->resolveCatalogFixturePath(self::PART1_CSV_FILENAME), 'type' => 'csv'],
            ['path' => $this->resolveCatalogFixturePath(self::PART1_XML_FILENAME), 'type' => 'xml'],
        ];

        foreach ($paths as $entry) {
            $path = (string) ($entry['path'] ?? '');
            $type = (string) ($entry['type'] ?? '');
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $count = $this->countFixtureRows($path, $type);
            if ($count > 0) {
                return $count;
            }
        }

        return 0;
    }

    private function countFixtureRows(string $path, string $type): int
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

    public function import(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $request->getParsedBody() ?? [];
        $type = strtolower(trim((string) ($data['feed_type'] ?? 'csv')));
        if (!in_array($type, ['csv', 'xml'], true)) {
            return $this->redirectWithFlash($response, '/admin/upload', 'Unsupported fixture type selected.', 'error');
        }

        $preparedFiles = $this->resolvePreparedImportFiles();
        $fileMeta = $preparedFiles[$type] ?? null;
        $path = (string) ($fileMeta['path'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return $this->redirectWithFlash(
                $response,
                '/admin/upload',
                'Selected prepared fixture file is not available on this server.',
                'error'
            );
        }

        try {
            $unknown = $this->importer->findUnknownTaxonomyInFeed($path, $type);
            if (($unknown['categories'] ?? []) !== [] || ($unknown['tags'] ?? []) !== []) {
                $categoryPart = ($unknown['categories'] ?? []) !== []
                    ? 'categories: ' . implode(', ', array_slice($unknown['categories'], 0, 8))
                    : '';
                $tagPart = ($unknown['tags'] ?? []) !== []
                    ? 'tags: ' . implode(', ', array_slice($unknown['tags'], 0, 8))
                    : '';
                $parts = array_values(array_filter([$categoryPart, $tagPart]));
                $message = 'Prepared fixture contains taxonomy values outside the preloaded set';
                if ($parts !== []) {
                    $message .= ' (' . implode('; ', $parts) . ')';
                }
                $message .= '.';
                return $this->redirectWithFlash($response, '/admin/upload', $message, 'error');
        }

            $result = $this->importer->importFile($path, $type, false, true);
            if (($result['status'] ?? '') !== 'ok') {
                return $this->redirectWithFlash(
                    $response,
                    '/admin/upload',
                    (string) ($result['error'] ?? 'Import failed due to a server-side issue.'),
                    'error'
                );
        }
        } catch (Throwable) {
            return $this->redirectWithFlash(
                $response,
                '/admin/upload',
                'Failed to import prepared fixture. Please check server logs and try again.',
                'error'
            );
        }

        $rows = (int) ($result['rows'] ?? 0);
        if ($rows > 0) {
            $this->markManageItemsEnabled();
        }
        return $this->redirectWithFlash(
            $response,
            '/admin/upload',
            sprintf('Prepared %s fixture imported successfully (%d rows).', strtoupper($type), $rows),
            'success'
        );
    }

    public function importStream(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $type = strtolower(trim((string) ($query['type'] ?? 'csv')));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $limit = max(1, (int) ($query['limit'] ?? ($this->settings['import']['batch_size'] ?? 100)));

        if (!in_array($type, ['csv', 'xml'], true)) {
            return $this->json($response, [
                'type' => 'done',
                'status' => 'error',
                'message' => 'Unsupported fixture type selected.',
            ], 400);
        }

        $preparedFiles = $this->resolvePreparedImportFiles();
        $fileMeta = $preparedFiles[$type] ?? null;
        $path = (string) ($fileMeta['path'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return $this->json($response, [
                'type' => 'done',
                'status' => 'error',
                'message' => 'Selected prepared fixture file is not available on this server.',
            ], 400);
        }

        $total = $this->countFixtureRows($path, $type);
        if ($total <= 0) {
            return $this->json($response, [
                'type' => 'done',
                'status' => 'error',
                'message' => 'Prepared fixture has no importable records.',
                'processed' => 0,
                'total' => 0,
            ], 400);
            }

        if ($offset >= $total) {
            return $this->json($response, [
                'type' => 'done',
                'status' => 'ok',
                'processed' => 0,
                'offset' => $offset,
                'next_offset' => $offset,
                'total' => $total,
                'done' => true,
                'message' => 'Import already complete.',
            ]);
        }

        try {
            if ($offset === 0) {
                $unknown = $this->importer->findUnknownTaxonomyInFeed($path, $type);
                if (($unknown['categories'] ?? []) !== [] || ($unknown['tags'] ?? []) !== []) {
                    $categoryPart = ($unknown['categories'] ?? []) !== []
                        ? 'categories: ' . implode(', ', array_slice($unknown['categories'], 0, 8))
                        : '';
                    $tagPart = ($unknown['tags'] ?? []) !== []
                        ? 'tags: ' . implode(', ', array_slice($unknown['tags'], 0, 8))
                        : '';
                    $parts = array_values(array_filter([$categoryPart, $tagPart]));
                    $message = 'Prepared fixture contains taxonomy values outside the preloaded set';
                    if ($parts !== []) {
                        $message .= ' (' . implode('; ', $parts) . ')';
                    }
                    $message .= '.';
                    return $this->json($response, [
                        'type' => 'done',
                        'status' => 'error',
                        'message' => $message,
                        'processed' => 0,
                        'offset' => $offset,
                        'next_offset' => $offset,
                        'total' => $total,
                    ], 400);
                }
            }

            [$batchPath, $batchRows] = $this->buildImportBatchFixture($path, $type, $offset, $limit);
            if ($batchPath === '' || $batchRows <= 0) {
                return $this->json($response, [
                    'type' => 'progress',
                    'status' => 'ok',
                    'processed' => 0,
                    'offset' => $offset,
                    'next_offset' => $offset,
                    'total' => $total,
                    'done' => true,
                    'message' => 'No remaining rows for import.',
                ]);
            }

            $result = $this->importer->importFile(
                $batchPath,
                $type,
                false,
                true
            );
            @unlink($batchPath);

            if (($result['status'] ?? '') !== 'ok') {
                return $this->json($response, [
                    'type' => 'done',
                    'status' => 'error',
                    'message' => (string) ($result['error'] ?? 'Import failed due to a server-side issue.'),
                    'processed' => 0,
                    'offset' => $offset,
                    'next_offset' => $offset,
                    'total' => $total,
                ], 500);
            }
        } catch (Throwable) {
            return $this->json($response, [
                'type' => 'done',
                'status' => 'error',
                'message' => 'Failed to import prepared fixture. Please check server logs and try again.',
                'processed' => 0,
                'offset' => $offset,
                'next_offset' => $offset,
                'total' => $total,
            ], 500);
        }

        $rows = max(0, (int) ($result['rows'] ?? 0));
        $nextOffset = min($total, $offset + $rows);
        $done = $nextOffset >= $total;
        if ($done && $nextOffset > 0) {
            $this->markManageItemsEnabled();
        }
        return $this->json($response, [
            'type' => $done ? 'done' : 'progress',
            'status' => 'ok',
            'rows' => $rows,
            'total' => $total,
            'processed' => $rows,
            'offset' => $offset,
            'next_offset' => $nextOffset,
            'done' => $done,
            'message' => $done
                ? sprintf('Prepared %s fixture imported successfully (%d rows).', strtoupper($type), $nextOffset)
                : null,
        ]);
    }

    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * @return array{0:string,1:int}
     */
    private function buildImportBatchFixture(string $sourcePath, string $type, int $offset, int $limit): array
    {
        if ($type === 'csv') {
            return $this->buildCsvImportBatchFixture($sourcePath, $offset, $limit);
        }
        if ($type === 'xml') {
            return $this->buildXmlImportBatchFixture($sourcePath, $offset, $limit);
            }
        return ['', 0];
    }

    /**
     * @return array{0:string,1:int}
     */
    private function buildCsvImportBatchFixture(string $sourcePath, int $offset, int $limit): array
    {
        $in = @fopen($sourcePath, 'rb');
        if ($in === false) {
            return ['', 0];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'import_csv_batch_');
        if ($tmp === false) {
            fclose($in);
            return ['', 0];
        }
        $out = @fopen($tmp, 'wb');
        if ($out === false) {
            fclose($in);
            @unlink($tmp);
            return ['', 0];
        }

        $header = fgetcsv($in);
        if ($header === false || $header === [null] || $header === []) {
            fclose($in);
            fclose($out);
            @unlink($tmp);
            return ['', 0];
        }
        fputcsv($out, $header);

        $current = 0;
        $written = 0;
        while (($row = fgetcsv($in)) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }
            if ($current < $offset) {
                $current++;
                continue;
            }
            if ($written >= $limit) {
                break;
            }
            fputcsv($out, $row);
            $written++;
            $current++;
        }

        fclose($in);
        fclose($out);
        if ($written <= 0) {
            @unlink($tmp);
            return ['', 0];
        }

        return [$tmp, $written];
    }

    /**
     * @return array{0:string,1:int}
     */
    private function buildXmlImportBatchFixture(string $sourcePath, int $offset, int $limit): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($sourcePath);
        if ($xml === false) {
            libxml_clear_errors();
            return ['', 0];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'import_xml_batch_');
        if ($tmp === false) {
            return ['', 0];
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('catalog');
        $doc->appendChild($root);

        $index = 0;
        $written = 0;
        foreach ($xml->game as $gameNode) {
            if ($index < $offset) {
                $index++;
                continue;
            }
            if ($written >= $limit) {
                break;
            }
            $gameXml = $gameNode->asXML();
            if (!is_string($gameXml) || $gameXml === '') {
                $index++;
                continue;
            }
            $gameDoc = new \DOMDocument('1.0', 'UTF-8');
            if (@$gameDoc->loadXML($gameXml) === false || $gameDoc->documentElement === null) {
                $index++;
                continue;
            }
            $root->appendChild($doc->importNode($gameDoc->documentElement, true));
            $written++;
            $index++;
        }

        if ($written <= 0) {
            @unlink($tmp);
            return ['', 0];
        }

        if ($doc->save($tmp) === false) {
            @unlink($tmp);
            return ['', 0];
        }

        return [$tmp, $written];
    }

    public function downloadPreparedFile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $type = strtolower(trim((string) ($query['type'] ?? 'csv')));
        if (!in_array($type, ['csv', 'xml'], true)) {
            return $this->redirectWithFlash($response, '/admin/upload', 'Unknown fixture type requested.', 'error');
        }

        $preparedFiles = $this->resolvePreparedImportFiles();
        $fileMeta = $preparedFiles[$type] ?? null;
        $path = (string) ($fileMeta['path'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return $this->redirectWithFlash(
                $response,
                '/admin/upload',
                'Prepared fixture file is not available on this server.',
                'error'
            );
        }

        $filename = basename((string) ($fileMeta['name'] ?? basename($path)));
        $safeFilename = str_replace(['"', '\\'], '', $filename);
        $mime = $type === 'xml' ? 'application/xml; charset=UTF-8' : 'text/csv; charset=UTF-8';
        $content = file_get_contents($path);
        if ($content === false) {
            return $this->redirectWithFlash(
                $response,
                '/admin/upload',
                'Failed to read prepared fixture file.',
                'error'
            );
        }

        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeFilename . '"')
            ->withHeader('Content-Length', (string) strlen($content));
    }

    private function resolvePreparedImportFiles(): array
    {
        $csvPath = $this->resolveCatalogFixturePath(self::PART2_CSV_FILENAME);
        $xmlPath = $this->resolveCatalogFixturePath(self::PART2_XML_FILENAME);

        return [
            'csv' => [
                'name' => self::PART2_CSV_FILENAME,
                'path' => $csvPath,
                'exists' => $csvPath !== '' && is_file($csvPath),
                'rows' => ($csvPath !== '' && is_file($csvPath) && is_readable($csvPath))
                    ? $this->countFixtureRows($csvPath, 'csv')
                    : 0,
            ],
            'xml' => [
                'name' => self::PART2_XML_FILENAME,
                'path' => $xmlPath,
                'exists' => $xmlPath !== '' && is_file($xmlPath),
                'rows' => ($xmlPath !== '' && is_file($xmlPath) && is_readable($xmlPath))
                    ? $this->countFixtureRows($xmlPath, 'xml')
                    : 0,
            ],
        ];
    }

    private function resolveCatalogFixturePath(string $filename): string
    {
        $root = (string) ($this->settings['paths']['root'] ?? '');
        if ($root === '') {
            $root = dirname(__DIR__, 4);
        }

        return rtrim($root, '/') . '/storage/catalog/' . ltrim($filename, '/');
    }

    public function saveItem(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->isManageItemsEnabled()) {
            return $this->denyManageItemsAccess($response);
        }

        $data = $request->getParsedBody() ?? [];
        $uploadedFiles = $request->getUploadedFiles();
        $imageFile = $uploadedFiles['image_file'] ?? null;
        $id = isset($data['id']) && $data['id'] !== '' ? (int) $data['id'] : 0;
        $itemPage = max(1, (int) ($data['page'] ?? 1));
        $itemSort = $this->normalizeItemSort((string) ($data['sort'] ?? 'created_at'));
        $itemDir = $this->normalizeSortDir((string) ($data['dir'] ?? 'desc'));
        if ($id > 0 && !$this->isManageableItemId($id)) {
            return $this->redirectWithFlash(
                $response,
                $this->buildItemsPageUrl($itemPage, null, $itemSort, $itemDir),
                'Baseline catalog items are read-only here. Import extra items first and edit only imported records.',
                'error'
            );
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return $this->redirectWithFlash(
                $response,
                $this->buildItemsPageUrl($itemPage, $id > 0 ? $id : null, $itemSort, $itemDir),
                'Title is required.',
                'error'
            );
        }

        try {
            $now = time();
            $createdAt = $id > 0 ? $this->resolveCreatedAt($id) : $now;
            $imageUrl = trim((string) ($data['image_url'] ?? ''));
            if ($imageFile instanceof UploadedFile && $imageFile->getError() === UPLOAD_ERR_OK && $imageFile->getSize() > 0) {
                $imageUrl = $this->storeItemImage($imageFile);
            }
            $document = [
                'title' => $title,
                'description' => trim((string) ($data['description'] ?? '')),
                'category_id' => $this->mapSingleInput((string) ($data['categories_text'] ?? ''), $this->categoryLookup),
                'tag_id' => $this->mapMultiInput((string) ($data['tags_text'] ?? ''), $this->tagLookup),
                'price' => (float) ($data['price'] ?? 0),
                'player_count_min' => max(1, (int) ($data['player_count_min'] ?? 1)),
                'player_count_max' => max(1, (int) ($data['player_count_max'] ?? 4)),
                'play_time_minutes' => max(1, (int) ($data['play_time_minutes'] ?? 60)),
                'publisher' => trim((string) ($data['publisher'] ?? '')),
                'designer' => trim((string) ($data['designer'] ?? '')),
                'release_year' => (int) ($data['release_year'] ?? date('Y')),
                'image_url' => $imageUrl,
                'created_at' => $createdAt,
                'updated_at' => $now,
            ];

            if ($id > 0) {
                $this->table->replaceDocument($document, $id);
                return $this->redirectWithFlash(
                    $response,
                    $this->buildItemsPageUrl($itemPage, $id, $itemSort, $itemDir),
                    'Item updated successfully.',
                    'success'
                );
            }

            $this->table->addDocument($document);
            return $this->redirectWithFlash(
                $response,
                $this->buildItemsPageUrl($itemPage, null, $itemSort, $itemDir),
                'Item added successfully.',
                'success'
            );
        } catch (Throwable) {
            return $this->redirectWithFlash(
                $response,
                $this->buildItemsPageUrl($itemPage, $id > 0 ? $id : null, $itemSort, $itemDir),
                'Failed to save item. Please check server logs and try again.',
                'error'
            );
        }
    }

    public function deleteItem(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->isManageItemsEnabled()) {
            return $this->denyManageItemsAccess($response);
        }

        $data = $request->getParsedBody() ?? [];
        $id = (int) ($data['id'] ?? 0);
        $itemPage = max(1, (int) ($data['page'] ?? 1));
        $itemSort = $this->normalizeItemSort((string) ($data['sort'] ?? 'created_at'));
        $itemDir = $this->normalizeSortDir((string) ($data['dir'] ?? 'desc'));
        if ($id <= 0) {
            return $this->redirectWithFlash(
                $response,
                $this->buildItemsPageUrl($itemPage, null, $itemSort, $itemDir),
                'Invalid item id.',
                'error'
            );
        }
        if (!$this->isManageableItemId($id)) {
            return $this->redirectWithFlash(
                $response,
                $this->buildItemsPageUrl($itemPage, null, $itemSort, $itemDir),
                'Baseline catalog items are read-only here. Only imported records can be removed.',
                'error'
            );
        }

        try {
            $this->table->deleteDocument($id);
            return $this->redirectWithFlash(
                $response,
                $this->buildItemsPageUrl($itemPage, null, $itemSort, $itemDir),
                'Item deleted successfully.',
                'success'
            );
        } catch (Throwable) {
            return $this->redirectWithFlash(
                $response,
                $this->buildItemsPageUrl($itemPage, null, $itemSort, $itemDir),
                'Failed to delete item. Please check server logs and try again.',
                'error'
            );
        }
    }

    private function recentItems(
        int $page = 1,
        int $perPage = 15,
        string $sort = 'created_at',
        string $dir = 'desc'
    ): array
    {
        $baseMaxId = $this->resolveBaseMaxId();
        $sort = $this->normalizeItemSort($sort);
        $dir = $this->normalizeSortDir($dir);
        $search = new Search($this->client);
        $search->setTable($this->tableName)
            ->search('*')
            ->filter('id', 'gt', [$baseMaxId])
            ->sort($sort, $dir)
            ->limit($perPage)
            ->offset(($page - 1) * $perPage);

        $resultSet = $search->get();
        $entries = [];
        foreach ($resultSet as $hit) {
            $entries[] = $this->formatItemHit($hit);
        }
        $total = (int) $resultSet->getTotal();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        return [
            'entries' => $entries,
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'per_page' => $perPage,
                'total' => $total,
                'has_prev' => $page > 1,
                'has_next' => $page < $pages,
            ],
        ];
    }

    private function findItemById(int $id): ?array
    {
        if (!$this->isManageableItemId($id)) {
            return null;
        }

        $hit = $this->table->getDocumentById($id);
        if ($hit === null) {
            return null;
        }
        return $this->formatItemHit($hit);
    }

    private function formatItemHit($hit): array
    {
        $data = $hit->getData();
        return [
            'id' => (int) $hit->getId(),
            'title' => (string) ($data['title'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'categories_text' => $this->labelFromId((int) ($data['category_id'] ?? 0), $this->categoryMap),
            'tags_text' => $this->labelsFromIds($data['tag_id'] ?? [], $this->tagMap),
            'price' => (float) ($data['price'] ?? 0),
            'player_count_min' => (int) ($data['player_count_min'] ?? 1),
            'player_count_max' => (int) ($data['player_count_max'] ?? 4),
            'play_time_minutes' => (int) ($data['play_time_minutes'] ?? 60),
            'publisher' => (string) ($data['publisher'] ?? ''),
            'designer' => (string) ($data['designer'] ?? ''),
            'release_year' => (int) ($data['release_year'] ?? date('Y')),
            'image_url' => (string) ($data['image_url'] ?? ''),
            'created_at' => (int) ($data['created_at'] ?? time()),
            'updated_at' => (int) ($data['updated_at'] ?? time()),
        ];
    }

    private function emptyItemForm(): array
    {
        return [
            'id' => '',
            'title' => '',
            'description' => '',
            'categories_text' => '',
            'tags_text' => '',
            'price' => 0,
            'player_count_min' => 1,
            'player_count_max' => 4,
            'play_time_minutes' => 60,
            'publisher' => '',
            'designer' => '',
            'release_year' => (int) date('Y'),
            'image_url' => '',
        ];
    }

    private function resolveCreatedAt(int $id): int
    {
        $existing = $this->table->getDocumentById($id);
        if ($existing === null) {
            return time();
        }
        $data = $existing->getData();
        return (int) ($data['created_at'] ?? time());
    }

    private function mapSingleInput(string $value, array $mapping): int
    {
        $parts = preg_split('/[|,]/', $value) ?: [];
        foreach ($parts as $part) {
            $key = $this->normalizeLabelKey((string) $part);
            if ($key === '' || !isset($mapping[$key])) {
                continue;
            }
            return (int) $mapping[$key];
        }
        return 0;
    }

    private function mapMultiInput(string $value, array $mapping): array
    {
        $parts = preg_split('/[|,]/', $value) ?: [];
        $result = [];
        foreach ($parts as $part) {
            $key = $this->normalizeLabelKey((string) $part);
            if ($key === '' || !isset($mapping[$key])) {
                continue;
            }
            $result[] = (int) $mapping[$key];
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

    private function labelFromId(int $id, array $mapping): string
    {
        if ($id <= 0) {
            return '';
        }
        return (string) ($mapping[$id] ?? '');
    }

    private function labelsFromIds(mixed $ids, array $mapping): string
    {
        $idList = is_array($ids) ? $ids : [$ids];
        $labels = [];
        foreach ($idList as $id) {
            $label = $this->labelFromId((int) $id, $mapping);
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        return implode('|', array_values(array_unique($labels)));
    }

    private function storeItemImage(UploadedFile $file): string
    {
        $original = $file->getClientFilename() ?? '';
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
        if (!in_array($extension, $allowed, true)) {
            $extension = 'png';
        }

        $uploadDir = rtrim($this->settings['paths']['root'], '/') . '/public/images/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $filename = sprintf('item_%s_%04d.%s', date('Ymd_His'), random_int(0, 9999), $extension);
        $destination = $uploadDir . '/' . $filename;
        $file->moveTo($destination);

        return '/images/uploads/' . $filename;
    }

    private function redirectWithFlash(ResponseInterface $response, string $to, string $message, string $type): ResponseInterface
    {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return $response->withHeader('Location', $to)->withStatus(302);
    }

    private function manageItemsFlagPath(): string
    {
        return rtrim((string) ($this->settings['paths']['storage'] ?? ''), '/') . '/catalog/.part2_imported_once.flag';
    }

    private function isManageItemsEnabled(): bool
    {
        $path = $this->manageItemsFlagPath();
        return $path !== '' && is_file($path);
    }

    private function markManageItemsEnabled(): void
    {
        $path = $this->manageItemsFlagPath();
        if ($path === '') {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($path, (string) time());
    }

    private function clearManageItemsEnabled(): void
    {
        $path = $this->manageItemsFlagPath();
        if ($path === '' || !is_file($path)) {
            return;
        }
        @unlink($path);
    }

    private function denyManageItemsAccess(ResponseInterface $response): ResponseInterface
    {
        return $this->redirectWithFlash(
            $response,
            '/admin/upload',
            'Manage Items is locked until you import extra items from this page.',
            'error'
        );
    }

    private function isManageableItemId(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        return $id > $this->resolveBaseMaxId();
    }

    private function normalizeItemSort(string $sort): string
    {
        $normalized = strtolower(trim($sort));
        return in_array($normalized, ['id', 'created_at'], true) ? $normalized : 'created_at';
    }

    private function normalizeSortDir(string $dir): string
    {
        return strtolower(trim($dir)) === 'asc' ? 'asc' : 'desc';
    }

    private function buildItemsPageUrl(
        int $page,
        ?int $editId = null,
        string $sort = 'created_at',
        string $dir = 'desc'
    ): string
    {
        $params = [
            'page' => max(1, $page),
            'sort' => $this->normalizeItemSort($sort),
            'dir' => $this->normalizeSortDir($dir),
        ];
        if ($editId !== null && $editId > 0) {
            $params['edit_id'] = $editId;
        }

        return '/admin/items?' . http_build_query($params);
    }
}
