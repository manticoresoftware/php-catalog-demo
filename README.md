# Demo: Catalog App Built With Manticore PHP Client 

**Publicly available demo - [catalog.manticoresearch.com](https://catalog.manticoresearch.com/)**

![Live search in demo](./live_search.gif)

[Blogpost about this project](https://manticoresearch.com/blog/manticore-php-demo/)

## Docker installation

Requirements: Docker and Docker Compose.

```bash
cd <repo-root>
docker compose up -d
```

That single command:

1. Starts Manticore Search
2. Builds the PHP app image (Composer install included)
3. Waits for Manticore to be ready
4. Bootstraps the demo catalog on first start
5. Serves the app on [http://localhost:8081/](http://localhost:8081/)

Both services use `restart: unless-stopped`, so they come back after Docker or host restarts.

### Useful commands

```bash
# Status / logs
docker compose ps
docker compose logs -f app

# Stop / start
docker compose down
docker compose up -d

# Rebuild after code or dependency changes
docker compose up -d --build

# Re-run fixture bootstrap (wipes and reloads demo catalog data)
FORCE_BOOTSTRAP=1 docker compose up -d --force-recreate app
```

### Custom publish port

By default the app is published on host port `8081`. Override if needed:

```bash
APP_PUBLISH_PORT=25560 docker compose up -d
```

Manticore stays on the Compose network (reachable from the app as host `manticore`). To also expose Manticore on the host, uncomment the `ports` entries under `manticore` in `compose.yaml`.

### Detect the app port

```bash
docker compose ps
ss -tlnp | grep -E '8081|25560'
curl -v http://127.0.0.1:8081/
```

## Running the demo

Open:

- http://localhost:8081/

Admin upload panel:

- http://localhost:8081/admin/upload

More application details (fixtures generation, admin reset, etc.) are in [`app/README.md`](./app/README.md).
