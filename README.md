# Myxa is a tiny, flexible, powerful, and quietly smart PHP Framework

Ultra-light, modern PHP framework built for speed, clarity, and extensibility.
Inspired by nature. Built for developers.

## Docker Setup

The repository includes a PHP 8.4 CLI container and a MySQL container.

### Install Composer dependencies

```bash
docker compose run --rm php composer install
```

### Run unit tests

```bash
docker compose run --rm php composer test:unit
```

### Start containers

```bash
docker compose up -d
```

### Enter running containers

```bash
docker exec -it myxa-php-cli /bin/bash
docker exec -it myxa-mysql /bin/bash
```

MySQL credentials are loaded from:
- `./docker/mysql/.env`

Default host in Docker network:
- `mysql`

Default exposed host port:
- `3306`

On Linux/macOS, build the PHP image with your local UID/GID:

```bash
UID=$(id -u) GID=$(id -g) docker compose build php
```
