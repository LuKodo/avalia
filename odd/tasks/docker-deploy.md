# docker-deploy

## Objective
Run Avalia (Laravel 12, PHP >= 8.2) on a DigitalOcean droplet with Docker Compose: nginx, php-fpm with required extensions, queue worker and scheduler. The database is a DigitalOcean Managed MySQL (external), so there is NO db container.

## Problem / why
`despliegue/` only targets Windows + XAMPP. The droplet is Linux; user chose Docker for isolation, restart policies and centralized logs.

## Scope (authorized)
Create: `Dockerfile`, `.dockerignore`, `docker-compose.yml`, `docker/nginx/default.conf`, `docker/php/php.ini`, `despliegue/LEEME-DOCKER.md`.
Out of scope (pending, separate decisions): `config/database.php` SSL options for `mysql`/`mysql_admin`, HTTPS/certbot, monitoring stack (Uptime Kuma / Prometheus), any action on the droplet.

## Constraints
- Do not read or edit `.env` or `.env.*`; secrets arrive at runtime via `env_file`, never baked into images.
- Web root is `public/`; nothing else reachable.
- Extensions required: pdo_mysql mbstring openssl fileinfo curl tokenizer xml json ctype filter; recommended zip gd opcache (+ intl).
- Migrations are NOT run automatically (they need the `_admin` connection); documented as a manual `docker compose run --rm` command.
- Comments/docs follow the project convention (Spanish, explaining the why).
- Advisory size heuristic ~400 authored changed lines per task; not a hard cap.

## Test-first exception
No runnable deterministic test applies to infra files. Proportionate checks: `docker compose config`, and `docker build` if Docker is available locally; otherwise static review and say so.

## Tasks
- [ ] T1 Dockerfile multi-stage: node assets build, composer vendor (no-dev), `app` target (php-fpm) and `web` target (nginx with `public/`)
- [ ] T2 `docker/nginx/default.conf` + `docker/php/php.ini`
- [ ] T3 `docker-compose.yml`: web, app, queue, scheduler; named volume for storage; healthchecks; restart policy; no db service
- [ ] T4 `.dockerignore` (exclude .env*, vendor, node_modules, storage contents, despliegue logs)
- [ ] T5 `despliegue/LEEME-DOCKER.md`: build, first run, migrations, logs, update flow

## Route declaration
Delegated direct: one writer. Trigger: 2+ non-trivial files plus reading (instalar.ps1, config/queue.php, composer.lock) that prepares the write.

## Delivery
Project is not a git repository: no branch, no work-unit commits, no native review possible. Recorded as exception; `git init` suggested to the user separately.

## Progress
Created. Writer pending.

## Next step
Launch writer, then spot-check `docker compose config`.
