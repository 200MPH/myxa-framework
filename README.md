# Myxa is a tiny, flexible, powerful, and quietly smart PHP Framework Powered by AI

Ultra-light, modern PHP framework built for speed, clarity, and extensibility.
Inspired by nature. Built for developers. Powered by AI

## Feature Docs

Focused package documentation lives next to the relevant source folders:

- [Auth](./src/Auth/README.md)
- [Container](./src/Container/README.md)
- [Console](./src/Console/README.md)
- [Database Overview](./src/Database/README.md)
- [Migrations](./src/Database/Migrations/README.md)
- [Schema Builder and Reverse Engineering](./src/Database/Schema/README.md)
- [Query Builder](./src/Database/Query/README.md)
- [Models](./src/Database/Model/README.md)
- [Events](./src/Events/README.md)
- [HTTP](./src/Http/README.md)
- [Logging](./src/Logging/README.md)
- [Middleware](./src/Middleware/README.md)
- [Rate Limiting](./src/RateLimit/README.md)
- [Routing](./src/Routing/README.md)
- [Storage](./src/Storage/README.md)
- [Support and Facades](./src/Support/README.md)

## Docker Setup

The repository includes a PHP 8.4 CLI container and a MySQL container.
It also includes a PostgreSQL container for execution-level database tests.

### Install Composer dependencies

```bash
docker compose run --rm php composer install
```

### Run unit tests

```bash
docker compose run --rm php composer test:unit
```

### Run unit test coverage

The PHP CLI image ships with `PCOV`, a lightweight code coverage driver.

```bash
docker compose build php
docker compose run --rm php composer test:coverage
```

For an HTML report:

```bash
docker compose run --rm php composer test:coverage:html
```

### Start containers

```bash
docker compose up -d
```

### Enter running containers

```bash
docker exec -it myxa-php-cli /bin/bash
docker exec -it myxa-mysql /bin/bash
docker exec -it myxa-postgres /bin/bash
```

MySQL credentials are loaded from:
- `./docker/mysql/.env`

PostgreSQL credentials are loaded from:
- `./docker/postgres/.env`

Default host in Docker network:
- `mysql`
- `postgres`

Default exposed host port:
- `3306`
- `5432`

On Linux/macOS, build the PHP image with your local UID/GID:

```bash
UID=$(id -u) GID=$(id -g) docker compose build php
```
