<?php

declare(strict_types=1);

namespace ManticoreDemo\Application\Controllers;

use Manticoresearch\Client;
use Manticoresearch\ResultHit;
use Manticoresearch\ResultSet;
use Manticoresearch\Search;
use Manticoresearch\Table;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;
use Throwable;

class CatalogController
{
    private const SORT_SLOTS = 4;
    private const SORTABLE_FIELDS = ['relevance', 'title', 'price', 'release_year'];
    private const HYBRID_MIN_K = 15;
    private const HYBRID_MAX_K = 30;
    private const HYBRID_MAX_MATCHES_MULTIPLIER = 3;
    private const SIMILAR_KNN_LIMIT = 1000;
    private const SIMILAR_RESULT_LIMIT = 5;
    private const SAFE_SEARCH_ERROR = 'Search is temporarily unavailable. Please try again in a moment.';
    private const SAFE_AUTOCOMPLETE_ERROR = 'Suggestions are temporarily unavailable. Please try again later.';

    private readonly string $tableName;
    private readonly Table $table;
    private readonly array $categoryMap;
    private readonly array $tagMap;

    public function __construct(
        private readonly Twig $view,
        private readonly Client $client,
        private readonly array $settings,
    ) {
        $this->tableName = $settings['table']['name'];
        $this->table = $this->client->table($this->tableName);
        $categoryTableName = $this->resolveTaxonomyTableName('categories');
        $tagTableName = $this->resolveTaxonomyTableName('tags');
        $this->categoryMap = $this->loadTaxonomyMap($categoryTableName);
        $this->tagMap = $this->loadTaxonomyMap($tagTableName);
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

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        $searchQuery = trim((string) ($params['q'] ?? ''));
        $searchLimit = $this->sanitizeLimit($params['limit'] ?? 20);
        $fuzzy = true;
        $sortCriteria = $this->sanitizeSortCriteria($params);
        $sortRows = $this->buildSortRows($sortCriteria);
        $page = $this->sanitizePage($params['page'] ?? 1);
        $scrollToken = $this->sanitizeScrollToken($params['scroll'] ?? null);
        $categoryIds = $this->sanitizeIdList($params['category'] ?? []);
        $tagIds = $this->sanitizeIdList($params['tag'] ?? []);
        $priceMin = $this->sanitizeFloat($params['price_min'] ?? null);
        $priceMax = $this->sanitizeFloat($params['price_max'] ?? null);
        $playTimeMin = $this->sanitizeInt($params['play_time_min'] ?? null);
        $playTimeMax = $this->sanitizeInt($params['play_time_max'] ?? null);
        $playerCountMin = $this->sanitizeInt($params['player_count_min'] ?? null);
        $playerCountMax = $this->sanitizeInt($params['player_count_max'] ?? null);
        $yearMin = $this->sanitizeInt($params['release_year_min'] ?? null);
        $yearMax = $this->sanitizeInt($params['release_year_max'] ?? null);
        $similarText = trim((string) ($params['similar'] ?? ''));
        $searchResults = null;
        $facetCounts = ['categories' => [], 'tags' => []];
        $searchError = null;
        $attributeFilters = [
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'play_time_min' => $playTimeMin,
            'play_time_max' => $playTimeMax,
            'player_count_min' => $playerCountMin,
            'player_count_max' => $playerCountMax,
            'release_year_min' => $yearMin,
            'release_year_max' => $yearMax,
        ];

        try {
            $searchResults = $this->runSearch(
                $searchQuery,
                $searchLimit,
                $fuzzy,
                $sortCriteria,
                $categoryIds,
                $tagIds,
                $page,
                $attributeFilters,
                $scrollToken
            );
            $facetCounts = $this->buildFacetCounts($searchQuery, $attributeFilters, $fuzzy);
            if ($searchResults !== null && (int) ($searchResults['total'] ?? 0) > 0 && $this->areFacetCountsZero($facetCounts)) {
                // Hybrid/fuzzy queries can yield result hits while lexical facet query returns no buckets.
                // Fallback to counts from the currently filtered dataset.
                $facetCounts = $this->buildFacetCounts('', $attributeFilters, false);
            }
        } catch (Throwable) {
            $searchError = self::SAFE_SEARCH_ERROR;
        }

        $baseQuery = $this->buildBaseQuery(
            $searchQuery,
            $searchLimit,
            $fuzzy,
            $sortCriteria,
            $categoryIds,
            $tagIds,
            $page,
            array_merge($attributeFilters, ['similar' => $similarText])
        );
        $categoryNav = $this->attachNavLinks(
            $this->buildNavOptions($this->categoryMap, $categoryIds, $facetCounts['categories']),
            $baseQuery,
            'category',
            $categoryIds
        );
        $tagNav = $this->attachNavLinks(
            $this->buildNavOptions($this->tagMap, $tagIds, $facetCounts['tags']),
            $baseQuery,
            'tag',
            $tagIds
        );
        $pager = $this->buildPager(
            $baseQuery,
            $page,
            (int) ($searchResults['pages'] ?? 1)
        );

        $term = trim((string) ($params['term'] ?? ''));
        $suggestLimit = $this->sanitizeLimit($params['term_limit'] ?? 5);
        $suggestions = null;
        $suggestError = null;
        if ($term !== '') {
            try {
                $suggestions = $this->runAutocomplete($term, $suggestLimit);
            } catch (Throwable) {
                $suggestError = self::SAFE_AUTOCOMPLETE_ERROR;
            }
        }

        return $this->view->render($response, 'home.twig', [
            'app' => $this->settings['app'],
            'env' => [
                'host' => $this->settings['manticore']['host'],
                'port' => $this->settings['manticore']['port'],
            ],
            'menu' => $this->primaryNav('catalog'),
            'categories' => $categoryNav,
            'category_all_link' => $this->buildUrlWith($baseQuery, ['category' => null, 'page' => 1]),
            'tags' => $tagNav,
            'tag_all_link' => $this->buildUrlWith($baseQuery, ['tag' => null, 'page' => 1]),
            'active_labels' => [
                'categories' => $this->findLabels($categoryNav, $categoryIds),
                'tags' => $this->findLabels($tagNav, $tagIds),
            ],
            'search' => [
                'query' => $searchQuery,
                'limit' => $searchLimit,
                'min_query_len' => max(1, (int) ($this->settings['table']['settings']['min_infix_len'] ?? 2)),
                'fuzzy' => $fuzzy,
                'sort_rows' => $sortRows,
                'sort_labels' => $this->sortLabelMap(),
                'order_options' => $this->sortDirectionOptions(),
                'results' => $searchResults,
                'error' => $searchError,
                'page' => $page,
                'filters' => [
                    'category' => $categoryIds,
                    'tag' => $tagIds,
                    'price_min' => $priceMin,
                    'price_max' => $priceMax,
                    'play_time_min' => $playTimeMin,
                    'play_time_max' => $playTimeMax,
                    'player_count_min' => $playerCountMin,
                    'player_count_max' => $playerCountMax,
                    'release_year_min' => $yearMin,
                    'release_year_max' => $yearMax,
                    'similar' => $similarText,
                ],
                'links' => [
                    'prev' => $page > 1 ? $this->buildUrlWith($baseQuery, ['page' => $page - 1]) : null,
                    'next' => is_array($searchResults)
                        && array_key_exists('next_scroll', $searchResults)
                        && $searchResults['next_scroll'] !== null
                        ? $this->buildUrlWith($baseQuery, ['page' => $page + 1, 'scroll' => $searchResults['next_scroll']])
                        : null,
                    'clear_category' => $categoryIds !== [] ? $this->buildUrlWith($baseQuery, ['category' => null, 'page' => 1]) : null,
                    'clear_tag' => $tagIds !== [] ? $this->buildUrlWith($baseQuery, ['tag' => null, 'page' => 1]) : null,
                    'clear_price' => ($priceMin !== null || $priceMax !== null)
                        ? $this->buildUrlWith($baseQuery, ['price_min' => null, 'price_max' => null, 'page' => 1])
                        : null,
                    'clear_play_time' => ($playTimeMin !== null || $playTimeMax !== null)
                        ? $this->buildUrlWith($baseQuery, ['play_time_min' => null, 'play_time_max' => null, 'page' => 1])
                        : null,
                    'clear_player_count' => ($playerCountMin !== null || $playerCountMax !== null)
                        ? $this->buildUrlWith($baseQuery, [
                            'player_count_min' => null,
                            'player_count_max' => null,
                            'page' => 1,
                        ])
                        : null,
                    'clear_release_year' => ($yearMin !== null || $yearMax !== null)
                        ? $this->buildUrlWith($baseQuery, [
                            'release_year_min' => null,
                            'release_year_max' => null,
                            'page' => 1,
                        ])
                        : null,
                    'clear_similar' => $similarText !== '' ? $this->buildUrlWith($baseQuery, ['similar' => null, 'page' => 1]) : null,
                ],
                'pager' => $pager,
            ],
            'suggest' => [
                'term' => $term,
                'limit' => $suggestLimit,
                'results' => $suggestions,
                'error' => $suggestError,
            ],
        ]);
    }

