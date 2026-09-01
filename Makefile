.PHONY: up down install serve serve-foreground serve-stop serve-restart serve-status bootstrap cleanup-uploaded

APP_HOST := $(or $(shell grep -E '^APP_HOST=' app/.env 2>/dev/null | cut -d= -f2-),127.0.0.1)
APP_PORT := $(or $(shell grep -E '^APP_PORT=' app/.env 2>/dev/null | cut -d= -f2-),8081)
PID_FILE := app/storage/php-server.pid
LOG_FILE := app/storage/logs/php-server.log

up:
	docker compose up -d

down:
	docker compose down

install:
	cd app && composer install

serve:
	@if [ -f $(PID_FILE) ] && kill -0 $$(cat $(PID_FILE)) 2>/dev/null; then \
		echo "PHP server already running (pid $$(cat $(PID_FILE)))"; \
		exit 1; \
	fi
	mkdir -p app/storage/logs
	@cd app && sh -c 'nohup php -S $(APP_HOST):$(APP_PORT) -t public >> storage/logs/php-server.log 2>&1 & echo $$! > storage/php-server.pid'
	@echo "PHP server started on $(APP_HOST):$(APP_PORT) (pid $$(cat $(PID_FILE)))"

serve-foreground:
	cd app && php -S $(APP_HOST):$(APP_PORT) -t public

serve-stop:
	@if [ -f $(PID_FILE) ] && kill -0 $$(cat $(PID_FILE)) 2>/dev/null; then \
		kill $$(cat $(PID_FILE)); \
		rm -f $(PID_FILE); \
		echo "PHP server stopped"; \
	else \
		rm -f $(PID_FILE); \
		echo "PHP server is not running"; \
	fi

serve-restart: serve-stop serve

serve-status:
	@if [ -f $(PID_FILE) ] && kill -0 $$(cat $(PID_FILE)) 2>/dev/null; then \
		echo "PHP server running (pid $$(cat $(PID_FILE)), $(APP_HOST):$(APP_PORT))"; \
	else \
		rm -f $(PID_FILE); \
		echo "PHP server is not running"; \
	fi

bootstrap:
	cd app && php bin/bootstrap-demo.php

cleanup-uploaded:
	cd app && php bin/cleanup-demo-uploaded.php
