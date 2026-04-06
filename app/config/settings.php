<?php

return [
    'app' => [
        'name' => 'Manticore Catalog Demo',
        'host' => getenv('APP_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('APP_PORT') ?: 8081),
    ],
    'paths' => [
        'root' => dirname(__DIR__),
        'templates' => dirname(__DIR__) . '/templates',
        'storage' => dirname(__DIR__) . '/storage',
        'logs' => dirname(__DIR__) . '/storage/logs',
    ],
    'manticore' => [
        'host' => getenv('MANTICORE_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('MANTICORE_PORT') ?: 9308),
        'transport' => 'Http',
    ],
    'table' => [
        'name' => 'catalog_board_games',
        'columns' => [
            'title' => ['type' => 'string'],
            'description' => ['type' => 'text', 'options' => ['indexed', 'stored']],
            'category_id' => ['type' => 'integer'],
            'tag_id' => ['type' => 'multi64'],
            'price' => ['type' => 'float'],
            'player_count_min' => ['type' => 'integer'],
            'player_count_max' => ['type' => 'integer'],
            'play_time_minutes' => ['type' => 'integer'],
            'publisher' => ['type' => 'string'],
            'designer' => ['type' => 'string'],
            'release_year' => ['type' => 'integer'],
            'image_url' => ['type' => 'string'],
            'created_at' => ['type' => 'timestamp'],
            'updated_at' => ['type' => 'timestamp'],
            'description_vector' => [
                'type' => 'float_vector',
                'options' => [
                    'KNN_TYPE' => 'hnsw',
                    'HNSW_SIMILARITY' => 'l2',
                    'MODEL_NAME' => 'sentence-transformers/all-MiniLM-L6-v2',
                    'FROM' => 'description',
                ],
            ],
        ],
        'settings' => [
            'morphology' => 'stem_en',
            'min_word_len' => 2,
            'dict' => 'keywords',
            'min_infix_len' => 2,
        ],
        'taxonomy_tables' => [
            [
                'name' => 'catalog_categories',
                'columns' => [
                    'label' => ['type' => 'string'],
                ],
                'settings' => [],
            ],
            [
                'name' => 'catalog_tags',
                'columns' => [
                    'label' => ['type' => 'string'],
                ],
                'settings' => [],
            ],
        ],
    ],
    'import' => [
        // Number of records per bulk insert/replace call during fixture import; tune it based on fixture size.
        'batch_size' => 100,
    ],
];
