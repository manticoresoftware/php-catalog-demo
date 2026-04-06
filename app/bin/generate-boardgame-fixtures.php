#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generates mock CSV/XML catalog data (board games) plus Stable Diffusion images.
 *
 * Usage:
 *   php bin/generate-boardgame-fixtures.php --count=2000 \
 *        --app-root=/srv/demo/app \
 *        --output-dir=/srv/demo/app/storage/catalog \
 *        --images-dir=/srv/demo/app/public/images/fixtures \
 *        --sd-python=/home/nick/sd-diffusers/.venv/bin/python3
 *
 * Options:
 *   --app-root     Absolute path to the app root (contains public/, storage/, bin/)
 *   --count        Number of items to generate (default: 500)
 *   --output-dir   Directory where CSV/XML files will be written
 *   --images-dir   Directory where generated images will be stored
 *   --sd-python    Python executable with diffusers installed
 *                  (default: /home/nick/sd-diffusers/.venv/bin/python3)
 *   --sd-config    Path to JSON file with SD settings (model/device/steps/size/cfg/seed/chunk/negative_prompt/runner)
 *   --sd-model-id  Hugging Face model id (default: runwayml/stable-diffusion-v1-5)
 *   --sd-device    Device (default: cuda)
 *   --sd-steps     Inference steps (default: 34)
 *   --sd-width     Output width (default: 512)
 *   --sd-height    Output height (default: 512)
 *   --sd-cfg-scale CFG scale (default: 6.8)
 *   --sd-seed      Optional base seed for reproducible runs
 *   --sd-chunk-size Number of images per generation chunk (default: 8)
 *   --sd-runner    Path to sd-image-runner.py (default: <app-root>/bin/sd-image-runner.py)
 *   --split-at     Required split boundary by id; emits part1 (<= id) and part2 (> id)
 *   --generate-images Enable Stable Diffusion image generation (disabled by default)
 *   --keep-images  Keep existing images in images dir before generation
 *   --skip-images  Skip Stable Diffusion image generation and keep images dir untouched
 *   --help, -h     Show this help and exit
 */

const DEFAULT_COUNT = 500;
const DEFAULT_SD_PYTHON = '/home/nick/sd-diffusers/.venv/bin/python3';
const DEFAULT_SD_MODEL = 'runwayml/stable-diffusion-v1-5';

$options = getopt('h', [
	'help',
	'app-root::',
	'count::',
	'output-dir::',
	'images-dir::',
	'sd-python::',
	'sd-config::',
	'sd-model-id::',
	'sd-device::',
	'sd-steps::',
	'sd-width::',
	'sd-height::',
	'sd-cfg-scale::',
	'sd-seed::',
	'sd-chunk-size::',
	'sd-runner::',
	'split-at::',
	'generate-images',
	'keep-images',
	'skip-images',
]);
if (array_key_exists('help', $options) || array_key_exists('h', $options)) {
	printUsage();
	exit(0);
}

$count = isset($options['count']) ? max(1, (int)$options['count']) : DEFAULT_COUNT;
$splitAtRaw = $options['split-at'] ?? null;
$splitAt = isset($splitAtRaw) ? (int)$splitAtRaw : 0;
$appRoot = resolveAppRoot((string)($options['app-root'] ?? (getenv('APP_ROOT') ?: '')));
$outputDir = ensureDir($options['output-dir'] ?? ($appRoot . '/storage/catalog'));
$imagesDir = ensureDir($options['images-dir'] ?? ($appRoot . '/public/images/fixtures'));
$publicRoot = realpath($appRoot . '/public') ?: ($appRoot . '/public');
$generateImages = array_key_exists('generate-images', $options);
$skipImages = !$generateImages;
if (array_key_exists('skip-images', $options)) {
	$skipImages = true;
}
$keepImages = array_key_exists('keep-images', $options);
if (!$skipImages && !$keepImages) {
	clearGeneratedImages($imagesDir);
}
$sdConfig = [
	'python' => (string)($options['sd-python'] ?? DEFAULT_SD_PYTHON),
	'model_id' => DEFAULT_SD_MODEL,
	'device' => 'cuda',
	'steps' => 34,
	'width' => 512,
	'height' => 512,
	'cfg_scale' => 6.8,
	'seed' => null,
	'chunk_size' => 8,
	'runner' => $appRoot . '/bin/sd-image-runner.py',
	'negative_prompt' => 'blurry, low quality, low contrast, washed colors, noisy background, duplicate objects, cropped box, distorted perspective, unreadable text, watermark, logo, deformed',
];
$sdConfigFile = loadSdConfigFile(isset($options['sd-config']) ? (string)$options['sd-config'] : '');
if ($sdConfigFile !== []) {
	$sdConfig = array_replace($sdConfig, $sdConfigFile);
}
if (isset($options['sd-model-id'])) {
	$sdConfig['model_id'] = (string)$options['sd-model-id'];
}
if (isset($options['sd-device'])) {
	$sdConfig['device'] = (string)$options['sd-device'];
}
if (isset($options['sd-steps'])) {
	$sdConfig['steps'] = max(10, (int)$options['sd-steps']);
}
if (isset($options['sd-width'])) {
	$sdConfig['width'] = max(256, (int)$options['sd-width']);
}
if (isset($options['sd-height'])) {
	$sdConfig['height'] = max(256, (int)$options['sd-height']);
}
if (isset($options['sd-cfg-scale'])) {
	$sdConfig['cfg_scale'] = max(1.0, (float)$options['sd-cfg-scale']);
}
if (isset($options['sd-seed'])) {
	$sdConfig['seed'] = (int)$options['sd-seed'];
}
if (isset($options['sd-chunk-size'])) {
	$sdConfig['chunk_size'] = max(1, (int)$options['sd-chunk-size']);
}
if (isset($options['sd-runner'])) {
	$sdConfig['runner'] = (string)$options['sd-runner'];
}

