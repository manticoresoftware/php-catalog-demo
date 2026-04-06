.PHONY: up down install serve bootstrap cleanup-uploaded

up:
	docker compose up -d

down:
	docker compose down

install:
	cd app && composer install

serve:
	cd app && php -S localhost:8081 -t public

bootstrap:
	cd app && php bin/bootstrap-demo.php

cleanup-uploaded:
	cd app && php bin/cleanup-demo-uploaded.php
