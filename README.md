# Demo: Catalog App Built With Manticore PHP Client 

**Publicly available demo - [catalog.manticoresearch.com](https://catalog.manticoresearch.com/)**

![Live search in demo](./live_search.gif)

[Blogpost about this project](https://manticoresearch.com/blog/manticore-php-demo/)

## Docker installation (recommended)

**Option A: Makefile shortcuts**

```bash
cd <repo-root>
make up
cp app/.env.example app/.env
make install
make bootstrap
make serve
make serve-status
make serve-stop
make serve-restart
```

**Option B: shell commands (no Make)**

```bash
cd <repo-root>
docker compose up -d

cp app/.env.example app/.env
cd app
composer install
php bin/bootstrap-demo.php

mkdir -p storage/logs
nohup php -S 127.0.0.1:8081 -t public >> storage/logs/php-server.log 2>&1 &
echo $! > storage/php-server.pid
```

This repository's `compose.yaml` starts the Manticore service in Docker.  
The PHP app itself is run via the built-in PHP server.

`php bin/bootstrap-demo.php` recreates the table and loads demo records from fixtures.

### PHP server management

`make serve` reads `APP_HOST` and `APP_PORT` from `app/.env` (defaults to `127.0.0.1:8081`), writes a PID file to `app/storage/php-server.pid`, and logs to `app/storage/logs/php-server.log`.

Check status without Make:

```bash
kill -0 "$(cat app/storage/php-server.pid)" && echo "running" || echo "not running"
curl http://127.0.0.1:8081/
```

Stop without Make:

```bash
kill "$(cat app/storage/php-server.pid)"
rm -f app/storage/php-server.pid
```

Replace `127.0.0.1:8081` with the values from `app/.env` if you changed them.


## Running the demo locally

To run the demo, open this URL in your browser:

- http://localhost:8081/