if ($splitAtRaw === null || $splitAt <= 0) {
	fprintf(STDERR, "Missing required --split-at. Use a value between 1 and --count-1.\n");
	exit(1);
}
if ($splitAt >= $count) {
	fprintf(STDERR, "--split-at must be less than --count (received split-at=%d, count=%d)\n", $splitAt, $count);
	exit(1);
}

$records = generateRecords($count, $imagesDir, $publicRoot, $sdConfig, $skipImages, $keepImages);

[$part1Records, $part2Records] = splitRecordsById($records, $splitAt);
[$part2Records, $adjustedRows] = ensurePart2TaxonomySubset($part1Records, $part2Records);
$part1CsvPath = $outputDir . '/boardgames_part1.csv';
$part1XmlPath = $outputDir . '/boardgames_part1.xml';
$part2CsvPath = $outputDir . '/boardgames_part2.csv';
$part2XmlPath = $outputDir . '/boardgames_part2.xml';
writeCsv($part1CsvPath, $part1Records);
writeXml($part1XmlPath, $part1Records);
writeCsv($part2CsvPath, $part2Records);
writeXml($part2XmlPath, $part2Records);

// Clean up legacy full exports if they exist from previous runs.
@unlink($outputDir . '/boardgames.csv');
@unlink($outputDir . '/boardgames.xml');

printf(
	"Generated %d records\nSplit files:\n  part1 (id <= %d): %d rows\n    CSV: %s\n    XML: %s\n  part2 (id > %d): %d rows\n    CSV: %s\n    XML: %s\n  taxonomy-adjusted part2 rows: %d\nImages dir: %s\nImage generation: %s\n",
	$count,
	$splitAt,
	count($part1Records),
	$part1CsvPath,
	$part1XmlPath,
	$splitAt,
	count($part2Records),
	$part2CsvPath,
	$part2XmlPath,
	$adjustedRows,
	$imagesDir,
	$skipImages ? 'skipped' : 'enabled'
);

/**
 * Print CLI help for fixture generation script.
 *
 * @return void
 */
function printUsage(): void {
	printf(
		"Usage:\n"
		. "  php bin/generate-boardgame-fixtures.php [options]\n\n"
		. "Options:\n"
		. "  --app-root=<dir>     Absolute path to app root (contains public/, storage/, bin/)\n"
		. "  --count=<n>          Number of items to generate (default: %d)\n"
		. "  --output-dir=<dir>   Directory where CSV/XML files will be written\n"
		. "  --images-dir=<dir>   Directory where generated images will be stored\n"
		. "  --generate-images    Enable Stable Diffusion generation (default: disabled)\n"
		. "  --skip-images        Skip Stable Diffusion generation and do not clear images dir\n"
		. "  --sd-python=<path>   Python executable with diffusers installed\n"
		. "                       (default: %s)\n"
		. "  --sd-config=<path>   JSON file with SD settings (defaults < config < CLI)\n"
		. "  --sd-model-id=<id>   Hugging Face model id (default: %s)\n"
		. "  --sd-device=<name>   Inference device (default: cuda)\n"
		. "  --sd-steps=<n>       Inference steps (default: 34)\n"
		. "  --sd-width=<n>       Output width (default: 512)\n"
		. "  --sd-height=<n>      Output height (default: 512)\n"
		. "  --sd-cfg-scale=<n>   CFG scale (default: 6.8)\n"
		. "  --sd-seed=<n>        Optional base seed for reproducible runs\n"
		. "  --sd-chunk-size=<n>  Images per generation chunk (default: 8)\n"
		. "  --sd-runner=<path>   Path to sd-image-runner.py (default: <app-root>/bin/sd-image-runner.py)\n"
		. "  --split-at=<id>      Required split boundary by id; writes boardgames_part1/part2 CSV+XML\n"
		. "  --keep-images        Keep existing images in images dir before generation\n"
		. "  --help, -h           Show this help and exit\n",
		DEFAULT_COUNT,
		DEFAULT_SD_PYTHON,
		DEFAULT_SD_MODEL
	);
}

/**
 * @param string $dir
 * @return string
 */
function ensureDir(string $dir): string {
	$dir = rtrim($dir, '/');
	if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
		fprintf(STDERR, "Failed to create directory: %s\n", $dir);
		exit(1);
	}
	return realpath($dir) ?: $dir;
}

function resolveAppRoot(string $raw): string {
	$candidate = trim($raw);
	if ($candidate === '') {
		fprintf(STDERR, "Missing required --app-root (or APP_ROOT env var).\n");
		exit(1);
	}
	$resolved = realpath($candidate);
	if ($resolved === false || !is_dir($resolved)) {
		fprintf(STDERR, "Invalid app root directory: %s\n", $candidate);
		exit(1);
	}
	return rtrim($resolved, '/');
}

/**
 * Load Stable Diffusion generation settings from JSON config file.
 *
 * Supported keys:
 * - model_id, device, steps, width, height, cfg_scale, seed, chunk_size, negative_prompt, runner
 *
 * @return array<string,mixed>
 */