    public function searchApi(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $query = trim((string) ($params['q'] ?? ''));
        $limit = $this->sanitizeLimit($params['limit'] ?? 20);
        $fuzzy = true;
        $sortCriteria = $this->sanitizeSortCriteria($params);
        $page = $this->sanitizePage($params['page'] ?? 1);
        $scrollToken = $this->sanitizeScrollToken($params['scroll'] ?? null);
        $categoryIds = $this->sanitizeIdList($params['category'] ?? []);
        $tagIds = $this->sanitizeIdList($params['tag'] ?? []);

        try {
            $results = $this->runSearch($query, $limit, $fuzzy, $sortCriteria, $categoryIds, $tagIds, $page, [], $scrollToken);
            return $this->json($response, [
                'query' => $query,
                'limit' => $limit,
                'fuzzy' => $fuzzy,
                'hybrid' => (bool) ($results['hybrid'] ?? false),
                'sort' => $sortCriteria,
                'page' => $page,
                'scroll' => $results['next_scroll'] ?? null,
                'filters' => [
                    'category' => $categoryIds,
                    'tag' => $tagIds,
                ],
            'results' => $results,
            ]);
        } catch (Throwable) {
            return $this->json($response, ['error' => self::SAFE_SEARCH_ERROR], 500);
        }
    }

