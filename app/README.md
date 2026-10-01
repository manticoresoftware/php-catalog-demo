# Catalog Demo Application

The recommended way to run this app is Docker Compose from the repository root:

```bash
docker compose up -d
```

See the [root README](../README.md) for install, ports, logs, and restart behavior.

## Requirements (Docker)

- Docker and Docker Compose

## Optional: local PHP tooling

Fixture generation and one-off scripts can still be run on the host if you have PHP 8.1+ and Composer installed.

### Dependency installation (host)

```bash
cd app
composer install
```

### Environment (host)

Compose sets `MANTICORE_HOST` / `MANTICORE_PORT` for the app container. For host-side scripts talking to Compose Manticore:

```bash
cp .env.example .env
```

Default Compose networking uses:

```
MANTICORE_HOST=127.0.0.1
MANTICORE_PORT=9308
```

### Fixtures generation

If you need to regenerate the catalog fixtures:

The generator creates split datasets: `boardgames_part1.*` and `boardgames_part2.*`.
This supports the demo workflow: part1 is the baseline catalog loaded on first container start, while part2 is reserved for admin imports and periodic cleanup/reset.

Use `--split-at=<id>` to set the split boundary (`part1: id <= split-at`, `part2: id > split-at`) and control their relative sizes.

```bash
cd app
php bin/generate-boardgame-fixtures.php \
  --app-root="$(pwd)" \
  --count=500 \
  --split-at=250 \
  --output-dir=storage/catalog
```

Rebuild the app image afterward so the container picks up new fixtures:

```bash
docker compose up -d --build
```

### Image generation

Image generation can be used when building fixture data. Generated files are written to `public/images/fixtures` and referenced by catalog items shown in the UI.

Requirements:

- Python 3.10+ environment
- `torch` and `diffusers` installed in the Python interpreter used by the generator
- A valid SD config file (example: `config/sd-image.json.example`)

Notes:

- This is optional for normal app runtime; required only if you want to make your own fixture images.
- Fixture generation skips images by default. Use `--generate-images` when you want to create/update images.
- Default/recommended mode is GPU inference (`"device": "cuda"`), which requires a CUDA-capable GPU and compatible PyTorch setup.
- You can run on CPU by setting `"device": "cpu"` in your SD config, but this is much slower than CUDA.
- You can point to a specific Python executable via `--sd-python`.
- You can preserve existing generated images with `--keep-images` (new files are appended, not overwritten).

```bash
cd app
php bin/generate-boardgame-fixtures.php \
  --app-root="$(pwd)" \
  --count=500 \
  --split-at=250 \
  --generate-images \
  --output-dir=storage/catalog \
  --images-dir=public/images/fixtures \
  --sd-config=config/sd-image.json.example \
  --sd-python=/path/to/python3/on/your-machine \
  --keep-images
```

### Manual bootstrap / cleanup inside Docker

```bash
# Re-import baseline fixtures (destructive to catalog tables)
docker compose exec app php bin/bootstrap-demo.php

# Cleanup uploaded demo rows (keeps baseline catalog)
docker compose exec app php bin/cleanup-demo-uploaded.php
```

## Demo Search UI

- Full-text search is the default retrieval mode, with optional fuzzy matching for tolerant typo handling.
- Hybrid retrieval is enabled for query searches by combining text + vector retrieval (RRF fusion) on the first page.
- “Show similar” on item pages uses KNN over `description_vector` to return semantically related games.
- Scroll-based pagination powers “Show more games” to keep deep pagination stable without offset drift.
- Autocomplete suggestions appear while typing, and category/tag facets support drill-down navigation.
- Numeric filters (`price`, `release_year`, `play_time_minutes`, `player_count_min/max`) and user-selected sorting are supported.
- Results cards include preview images and link to detail pages with full metadata and similar-item recommendations.

## Admin upload panel

Open http://localhost:8081/admin/upload to import prepared CSV/XML feeds (generated via the fixtures script) into the `catalog_board_games` table.
Use the optional “Reset uploaded data” button to remove demo-uploaded records above the cutoff derived from `storage/catalog/boardgames_part1.csv` (fallback: `boardgames_part1.xml`) while keeping the base catalog intact.