function loadSdConfigFile(string $path): array {
	$path = trim($path);
	if ($path === '') {
		return [];
	}
	$resolved = realpath($path) ?: $path;
	if (!is_file($resolved) || !is_readable($resolved)) {
		fprintf(STDERR, "SD config file not found or unreadable: %s\n", $path);
		exit(1);
	}
	$raw = file_get_contents($resolved);
	if ($raw === false) {
		fprintf(STDERR, "Failed to read SD config file: %s\n", $resolved);
		exit(1);
	}
	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		fprintf(STDERR, "Invalid SD config JSON (must be an object): %s\n", $resolved);
		exit(1);
	}
	$out = [];
	if (isset($decoded['model_id'])) {
		$out['model_id'] = (string)$decoded['model_id'];
	}
	if (isset($decoded['device'])) {
		$out['device'] = (string)$decoded['device'];
	}
	if (isset($decoded['steps'])) {
		$out['steps'] = max(10, (int)$decoded['steps']);
	}
	if (isset($decoded['width'])) {
		$out['width'] = max(256, (int)$decoded['width']);
	}
	if (isset($decoded['height'])) {
		$out['height'] = max(256, (int)$decoded['height']);
	}
	if (isset($decoded['cfg_scale'])) {
		$out['cfg_scale'] = max(1.0, (float)$decoded['cfg_scale']);
	}
	if (array_key_exists('seed', $decoded)) {
		$out['seed'] = $decoded['seed'] === null ? null : (int)$decoded['seed'];
	}
	if (isset($decoded['chunk_size'])) {
		$out['chunk_size'] = max(1, (int)$decoded['chunk_size']);
	}
	if (isset($decoded['negative_prompt'])) {
		$out['negative_prompt'] = (string)$decoded['negative_prompt'];
	}
	if (isset($decoded['runner'])) {
		$out['runner'] = (string)$decoded['runner'];
	}
	return $out;
}

/**
 * Remove previously generated image files from the output directory.
 *
 * @param string $imagesDir
 * @return void
 */
function clearGeneratedImages(string $imagesDir): void {
	$entries = scandir($imagesDir);
	if ($entries === false) {
		fprintf(STDERR, "Failed to read images directory: %s\n", $imagesDir);
		exit(1);
	}

	foreach ($entries as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}
		$path = $imagesDir . '/' . $entry;
		if (!is_file($path)) {
			continue;
		}
		$ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
		if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
			continue;
		}
		if (!unlink($path)) {
			fprintf(STDERR, "Failed to delete image: %s\n", $path);
			exit(1);
		}
	}
}

/**
 * @param int $count
 * @param string $imagesDir
 * @param string $publicRoot
 * @param array<string,mixed> $sdConfig
 * @param bool $skipImages
 * @param bool $keepImages
 * @return array<int,array<string,mixed>>
 */
function generateRecords(
	int $count,
	string $imagesDir,
	string $publicRoot,
	array $sdConfig,
	bool $skipImages = false,
	bool $keepImages = false
): array {
	$records = [];
	$usedTitles = [];
	for ($i = 1; $i <= $count; $i++) {
		$title = generateUniqueTitle($usedTitles, $i);
		$record = fakeBoardGame($i, $imagesDir, $publicRoot, $title);
		$records[] = $record;
	}
	if (!$skipImages) {
		attachImagePrompts($records);
		generateCatalogImages($records, $imagesDir, $publicRoot, $sdConfig, $keepImages);
	}
	return $records;
}

/**
 * @param string $path
 * @param array<int,array<string,mixed>> $records
 * @return void
 */
function writeCsv(string $path, array $records): void {
	$fp = fopen($path, 'w');
	if ($fp === false) {
		fprintf(STDERR, "Unable to write CSV: %s\n", $path);
		exit(1);
	}

	$header = array_keys($records[0]);
	fputcsv($fp, $header);
	foreach ($records as $record) {
		fputcsv($fp, array_map(formatValue(...), $record));
	}
	fclose($fp);
}

/**
 * @param string $path
 * @param array<int,array<string,mixed>> $records
 * @return void
 */
function writeXml(string $path, array $records): void {
	$doc = new DOMDocument('1.0', 'UTF-8');
	$doc->formatOutput = true;
	$root = $doc->createElement('catalog');

	foreach ($records as $record) {
		$item = $doc->createElement('game');
		foreach ($record as $key => $value) {
			$child = $doc->createElement($key);
			$child->appendChild($doc->createTextNode(is_array($value) ? implode(',', $value) : (string)$value));
			$item->appendChild($child);
		}
		$root->appendChild($item);
	}

	$doc->appendChild($root);
	if ($doc->save($path) === false) {
		fprintf(STDERR, "Unable to write XML: %s\n", $path);
		exit(1);
	}
}

/**
 * @param array<int,array<string,mixed>> $records
 * @return array{0: array<int,array<string,mixed>>, 1: array<int,array<string,mixed>>}
 */
function splitRecordsById(array $records, int $splitAt): array {
	$part1 = [];
	$part2 = [];
	foreach ($records as $record) {
		$id = (int)($record['id'] ?? 0);
		if ($id <= $splitAt) {
			$part1[] = $record;
		} else {
			$part2[] = $record;
		}
	}
	return [$part1, $part2];
}

/**
 * Ensure part2 has no unique categories/tags by rewriting unknown values to part1-known values.
 *
 * @param array<int,array<string,mixed>> $part1Records
 * @param array<int,array<string,mixed>> $part2Records
 * @return array{0: array<int,array<string,mixed>>, 1: int}
 */