    public function autocompleteApi(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $term = trim((string) ($params['term'] ?? ''));
        if ($term === '') {
            return $this->json($response, ['error' => 'Parameter term is required'], 400);
        }
        $limit = $this->sanitizeLimit($params['limit'] ?? 5);

        try {
            $result = $this->runAutocomplete($term, $limit);
            return $this->json($response, [
                'term' => $term,
                'limit' => $limit,
                'suggestions' => $result,
            ]);
        } catch (Throwable) {
            return $this->json($response, ['error' => self::SAFE_AUTOCOMPLETE_ERROR], 500);
        }
    }

    public function item(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $hit = $this->table->getDocumentById($id);
        if (!$hit) {
            throw new HttpNotFoundException($request, 'Item not found');
        }

        $showSimilar = isset($request->getQueryParams()['show_similar']);
        $similar = $showSimilar ? $this->findSimilarItems($hit) : [];

        return $this->view->render($response, 'item.twig', [
            'app' => $this->settings['app'],
            'env' => [
                'host' => $this->settings['manticore']['host'],
                'port' => $this->settings['manticore']['port'],
            ],
            'menu' => $this->primaryNav('catalog'),
            'item' => $this->formatHit($hit),
            'similar' => $similar,
            'show_similar' => $showSimilar,
        ]);
    }

