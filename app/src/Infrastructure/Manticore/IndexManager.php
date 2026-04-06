<?php

declare(strict_types=1);

namespace ManticoreDemo\Infrastructure\Manticore;

use Manticoresearch\Client;
use Manticoresearch\Exceptions\ResponseException;
use Manticoresearch\Table;
use RuntimeException;

class IndexManager
{
    private Table $table;

    public function __construct(
        private readonly Client $client,
        private readonly array $config
    ) {
        $this->table = $this->client->table($this->config['name']);
    }

    public function ensure(): void
    {
        $this->ensureTaxonomyTables();
        if (!$this->exists()) {
            $this->create();
        }
    }

    public function recreate(): void
    {
        $this->dropTaxonomyTables();
        if ($this->exists()) {
            $this->drop();
        }

        $this->create();
        $this->ensureTaxonomyTables();
    }

    private function exists(): bool
    {
        try {
            $this->table->describe();
            return true;
        } catch (ResponseException $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'unknown table') || str_contains($message, 'no such table')) {
                return false;
            }
            throw $e;
        }
    }

    private function create(): void
    {
        $columns = $this->config['columns'] ?? [];
        $settings = $this->config['settings'] ?? [];

        $response = $this->table->create($columns, $settings);
        if (isset($response['error']) && $response['error'] !== '') {
            throw new RuntimeException('Failed to create index: ' . json_encode($response));
        }
    }

    private function drop(): void
    {
        $response = $this->client->tables()->drop([
            'table' => $this->config['name'],
        ]);
        if (isset($response['error']) && $response['error'] !== '') {
            throw new RuntimeException('Failed to drop index: ' . json_encode($response));
        }
    }

    private function ensureTaxonomyTables(): void
    {
        foreach ($this->config['taxonomy_tables'] ?? [] as $tableConfig) {
            $name = (string) ($tableConfig['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (!$this->tableExists($name)) {
                $taxonomyTable = $this->client->table($name);
                $columns = $this->sanitizeColumnsForCreate($tableConfig['columns'] ?? []);
                $response = $taxonomyTable->create($columns, $tableConfig['settings'] ?? [], true);
                if (isset($response['error']) && $response['error'] !== '') {
                    throw new RuntimeException('Failed to create taxonomy table: ' . json_encode($response));
                }
            }
        }
    }

    private function dropTaxonomyTables(): void
    {
        foreach ($this->config['taxonomy_tables'] ?? [] as $tableConfig) {
            $name = (string) ($tableConfig['name'] ?? '');
            if ($name === '' || !$this->tableExists($name)) {
                continue;
            }
            $response = $this->client->tables()->drop(['table' => $name]);
            if (isset($response['error']) && $response['error'] !== '') {
                throw new RuntimeException('Failed to drop taxonomy table: ' . json_encode($response));
            }
        }
    }

    private function tableExists(string $tableName): bool
    {
        $table = $this->client->table($tableName);
        try {
            $table->describe();
            return true;
        } catch (ResponseException $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'unknown table') || str_contains($message, 'no such table')) {
                return false;
            }
            throw $e;
        }
    }

    private function sanitizeColumnsForCreate(array $columns): array
    {
        unset($columns['id']);
        return $columns;
    }
}