function ensurePart2TaxonomySubset(array $part1Records, array $part2Records): array {
	$part1CategorySet = [];
	$part1TagSet = [];
	foreach ($part1Records as $record) {
		foreach (parsePipeList((string)($record['categories'] ?? '')) as $category) {
			$part1CategorySet[$category] = true;
		}
		foreach (parsePipeList((string)($record['tags'] ?? '')) as $tag) {
			$part1TagSet[$tag] = true;
		}
	}
	$allowedCategories = array_keys($part1CategorySet);
	$allowedTags = array_keys($part1TagSet);
	if ($allowedCategories === [] || $allowedTags === []) {
		fprintf(STDERR, "Unable to split fixtures: part1 has empty categories/tags taxonomy set.\n");
		exit(1);
	}

	$adjustedRows = 0;
	foreach ($part2Records as $idx => $record) {
		$rowAdjusted = false;
		$categories = parsePipeList((string)($record['categories'] ?? ''));
		$tags = parsePipeList((string)($record['tags'] ?? ''));

		$categories = array_values(array_filter($categories, static fn(string $value): bool => isset($part1CategorySet[$value])));
		if ($categories === []) {
			$categories = [$allowedCategories[0]];
			$rowAdjusted = true;
		}

		$tags = array_values(array_filter($tags, static fn(string $value): bool => isset($part1TagSet[$value])));
		if ($tags === []) {
			$tags = [$allowedTags[0]];
			$rowAdjusted = true;
		}

		$nextCategoryValue = implode('|', $categories);
		$nextTagValue = implode('|', $tags);
		if (((string)($record['categories'] ?? '')) !== $nextCategoryValue || ((string)($record['tags'] ?? '')) !== $nextTagValue) {
			$rowAdjusted = true;
		}
		$record['categories'] = $nextCategoryValue;
		$record['tags'] = $nextTagValue;

		if ($rowAdjusted) {
			$adjustedRows++;
		}
		$part2Records[$idx] = $record;
	}

	return [$part2Records, $adjustedRows];
}

/**
 * @return array<int,string>
 */
function parsePipeList(string $raw): array {
	$parts = explode('|', $raw);
	$normalized = [];
	foreach ($parts as $part) {
		$value = trim($part);
		if ($value === '') {
			continue;
		}
		$normalized[$value] = true;
	}
	return array_keys($normalized);
}

/**
 * @param int $id
 * @param string $imagesDir
 * @param string $publicRoot
 * @param string $title
 * @return array<string,mixed>
 */
function fakeBoardGame(int $id, string $imagesDir, string $publicRoot, string $title): array {
	$categories = fakeCategories();
	$tags = fakeTags();
	$now = time();

	$description = fakeDescription($title, $categories, $tags);

	return [
		'id' => $id,
		'title' => $title,
		'description' => $description,
		'image_prompt' => '',
		'tags' => implode('|', $tags),
		'categories' => implode('|', $categories),
		'price' => number_format(fakePrice(), 2, '.', ''),
		'player_count_min' => rand(1, 4),
		'player_count_max' => rand(5, 10),
		'play_time_minutes' => rand(30, 180),
		'publisher' => fakePublisher(),
		'designer' => fakeDesigner(),
		'release_year' => rand(1990, 2024),
		'image_url' => relativePath(sprintf('%s/game_%04d.png', $imagesDir, $id), $publicRoot),
		'created_at' => date('c', $now - rand(0, 86400 * 365)),
		'updated_at' => date('c', $now),
		'description_vector' => implode(',', generateDescriptionVector($description)),
	];
}

/**
 * Generates a unique title for each item.
 *
 * @param array<string,bool> $usedTitles
 * @param int $id
 * @return string
 */
function generateUniqueTitle(array &$usedTitles, int $id): string {
	for ($attempt = 0; $attempt < 32; $attempt++) {
		$candidate = buildTitleCandidate();
		if (!isset($usedTitles[$candidate])) {
			$usedTitles[$candidate] = true;
			return $candidate;
		}
	}

	// Hard fallback to guarantee uniqueness even when random pairs are exhausted.
	$fallback = sprintf('%s %s %d', fakeAdjective(), fakeNoun(), $id);
	while (isset($usedTitles[$fallback])) {
		$fallback .= ' X';
	}
	$usedTitles[$fallback] = true;
	return $fallback;
}

function buildTitleCandidate(): string {
	$baseTitles = [
		'Heat',
		'Trio',
		'Nana',
		'Tatari',
		'Inis',
		'Scout',
		'Gang of Dice',
		'Azul',
		'My City',
		'Trailblazers',
		'Ichor',
		'Silos',
		'Ego',
		'Zoo Vadis',
		'El Grande',
		'Tigris and Euphrates',
		'Ra',
		'Orbit',
	];
	$joiners = ['of', 'for', 'beyond', 'against', 'under', 'without'];

	$pattern = random_int(1, 4);
	if ($pattern === 1) {
		return sprintf('%s %s', fakeAdjective(), fakeNoun());
	}
	if ($pattern === 2) {
		return sprintf('%s: %s', $baseTitles[array_rand($baseTitles)], fakeNoun());
	}
	if ($pattern === 3) {
		return sprintf('%s %s %s', fakeNoun(), $joiners[array_rand($joiners)], fakeNoun());
	}
	return sprintf('%s %s', $baseTitles[array_rand($baseTitles)], fakeAdjective());
}

/**
 * Adds an image prompt to each record by sampling multiple board game titles
 * from the generated fixture set, so image generation can use rich title mixes.
 *
 * @param array<int,array<string,mixed>> $records
 * @return void
 */
function attachImagePrompts(array &$records): void {
	if ($records === []) {
		return;
	}

	$titles = array_values(array_filter(array_map(
		static fn(array $record): string => trim((string)($record['title'] ?? '')),
		$records
	)));

	if ($titles === []) {
		return;
	}

	foreach ($records as $idx => $record) {
		$currentTitle = trim((string)($record['title'] ?? ''));
		$categories = array_values(array_filter(array_map('trim', explode('|', (string)($record['categories'] ?? '')))));
		$tags = array_values(array_filter(array_map('trim', explode('|', (string)($record['tags'] ?? '')))));
		$recordId = (int)($record['id'] ?? 0);
		$record['image_prompt'] = buildImagePrompt($currentTitle, $titles, $categories, $tags, $recordId);
		$records[$idx] = $record;
	}
}

/**
 * Builds a prompt that mixes several titles, prioritizing the current record title.
 *
 * @param string $primaryTitle
 * @param array<int,string> $titles
 * @param array<int,string> $categories
 * @param array<int,string> $tags
 * @param int $recordId
 * @return string
 */