    private function runSearch(
        string $query,
        int $limit,
        bool $fuzzy,
        array $sortCriteria,
        array $categoryIds,
        array $tagIds,
        int $page,
        array $attributeFilters,
        ?string $scrollToken = null
    ): array {
        $startedAtNs = hrtime(true);
        // Keep hybrid enabled for all query pages, including scroll continuation.
        $useHybrid = $query !== '';

        $search = new Search($this->client);
        $search->setTable($this->tableName)
            ->limit($limit);

        if ($query !== '') {
            if ($fuzzy) {
                $search->search($query);
                $search->option('fuzzy', 1)
                	->option('force_bigrams', 1);
            } else {
                $search->search($query);
            }
        } else {
            $search->search('*');
        }

        if ($categoryIds !== []) {
            $search->filter('category_id', 'in', $categoryIds);
        }

        if ($tagIds !== []) {
            $search->filter('tag_id', 'in', $tagIds);
        }

        $this->applyNumericFilters($search, $attributeFilters);
        if ($page === 1) {
            $this->applySort($search, $sortCriteria, $query, $useHybrid);
        } else {
            // For continuation pages, keep deterministic id-only ordering.
            $search->sort('id', 'asc');
        }
        if ($page === 1) {
            $search->facet('category_id')
                ->facet('tag_id');
        }
        // Never reuse incoming scroll token on the first page; start a fresh scroll session.
        $effectiveScrollToken = $page > 1 ? $scrollToken : null;
        $search->option('scroll', $effectiveScrollToken ?? true);

        $body = $search->compile();
        if ($useHybrid) {
            unset($body['aggs']);
            // Hybrid search: BM25 + KNN fused via Reciprocal Rank Fusion (RRF).
            // Keep compiled query intact so active filters remain applied in hybrid mode.
            $body['knn'] = [
                'field' => 'description_vector',
                // Let Manticore auto-embed the query string using table model settings.
                'query' => $query,
            ];
            $knnFilter = $this->buildKnnFilterPayload($categoryIds, $tagIds, $attributeFilters);
            if ($knnFilter !== null) {
                $body['knn']['filter'] = $knnFilter;
            }
            $body['options']['fusion_method'] = 'rrf';
        }

        $resultSet = new ResultSet($this->client->search(['body' => $body], true));
        $results = $this->formatResultSet($resultSet);
        if ($page > 1) {
            $results['facet_counts'] = [
                'categories' => [],
                'tags' => [],
            ];
        } else {
            $facets = $resultSet->getFacets();
            $results['facet_counts'] = [
                'categories' => $this->mapFacetBucketsToCounts($facets['category_id']['buckets'] ?? [], $this->categoryMap),
                'tags' => $this->mapFacetBucketsToCounts($facets['tag_id']['buckets'] ?? [], $this->tagMap),
            ];
        }

        $results['page'] = $page;
        $results['limit'] = $limit;
        $results['hybrid'] = $useHybrid;
        $nextScroll = $resultSet->getScroll();
        $results['next_scroll'] = is_string($nextScroll) && $nextScroll !== '' ? $nextScroll : null;
        if ($results['next_scroll'] === null && is_scalar($nextScroll) && (string) $nextScroll !== '') {
            $results['next_scroll'] = (string) $nextScroll;
        }
        $results['has_more'] = $results['next_scroll'] !== null;
        $results['pages'] = $results['has_more'] ? $page + 1 : $page;
        $elapsedMs = (int) round((hrtime(true) - $startedAtNs) / 1_000_000);
        if (!isset($results['query_time_ms']) || (int) $results['query_time_ms'] <= 0) {
            $results['query_time_ms'] = $elapsedMs;
        }

        return $results;
    }

    private function applySort(Search $search, array $sortCriteria, string $query, bool $useHybrid = false): void
    {
        foreach ($sortCriteria as $criterion) {
            $sortBy = (string) ($criterion['field'] ?? '');
            $sortDirection = $this->sanitizeSortDirection($criterion['order'] ?? 'desc');
            switch ($sortBy) {
                case 'title':
                    $search->sort('title', $sortDirection);
                    break;
                case 'price':
                    $search->sort('price', $sortDirection);
                    break;
                case 'release_year':
                    $search->sort('release_year', $sortDirection);
                    break;
                case 'relevance':
                    if ($query !== '') {
                        if (!$useHybrid) {
                            $search->sort('_score', $sortDirection);
                        }
                    } else {
                        $search->sort('created_at', $sortDirection);
                    }
                    break;
            }
        }

        if (!$sortCriteria) {
            if ($query !== '') {
                $search->sort('_score', 'desc');
            } else {
                $search->sort('created_at', 'desc');
            }
        }
    }

    private function runAutocomplete(string $term, int $limit): array
    {
        $payload = [
            'body' => [
                'query' => $term,
                'table' => $this->tableName,
                'options' => [
                	'limit' => $limit,
                	'force_bigrams' => 1,
                ],
            ],
        ];

        $response = $this->client->autocomplete($payload);
        return $this->normalizeAutocompleteResponse($response);
    }

    private function formatResultSet(ResultSet $resultSet): array
    {
        $hits = [];
        foreach ($resultSet as $hit) {
            $hits[] = $this->formatHit($hit);
        }

        return [
            'total' => $resultSet->getTotal(),
            'count' => count($hits),
            'hits' => $hits,
            'query_time_ms' => (int) $resultSet->getTime(),
        ];
    }

    private function formatHit(ResultHit $hit): array
    {
        $data = $hit->getData();
        return [
            'id' => $hit->getId(),
            'score' => $hit->getScore(),
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'price' => $data['price'] ?? null,
            'categories' => $this->labelFromId((int) ($data['category_id'] ?? 0), $this->categoryMap),
            'tags' => $this->labelsFromIds($data['tag_id'] ?? [], $this->tagMap),
            'publisher' => $data['publisher'] ?? '',
            'designer' => $data['designer'] ?? '',
            'release_year' => $data['release_year'] ?? null,
            'player_count_min' => $data['player_count_min'] ?? null,
            'player_count_max' => $data['player_count_max'] ?? null,
            'play_time_minutes' => $data['play_time_minutes'] ?? null,
            'created_at' => $data['created_at'] ?? null,
            'image_url' => $this->normalizeImagePath($data['image_url'] ?? ''),
        ];
    }