function buildImagePrompt(string $primaryTitle, array $titles, array $categories, array $tags, int $recordId): string {
	$uniqueTitles = array_values(array_unique(array_filter(array_map('trim', $titles), static fn(string $title): bool => $title !== '')));
	$targetCount = min(5, max(1, count($uniqueTitles)));
	$selected = $primaryTitle !== '' ? [$primaryTitle] : [];
	$pool = array_values(array_filter(
		$uniqueTitles,
		static fn(string $title): bool => $title !== '' && !in_array($title, $selected, true)
	));
	shuffle($pool);
	$need = max(0, $targetCount - count($selected));
	if ($need > 0) {
		$selected = array_merge($selected, array_slice($pool, 0, $need));
	}
	$styles = [
		'cinematic studio lighting, top-down tabletop composition, highly detailed box-art inspired scene',
		'premium product photography aesthetic, vibrant colors, sharp print textures, dramatic shadows',
		'collector shelf showcase style, polished packaging look, rich contrast, ultra-detailed render',
		'board game convention promo poster vibe, clean typography space, glossy materials, high detail',
	];
	$shotTypes = [
		'top-down unboxing flat-lay',
		'three-quarter angle product shot',
		'isometric table diorama',
		'close-up macro composition of components',
		'wide shelf display with open box foreground',
	];
	$environments = [
		'cozy wooden table in a warm home game night setting',
		'modern studio backdrop with soft gradients',
		'moody hobby room with painted miniatures in the background',
		'clean convention booth display with premium lighting rigs',
		'cafe tabletop scene with ambient city evening light',
	];
	$artDirections = [
		'photoreal commercial product photography',
		'stylized editorial board game magazine cover aesthetic',
		'cinematic film still look with subtle grain',
		'ultra-sharp e-commerce catalog look',
		'handcrafted boutique tabletop brand visual language',
	];
	$colorPalettes = [
		'rich jewel-tone palette',
		'high-contrast neon accents with deep shadows',
		'earthy natural tones with brass highlights',
		'pastel modern palette with clean whites',
		'dark academic palette with warm amber lights',
	];
	$componentFocuses = [
		'rulebook, folded board, and organized card decks',
		'miniatures and meeples arranged in tactical formation',
		'dice, tokens, and player boards with score markers',
		'modular tiles and resource trays laid out by type',
		'insert compartments and sorted components in open box view',
	];

	$categorySnippet = $categories !== []
		? sprintf('Theme signals: %s.', implode(', ', array_slice($categories, 0, 2)))
		: '';
	$tagSnippet = $tags !== []
		? sprintf('Mechanic cues: %s.', implode(', ', array_slice($tags, 0, 3)))
		: '';
	$components = sprintf(
		'Include realistic in-box components emphasizing %s, plus cards, tokens, dice, and player aids.',
		$componentFocuses[array_rand($componentFocuses)]
	);
	$variationToken = sprintf('creative variation token V-%04d-%04d', $recordId, random_int(1000, 9999));

	return sprintf(
		'Create a distinct board game promotional visual inspired by: %s. Shot type: %s. Environment: %s. Art direction: %s. Color palette: %s. %s %s %s %s.',
		implode(', ', $selected),
		$shotTypes[array_rand($shotTypes)],
		$environments[array_rand($environments)],
		$artDirections[array_rand($artDirections)],
		$colorPalettes[array_rand($colorPalettes)],
		$styles[array_rand($styles)],
		$components,
		$categorySnippet,
		$tagSnippet,
		$variationToken
	);
}

/**
 * @param array<int,array<string,mixed>> $records
 * @param string $publicRoot
 * @param array<string,mixed> $sdConfig
 * @param bool $keepImages
 * @return void
 */
function generateCatalogImages(
	array &$records,
	string $imagesDir,
	string $publicRoot,
	array $sdConfig,
	bool $keepImages = false
): void {
	$aspectVariants = [
		['width' => 512, 'height' => 512],
		['width' => 768, 'height' => 512],
		['width' => 512, 'height' => 768],
		['width' => 640, 'height' => 640],
	];
	$stylePacks = [
		[
			'name' => 'studio_catalog',
			'directive' => 'Style pack: premium studio catalog. Clean neutral backdrop, controlled softbox highlights, crisp object separation, minimal visual clutter.',
		],
		[
			'name' => 'cozy_tabletop',
			'directive' => 'Style pack: cozy tabletop lifestyle. Warm wood tones, practical lamp lighting, lived-in but tidy arrangement, inviting game-night atmosphere.',
		],
		[
			'name' => 'convention_showcase',
			'directive' => 'Style pack: convention showcase display. Bold signage energy, dramatic edge lighting, high contrast presentation, premium booth styling.',
		],
		[
			'name' => 'editorial_magazine',
			'directive' => 'Style pack: editorial magazine spread. Intentional composition lines, fashion-like lighting ratios, curated prop spacing, high-end print feel.',
		],
	];
	$chunkSize = max(1, (int)($sdConfig['chunk_size'] ?? 8));
	$nextImageSequence = $keepImages ? (findMaxExistingImageSequence($imagesDir) + 1) : null;
	$jobs = [];
	foreach ($records as $idx => $record) {
		$id = (int)($record['id'] ?? 0);
		if ($id <= 0) {
			continue;
		}
		if ($nextImageSequence !== null) {
			$path = sprintf('%s/game_%04d.png', $imagesDir, $nextImageSequence);
			$nextImageSequence++;
		} else {
		$path = sprintf('%s/game_%04d.png', $imagesDir, $id);
		}
		$prompt = (string)($record['image_prompt'] ?? '');
		$jobIndex = count($jobs);
		$chunkIndex = intdiv($jobIndex, $chunkSize);
		$stylePack = $stylePacks[$chunkIndex % count($stylePacks)];
		$prompt = trim($prompt . ' ' . $stylePack['directive']);
		$variant = $aspectVariants[array_rand($aspectVariants)];
		$jobs[] = [
			'id' => $id,
			'path' => $path,
			'prompt' => $prompt,
			'width' => (int)$variant['width'],
			'height' => (int)$variant['height'],
		];

		$record['image_url'] = relativePath($path, $publicRoot);
		$records[$idx] = $record;
	}

	if (!generateStableDiffusionImages($jobs, $sdConfig)) {
		fprintf(STDERR, "Stable Diffusion generation failed.\n");
		exit(1);
	}
}

function findMaxExistingImageSequence(string $imagesDir): int {
	$entries = scandir($imagesDir);
	if ($entries === false) {
		return 0;
	}
	$max = 0;
	foreach ($entries as $entry) {
		if (!preg_match('/^game_(\d+)\.png$/', $entry, $matches)) {
			continue;
		}
		$value = (int)$matches[1];
		if ($value > $max) {
			$max = $value;
		}
	}
	return $max;
}

/**
 * @param array<int,array{id:int,path:string,prompt:string,width:int,height:int}> $jobs
 * @param array<string,mixed> $sdConfig
 * @return bool
 */
function generateStableDiffusionImages(array $jobs, array $sdConfig): bool {
	if ($jobs === []) {
		return true;
	}

	$python = (string)($sdConfig['python'] ?? '');
	if ($python === '' || !is_file($python)) {
		fprintf(STDERR, "Stable Diffusion python executable not found: %s\n", $python);
		return false;
	}
	$runnerFile = (string)($sdConfig['runner'] ?? '');
	if (!is_file($runnerFile)) {
		fprintf(STDERR, "Stable Diffusion runner script not found: %s\n", $runnerFile);
		return false;
	}

	$jobsFile = tempnam(sys_get_temp_dir(), 'sd_jobs_');
	if ($jobsFile === false) {
		return false;
	}

	$chunkSize = max(1, (int)($sdConfig['chunk_size'] ?? 8));
	$baseSeed = $sdConfig['seed'];
	$chunks = array_chunk($jobs, $chunkSize);

	foreach ($chunks as $chunkIndex => $chunkJobs) {
		$chunkSeed = is_int($baseSeed)
			? $baseSeed + ($chunkIndex * 100000)
			: random_int(1, PHP_INT_MAX - 100000);
		$config = [
		'model_id' => (string)($sdConfig['model_id'] ?? DEFAULT_SD_MODEL),
		'device' => (string)($sdConfig['device'] ?? 'cuda'),
		'steps' => (int)($sdConfig['steps'] ?? 26),
		'width' => (int)($sdConfig['width'] ?? 512),
		'height' => (int)($sdConfig['height'] ?? 512),
		'cfg_scale' => (float)($sdConfig['cfg_scale'] ?? 7.0),
		'seed' => $chunkSeed,
		'negative_prompt' => (string)($sdConfig['negative_prompt'] ?? ''),
		'jobs' => $chunkJobs,
	];
		if (file_put_contents($jobsFile, json_encode($config, JSON_UNESCAPED_SLASHES)) === false) {
			@unlink($jobsFile);
			return false;
		}

		$command = [
			$python,
			$runnerFile,
			$jobsFile,
		];
		$cmd = sprintf(
			'%s %s %s 2>&1',
			escapeshellarg($python),
			escapeshellarg($runnerFile),
			escapeshellarg($jobsFile)
		);
		exec($cmd, $output, $exitCode);
		if ($exitCode !== 0) {
			@unlink($jobsFile);
			fprintf(STDERR, "Stable Diffusion runner failed:\n%s\n", implode("\n", $output));
			return false;
		}
	}

	@unlink($jobsFile);

	foreach ($jobs as $job) {
		if (!is_file($job['path']) || filesize($job['path']) === 0) {
			fprintf(STDERR, "Missing generated image: %s\n", $job['path']);
			return false;
		}
	}
	return true;
}

/**
 * @param array<int,array-key,mixed> $record
 */
function formatValue($value) {
	return is_array($value) ? implode('|', $value) : $value;
}

function fakeAdjective(): string {
	$list = [
		'Ancient', 'Galactic', 'Mystic', 'Cunning', 'Bold', 'Hidden', 'Emerald', 'Crimson', 'Arcane', 'Whispering',
		'Sunken', 'Ethereal', 'Obsidian', 'Luminous', 'Ironclad', 'Verdant', 'Astral', 'Forgotten', 'Radiant', 'Shattered',
		'Pedal', 'Metal', 'Celtic', 'Royal', 'Cosmic', 'Strategic', 'Asymmetric', 'Dynamic', 'Legendary', 'Classic',
		'Modern', 'Abstract', 'Negotiation', 'Tactical', 'Risky', 'Competitive', 'Cooperative', 'Drafting', 'Crowned', 'Wily',
		'Alien', 'Mythic', 'Frontier', 'Festival', 'Pacey', 'Thrilling', 'Cutthroat', 'Vibrant', 'Clackety', 'Sharp',
		'Patient', 'Persistent', 'Expressive', 'Ambitious', 'Captivating', 'Epic', 'Stressful', 'Elegant', 'Classy', 'Clever'
	];
	return $list[array_rand($list)];
}

function fakeNoun(): string {
	$list = [
		'Citadel', 'Voyage', 'Alliance', 'Chronicles', 'Expedition', 'Frontier', 'Bazaar', 'Legion', 'Labyrinth', 'Dynasty',
		'Sanctum', 'Caravan', 'Outpost', 'Forge', 'Nebula', 'Harbor', 'Conclave', 'Vault', 'Maze', 'Observatory',
		'Heat', 'Trio', 'Nana', 'Tatari', 'Inis', 'Scout', 'Azul', 'Ichor', 'Silos', 'Ego', 'Orbit', 'Trails', 'Loops',
		'Crown', 'Island', 'Kingdom', 'Arena', 'Peacocks', 'Council', 'Guild', 'Harvest', 'Campaign', 'Championship',
		'Comet', 'Odyssey', 'Monolith', 'Horizon', 'Dominion', 'Concord', 'Reckoning', 'Protocol', 'Echoes', 'Legacy',
		'My City', 'Gang of Dice', 'Zoo Vadis', 'Trailblazers', 'El Grande', 'Tigris and Euphrates'
	];
	return $list[array_rand($list)];
}