    private function labelFromId(int $id, array $map): string
    {
        if ($id <= 0) {
            return '';
        }
        return (string) ($map[$id] ?? '');
    }

    private function labelsFromIds(mixed $ids, array $map): string
    {
        $idList = is_array($ids) ? $ids : [$ids];
        $labels = [];
        foreach ($idList as $id) {
            $label = $this->labelFromId((int) $id, $map);
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        return implode('|', array_values(array_unique($labels)));
    }

    private function sanitizeLimit(mixed $value): int
    {
        $limit = (int) $value;
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 50) {
            $limit = 50;
        }
        return $limit;
    }

    private function sanitizePage(mixed $value): int
    {
        $page = (int) $value;
        if ($page < 1) {
            $page = 1;
        }
        return $page;
    }

    private function sanitizeScrollToken(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $token = trim($value);
        if ($token === '') {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9+\/=_-]+$/', $token)) {
            return null;
        }
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        $payload = json_decode($decoded, true);
        if ($payload !== null && !is_array($payload)) {
            return null;
        }
        return $token;
    }

    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function normalizeAutocompleteResponse(array $raw): array
    {
        if (isset($raw['hits']['suggestions'])) {
            return $raw['hits']['suggestions'];
        }

        if (isset($raw['suggestions'])) {
            return $raw['suggestions'];
        }

        if (isset($raw[0]['data'])) {
            $items = [];
            foreach ($raw[0]['data'] as $row) {
                $value = $row['query'] ?? (is_string(reset($row)) ? reset($row) : null);
                if (!$value) {
                    continue;
                }
                $items[] = [
                    'suggestion' => $value,
                    'distance' => $row['distance'] ?? null,
                    'docs' => $row['docs'] ?? null,
                ];
            }

            return [
                'suggest' => ['data' => $items],
                'qsuggest' => ['data' => []],
            ];
        }

        return [
            'suggest' => ['data' => []],
            'qsuggest' => ['data' => []],
        ];
    }

    /**
     * @return array<int, array{label:string,href:string,active:bool}>
     */
    private function primaryNav(string $active): array
    {
        return [
            [
                'label' => 'Catalog',
                'href' => '/',
                'active' => $active === 'catalog',
            ],
            [
                'label' => 'Admin Dashboard',
                'href' => '/admin/upload',
                'active' => $active === 'admin',
            ],
        ];
    }

    /**
     * @param array<string,int> $map
     * @param array<string,int> $counts
     * @return array<int, array{label:string,active:bool,count:int,id:int}>
     */
    private function buildNavOptions(array $map, array $activeIds, array $counts = []): array
    {
        $options = [];
        foreach ($map as $id => $label) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $options[] = [
                'label' => (string) $label,
                'id' => $id,
                'active' => in_array($id, $activeIds, true),
                'count' => (int) ($counts[(int) $id] ?? 0),
            ];
        }

        return $options;
    }

    private function buildBaseQuery(
        string $query,
        int $limit,
        bool $fuzzy,
        array $sortCriteria,
        array $categories,
        array $tags,
        int $page,
        array $attributeFilters
    ): array {
        $params = [
            'q' => $query,
            'limit' => $limit,
            'page' => $page,
        ];
        if ($fuzzy) {
            $params['fuzzy'] = 1;
        }
        if ($sortCriteria) {
            $params['sort'] = array_values(array_map(
                static fn(array $row): string => (string) ($row['field'] ?? ''),
                $sortCriteria
            ));
            $params['order_by_field'] = [];
            foreach ($sortCriteria as $row) {
                $field = (string) ($row['field'] ?? '');
                if ($field === '') {
                    continue;
                }
                $params['order_by_field'][$field] = $this->sanitizeSortDirection($row['order'] ?? 'desc');
            }
        }
        if ($categories !== []) {
            $params['category'] = array_values($categories);
        }
        if ($tags !== []) {
            $params['tag'] = array_values($tags);
        }
        foreach ($attributeFilters as $key => $value) {
            if ($value !== null && $value !== '') {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * @return array<string,string>
     */
    private function sortLabelMap(): array
    {
        return [
            'relevance' => 'Relevance',
            'title' => 'Title',
            'price' => 'Price',
            'release_year' => 'Release year',
        ];
    }

    /**
     * @return array<int, array{value:string,label:string}>
     */
    private function sortDirectionOptions(): array
    {
        return [
            ['value' => 'asc', 'label' => 'Ascending'],
            ['value' => 'desc', 'label' => 'Descending'],
        ];
    }

    private function sanitizeSortDirection(mixed $value): string
    {
        $direction = strtolower(trim((string) $value));
        if (!in_array($direction, ['asc', 'desc'], true)) {
            return 'desc';
        }
        return $direction;
    }

    private function buildFuzzyPhraseQuery(string $query): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $query) ?? '');
        if ($normalized === '') {
            return '""';
        }

        // Escape phrase delimiter and backslash for query-string phrase syntax.
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $normalized);
        return '"' . $escaped . '"';
    }

    /**
     * @return array<int, array{field:string,order:string}>
     */
    private function sanitizeSortCriteria(array $params): array
    {
        $criteria = [];

        // Preferred format from draggable table form.
        if (isset($params['sort']) && is_array($params['sort'])) {
            $sortFields = $params['sort'];
            $sortOrders = isset($params['order']) && is_array($params['order']) ? $params['order'] : [];
            $sortOrdersByField = isset($params['order_by_field']) && is_array($params['order_by_field'])
                ? $params['order_by_field']
                : [];
            foreach ($sortFields as $index => $fieldRaw) {
                $field = strtolower(trim((string) $fieldRaw));
                if ($field === '' || !in_array($field, self::SORTABLE_FIELDS, true)) {
                    continue;
                }
                $orderRaw = $sortOrdersByField[$field] ?? ($sortOrders[$index] ?? 'desc');
                $criteria[] = [
                    'field' => $field,
                    'order' => $this->sanitizeSortDirection($orderRaw),
                ];
            }
        } else {
            // Backward compatibility for legacy indexed params.
            for ($i = 1; $i <= self::SORT_SLOTS; $i++) {
                $field = strtolower(trim((string) ($params['sort' . $i] ?? '')));
                if ($field === '' || !in_array($field, self::SORTABLE_FIELDS, true)) {
                    continue;
                }
                $criteria[] = [
                    'field' => $field,
                    'order' => $this->sanitizeSortDirection($params['order' . $i] ?? 'desc'),
                ];
            }
        }

        // Backward compatibility for old single sort params.
        if (!$criteria) {
            $legacyField = strtolower(trim((string) ($params['sort'] ?? '')));
            if ($legacyField !== '' && in_array($legacyField, self::SORTABLE_FIELDS, true)) {
                $criteria[] = [
                    'field' => $legacyField,
                    'order' => $this->sanitizeSortDirection($params['order'] ?? 'desc'),
                ];
            }
        }

        if (!$criteria) {
            $criteria[] = ['field' => 'relevance', 'order' => 'desc'];
        }

        // Keep unique fields by first occurrence to avoid conflicting duplicates.
        $unique = [];
        $seen = [];
        foreach ($criteria as $criterion) {
            if (isset($seen[$criterion['field']])) {
                continue;
            }
            $seen[$criterion['field']] = true;
            $unique[] = $criterion;
        }

        // Only a single selected sort criterion is supported.
        return array_slice($unique, 0, 1);
    }

    /**
     * @param array<int, array{field:string,order:string}> $criteria
     * @return array<int, array{field:string,order:string}>
     */
    private function buildSortRows(array $criteria): array
    {
        $rows = [];
        foreach ($criteria as $criterion) {
            if (!in_array($criterion['field'], self::SORTABLE_FIELDS, true)) {
                continue;
            }
            $rows[] = [
                'field' => $criterion['field'],
                'order' => $this->sanitizeSortDirection($criterion['order'] ?? 'desc'),
            ];
        }

        if ($rows === []) {
            $rows[] = ['field' => 'relevance', 'order' => 'desc'];
        }

        return array_slice($rows, 0, 1);
    }

    /**
     * @param array<int, array{id:int,label:string,active:bool,count:int}> $options
     */
    private function attachNavLinks(array $options, array $baseQuery, string $param, array $selectedIds): array
    {
        $withLinks = [];
        foreach ($options as $option) {
            $nextSelected = $selectedIds;
            $id = (int) ($option['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (in_array($id, $nextSelected, true)) {
                $nextSelected = array_values(array_filter($nextSelected, static fn(int $value): bool => $value !== $id));
            } else {
                $nextSelected[] = $id;
            }
            $withLinks[] = array_merge($option, [
                'href' => $this->buildUrlWith($baseQuery, [
                    $param => $nextSelected !== [] ? array_values(array_unique($nextSelected)) : null,
                    'page' => 1,
                ]),
            ]);
        }
        return $withLinks;
    }

    private function findLabels(array $options, array $ids): array
    {
        $labels = [];
        $wanted = array_fill_keys($ids, true);
        foreach ($options as $option) {
            $optionId = (int) ($option['id'] ?? 0);
            if ($optionId > 0 && isset($wanted[$optionId])) {
                $labels[] = (string) $option['label'];
            }
        }
        return $labels;
    }

    private function sanitizeIdList(mixed $value): array
    {
        if (!is_array($value)) {
            if (is_string($value) && $value !== '') {
                $value = [$value];
            } else {
                return [];
            }
        }

        $ids = [];
        foreach ($value as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    private function buildUrlWith(array $base, array $overrides): string
    {
        return $this->buildUrl(array_merge($base, $overrides));
    }

    private function buildUrl(array $params): string
    {
        $filtered = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $filtered[$key] = $value;
        }

        if (!$filtered) {
            return '/';
        }

        return '/?' . http_build_query($filtered);
    }

    private function buildPager(array $baseQuery, int $currentPage, int $totalPages): array
    {
        $totalPages = max(1, $totalPages);
        $currentPage = max(1, min($currentPage, $totalPages));

        $window = 2;
        $start = max(1, $currentPage - $window);
        $end = min($totalPages, $currentPage + $window);

        $items = [];
        if ($start > 1) {
            $items[] = [
                'type' => 'page',
                'number' => 1,
                'active' => $currentPage === 1,
                'href' => $this->buildUrlWith($baseQuery, ['page' => 1]),
            ];
            if ($start > 2) {
                $items[] = ['type' => 'ellipsis'];
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            $items[] = [
                'type' => 'page',
                'number' => $i,
                'active' => $i === $currentPage,
                'href' => $this->buildUrlWith($baseQuery, ['page' => $i]),
            ];
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $items[] = ['type' => 'ellipsis'];
            }
            $items[] = [
                'type' => 'page',
                'number' => $totalPages,
                'active' => $currentPage === $totalPages,
                'href' => $this->buildUrlWith($baseQuery, ['page' => $totalPages]),
            ];
        }

        return [
            'current' => $currentPage,
            'pages' => $totalPages,
            'first' => $currentPage > 1 ? $this->buildUrlWith($baseQuery, ['page' => 1]) : null,
            'prev' => $currentPage > 1 ? $this->buildUrlWith($baseQuery, ['page' => $currentPage - 1]) : null,
            'next' => $currentPage < $totalPages ? $this->buildUrlWith($baseQuery, ['page' => $currentPage + 1]) : null,
            'last' => $currentPage < $totalPages ? $this->buildUrlWith($baseQuery, ['page' => $totalPages]) : null,
            'items' => $items,
        ];
    }

    private function applyNumericFilters(Search $search, array $filters): void
    {
        if (isset($filters['price_min'])) {
            $search->filter('price', 'gte', [$filters['price_min']]);
        }
        if (isset($filters['price_max'])) {
            $search->filter('price', 'lte', [$filters['price_max']]);
        }
        if (isset($filters['play_time_min'])) {
            $search->filter('play_time_minutes', 'gte', [$filters['play_time_min']]);
        }
        if (isset($filters['play_time_max'])) {
            $search->filter('play_time_minutes', 'lte', [$filters['play_time_max']]);
        }
        if (isset($filters['player_count_min'])) {
            $search->filter('player_count_min', 'gte', [$filters['player_count_min']]);
        }
        if (isset($filters['player_count_max'])) {
            $search->filter('player_count_max', 'lte', [$filters['player_count_max']]);
        }
        if (isset($filters['release_year_min'])) {
            $search->filter('release_year', 'gte', [$filters['release_year_min']]);
        }
        if (isset($filters['release_year_max'])) {
            $search->filter('release_year', 'lte', [$filters['release_year_max']]);
        }
    }

    private function buildKnnFilterPayload(array $categoryIds, array $tagIds, array $filters): ?array
    {
        $must = [];
        if ($categoryIds !== []) {
            $must[] = ['in' => ['category_id' => array_values($categoryIds)]];
        }
        if ($tagIds !== []) {
            $must[] = ['in' => ['tag_id' => array_values($tagIds)]];
        }
        if (isset($filters['price_min'])) {
            $must[] = ['range' => ['price' => ['gte' => $filters['price_min']]]];
        }
        if (isset($filters['price_max'])) {
            $must[] = ['range' => ['price' => ['lte' => $filters['price_max']]]];
        }
        if (isset($filters['play_time_min'])) {
            $must[] = ['range' => ['play_time_minutes' => ['gte' => $filters['play_time_min']]]];
        }
        if (isset($filters['play_time_max'])) {
            $must[] = ['range' => ['play_time_minutes' => ['lte' => $filters['play_time_max']]]];
        }
        if (isset($filters['player_count_min'])) {
            $must[] = ['range' => ['player_count_min' => ['gte' => $filters['player_count_min']]]];
        }
        if (isset($filters['player_count_max'])) {
            $must[] = ['range' => ['player_count_max' => ['lte' => $filters['player_count_max']]]];
        }
        if (isset($filters['release_year_min'])) {
            $must[] = ['range' => ['release_year' => ['gte' => $filters['release_year_min']]]];
        }
        if (isset($filters['release_year_max'])) {
            $must[] = ['range' => ['release_year' => ['lte' => $filters['release_year_max']]]];
        }

        return $must !== [] ? ['bool' => ['must' => $must]] : null;
    }

    private function areFacetCountsZero(array $facetCounts): bool
    {
        $categoryCounts = $facetCounts['categories'] ?? [];
        $tagCounts = $facetCounts['tags'] ?? [];
        $sum = array_sum(array_map('intval', $categoryCounts)) + array_sum(array_map('intval', $tagCounts));
        return $sum <= 0;
    }

    /**
     * Build category/tag counters for left navigation based on the current search context.
     * Uses dedicated facet queries (names + counts only), without loading hits.
     *
     * @return array{categories:array<int,int>,tags:array<int,int>}
     */
    private function buildFacetCounts(
        string $query,
        array $attributeFilters,
        bool $fuzzy = true
    ): array {
        $search = new Search($this->client);
        $search->setTable($this->tableName)
            ->facet('category_id')
            ->facet('tag_id');

        if ($query !== '') {
            if ($fuzzy) {
                // Keep facet counting query semantics aligned with main result query.
                $search->search($query);
                $search->option('fuzzy', 1);
            } else {
                $search->search($query);
            }
        } else {
            $search->search('*');
        }

        $this->applyNumericFilters($search, $attributeFilters);

        $facets = $search->get()->getFacets();
        $categoryBuckets = $facets['category_id']['buckets'] ?? [];
        $tagBuckets = $facets['tag_id']['buckets'] ?? [];

        $categoryCounts = $this->mapFacetBucketsToCounts($categoryBuckets, $this->categoryMap);
        $tagCounts = $this->mapFacetBucketsToCounts($tagBuckets, $this->tagMap);

        return [
            'categories' => $categoryCounts,
            'tags' => $tagCounts,
        ];
    }

    /**
     * @param array<int,string> $idToLabelMap
     * @return array<int,int>
     */
    private function mapFacetBucketsToCounts(array $buckets, array $idToLabelMap): array
    {
        $counts = array_fill_keys(array_map('intval', array_keys($idToLabelMap)), 0);

        foreach ($buckets as $bucket) {
            $id = (int) ($bucket['key'] ?? 0);
            if (!array_key_exists($id, $counts)) {
                continue;
            }
            $counts[$id] = (int) ($bucket['doc_count'] ?? 0);
        }

        return $counts;
    }

    private function sanitizeFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    private function sanitizeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private function normalizeImagePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $clean = preg_replace('#^/public/#', '/', $path);
        if ($clean !== $path) {
            return $clean ?: '/';
        }
        if (($pos = strpos($path, '/public/')) !== false) {
            return '/' . ltrim(substr($path, $pos + strlen('/public')), '/');
        }
        if (($pos = strpos($path, '/images/')) !== false) {
            return substr($path, $pos);
        }
        return $path;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function findSimilarItems(ResultHit $source): array
    {
        $search = new Search($this->client);
        $search->setTable($this->tableName)
            ->knn('description_vector', $source->getId(), self::SIMILAR_KNN_LIMIT)
            ->notFilter('id', 'in', [$source->getId()])
            ->limit(self::SIMILAR_RESULT_LIMIT);
        $resultSet = $search->get();
        $hits = $this->formatResultSet($resultSet)['hits'];
        return array_slice($hits, 0, self::SIMILAR_RESULT_LIMIT);
    }

}