function fakeDescription(string $title, array $categories, array $tags): string {
	$categoryPhrase = humanizeList($categories);
	$tagPhrase = humanizeList($tags);
	$highlightCategory = ucfirst($categories[array_rand($categories)]);
	$highlightTag = ucfirst($tags[array_rand($tags)]);
	$sessionMinutes = rand(30, 180);
	$playerRange = sprintf('%d–%d players', rand(1, 2), rand(3, 8));
	$campaignLength = rand(4, 14);
	$relicCount = rand(3, 10);
	$mapTiles = rand(12, 30);
	$legacyPackets = rand(4, 9);
	$chapterCount = rand(3, 7);
	$resourcePressureOptions = ['scarce', 'swingy', 'predictable', 'volatile', 'tight', 'feast-or-famine', 'attritional', 'risk-loaded'];
	$tempoStyleOptions = ['simultaneous planning', 'sequential tactical turns', 'real-time bursts', 'draft-and-resolve rounds', 'initiative bidding', 'programmed actions'];
	$narrativeToneOptions = ['political intrigue', 'expedition survival', 'arcane rivalry', 'merchant drama', 'city-building tension', 'frontier diplomacy', 'guild conspiracies', 'mythic restoration'];
	$audienceOptions = ['newcomer-friendly', 'mid-weight hobby', 'brain-burner', 'campaign-first', 'family-plus strategy', 'expert optimization'];
	$resourcePressure = $resourcePressureOptions[array_rand($resourcePressureOptions)];
	$tempoStyle = $tempoStyleOptions[array_rand($tempoStyleOptions)];
	$narrativeTone = $narrativeToneOptions[array_rand($narrativeToneOptions)];
	$audience = $audienceOptions[array_rand($audienceOptions)];
	$twist = [
		'a hidden objective flips alliances at endgame',
		'the map physically changes every era',
		'discarded cards return as global events',
		'players vote to rewrite one core rule each chapter',
		'a shared market board rewards temporary cooperation',
		'weather tracks alter core action costs between rounds',
		'legacy stickers permanently buff underused strategies',
		'a reputation system unlocks asymmetric late-game powers',
		'neutral factions can be bribed to swing regional control',
		'one public objective mutates whenever milestones are missed',
	][array_rand([0, 1, 2, 3, 4, 5, 6, 7, 8, 9])];

	$openers = [
		sprintf('%s is a %s %s title for %s that leans into %s and %s play.', $title, $audience, $highlightCategory, $playerRange, $tagPhrase, $tempoStyle),
		sprintf('Built around %s systems, %s pushes %s choices across %d linked sessions and rewards tables that enjoy %s.', $categoryPhrase, $title, $highlightTag, $campaignLength, $narrativeTone),
		sprintf('%s blends %s structure with %s chaos, delivering %d-minute arcs where every decision compounds into later chapters.', $title, $highlightCategory, $highlightTag, $sessionMinutes),
		sprintf('At its core, %s is a %s design where %s mechanisms collide with %s pacing for %s groups.', $title, $highlightCategory, $tagPhrase, $tempoStyle, $playerRange),
		sprintf('%s frames %s as a living sandbox: each scenario escalates pressure while %s teams improvise around shifting incentives.', $title, $narrativeTone, $playerRange),
		sprintf('Part campaign and part systems puzzle, %s asks %s players to master %s priorities under %s pressure.', $title, $playerRange, $categoryPhrase, $resourcePressure),
		sprintf('%s opens like a classic %s game, then quickly reveals %s layers that reward long-form table memory.', $title, $highlightCategory, $highlightTag),
		sprintf('Designed for %s tables, %s channels %s into a ruleset that stays readable while outcomes stay unpredictable.', $audience, $title, $narrativeTone),
	];

	$middleA = [
		sprintf('Your group manages %s economies while chasing milestone bonuses; by chapter %d the board can contain over %d modular tiles.', $resourcePressure, $chapterCount, $mapTiles),
		sprintf('Core turns revolve around %s: commit actions, resolve conflicts, then pivot as event decks introduce %d escalating complications.', $tempoStyle, $legacyPackets),
		sprintf('Campaign logs track table history, so failed gambits still unlock content; expect up to %d relic-style upgrades that alter scoring priorities.', $relicCount),
		sprintf('Round structure forces meaningful tradeoffs between tempo and efficiency; over %d chapters, even small resource leaks become strategic liabilities.', $chapterCount),
		sprintf('Economy rails are intentionally unstable: once players trigger threshold events, %d new scoring hooks reshape optimal lines.', $legacyPackets),
		sprintf('By mid-campaign, %d relic slots and rotating objectives create dense interaction where denial play is as valuable as raw points.', $relicCount),
		sprintf('Map states remain highly legible despite scale; by late game, up to %d tiles and layered markers support deep positional planning.', $mapTiles),
		sprintf('Action windows stay short, but consequence chains run long, especially in sessions around %d minutes where adaptation beats scripted play.', $sessionMinutes),
	];

	$middleB = [
		sprintf('A standout mechanic tied to %s means even safe plans can collapse if opponents read your tempo one round ahead.', $highlightTag),
		sprintf('Scenario goals favor adaptive teams: one mission asks for diplomatic efficiency, the next demands aggressive engine conversion under time pressure.'),
		sprintf('Component design supports readability at scale: icon-first cards, layered reference boards, and phase reminders keep late-game turns moving.'),
		sprintf('Because scoring vectors drift over time, tables that over-specialize early often get punished once %s effects begin stacking.', $highlightTag),
		sprintf('Negotiation matters more than it first appears; temporary coalitions routinely form, fracture, and reform inside a single chapter.'),
		sprintf('Hidden information is sparse but impactful, creating tense reveals that reward inference rather than guesswork.'),
		sprintf('Rule overhead stays modest thanks to consistent icon grammar, even while strategic depth rises sharply after the first two plays.'),
		sprintf('Mission cadence alternates between tactical crunch and narrative resolution, preventing decision fatigue while preserving competitive tension.'),
	];

	$closings = [
		sprintf('Only adaptable crews master %s, where %s and long-term planning matter as much as tactical brilliance.', $title, $twist),
		sprintf('If your table likes post-game debriefs, %s will spark them—nearly every finale feels earned, messy, and memorable.', $title),
		sprintf('Expect repeat plays to diverge quickly: opening lines may look familiar, but midgame states rarely resolve the same way twice.'),
		sprintf('Veteran groups report that %s shines brightest after a few sessions, once subtle timing windows and bluff cues become legible.', $title),
		sprintf('Win or lose, %s tends to produce table stories that reference specific gambits and betrayals for weeks afterward.', $title),
		sprintf('If your group values depth with momentum, this one delivers a rare mix of sharp tactical play and evolving campaign texture.'),
		sprintf('The late game stays dramatic without becoming random, making each finale feel like the natural consequence of earlier risks.'),
		sprintf('Few designs blend %s and %s this smoothly while remaining approachable across varied player counts.', $highlightCategory, $highlightTag),
	];

	static $usedDescriptionSignatures = [];
	$sentencePlan = 0;
	$openerIdx = 0;
	$middleAIdx = 0;
	$middleBIdx = 0;
	$closingIdx = 0;
	$signature = '';
	$maxAttempts = 24;

	for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
		$sentencePlan = rand(0, 2);
		$openerIdx = array_rand($openers);
		$middleAIdx = array_rand($middleA);
		$middleBIdx = array_rand($middleB);
		$closingIdx = array_rand($closings);
		$signature = implode(':', [$sentencePlan, $openerIdx, $middleAIdx, $middleBIdx, $closingIdx]);
		if (!isset($usedDescriptionSignatures[$signature])) {
			$usedDescriptionSignatures[$signature] = true;
			break;
		}
		if ($attempt === $maxAttempts - 1) {
			$usedDescriptionSignatures[$signature] = true;
		}
	}

	if ($sentencePlan === 0) {
		return implode(' ', [
			$openers[$openerIdx],
			$middleA[$middleAIdx],
			$closings[$closingIdx],
		]);
	}
	if ($sentencePlan === 1) {
		return implode(' ', [
			$openers[$openerIdx],
			$middleB[$middleBIdx],
			$middleA[$middleAIdx],
			$closings[$closingIdx],
		]);
	}
	return implode(' ', [
		$openers[$openerIdx],
		sprintf('Its %s framework encourages risky pivots, especially once table politics reshape priorities.', $highlightCategory),
		$middleA[$middleAIdx],
		$middleB[$middleBIdx],
		$closings[$closingIdx],
	]);
}

function humanizeList(array $items): string {
	$items = array_map(static fn($item) => ucfirst($item), $items);
	if (count($items) <= 1) {
		return $items[0] ?? '';
	}
	$last = array_pop($items);
	return implode(', ', $items) . ' and ' . $last;
}

function generateDescriptionVector(string $description): array {
	$hash = md5($description, true);
	$vector = [];
	for ($i = 0; $i < 8; $i++) {
		$byte = ord($hash[$i]);
		$next = ord($hash[$i + 8]);
		$value = (($byte << 8) + $next) / 65535;
		$vector[] = round($value * 2 - 1, 4);
	}
	return $vector;
}

function fakeCategories(): array {
	$all = ['Strategy', 'Family', 'Party', 'Co-op', 'Deck Building', 'Eurogame', 'Thematic', 'Abstract'];
	shuffle($all);
	return array_slice($all, 0, 1);
}

function fakeTags(): array {
	$all = ['Dice', 'Cards', 'Miniatures', 'Campaign', 'Solo', 'Legacy', 'Engine Building', 'Tile Laying'];
	shuffle($all);
	return array_slice($all, 0, rand(2, 4));
}

function fakePublisher(): string {
	$list = ['Arcana Works', 'Silver Oak Studio', 'Indigo Forge', 'Nimbus Games', 'Clockwork Labs'];
	return $list[array_rand($list)];
}

function fakeDesigner(): string {
	$list = ['Lena Ortiz', 'Marcus Thorne', 'Akira Flynn', 'Nico Valdez', 'Soraya Kline', 'Elliot Mercer'];
	return $list[array_rand($list)];
}

function fakePrice(): float {
	return rand(2000, 9000) / 100;
}

function relativePath(string $absolute, string $publicRoot): string {
	$real = realpath($absolute) ?: $absolute;
	$resolvedPublicRoot = realpath($publicRoot) ?: $publicRoot;
	if ($resolvedPublicRoot !== '' && str_starts_with($real, $resolvedPublicRoot)) {
		$relative = substr($real, strlen($resolvedPublicRoot));
		return $relative === '' ? '/' : '/'.ltrim($relative, '/');
	}
	if (($pos = strpos($real, '/public/')) !== false) {
		return '/' . ltrim(substr($real, $pos + strlen('/public')), '/');
	}
	if (($pos = strpos($real, '/images/')) !== false) {
		return substr($real, $pos);
	}
	return '/images/fixtures/' . basename($real);
}
