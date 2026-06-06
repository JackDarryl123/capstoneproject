# PEPO Docker Setup

This setup runs the Windows/XAMPP-style PEPO app on Linux with Docker.

## Requirements

On Manjaro, install Docker Compose if both `docker compose version` and
`docker-compose --version` are missing:

```bash
sudo pacman -S docker-compose
```

Make sure Docker is running and your user can access it.

## Start

```bash
docker compose up -d --build
```

If your install provides the standalone command instead, use:

```bash
docker-compose up -d --build
```

If Compose is not installed yet, use the plain Docker fallback:

```bash
chmod +x docker-up.sh docker-down.sh
./docker-up.sh
```

Stop the fallback containers with:

```bash
./docker-down.sh
```

Open:

- App: http://localhost:8080
- phpMyAdmin: http://localhost:8081
- MariaDB from host tools: `127.0.0.1:3307`

Database defaults:

- Host in Docker: `db`
- Database: `user_management`
- User: `root`
- Password: empty

On first startup, MariaDB imports the sanitized `user_management.example.sql` seed into the
`user_management` database. The database is then kept in the `db_data` Docker volume.

Demo login accounts use the password `password`:

- `admin@example.test`
- `staff@example.test`
- `supply@example.test`
- `user@example.test`
- `pacco@example.test`
- `gso@example.test`

## Reset Database

This deletes the local Docker database and re-imports `user_management.example.sql` on the next start:

```bash
docker compose down
docker volume rm pepoupdated_db_data
docker compose up -d --build
```

## Useful Commands

```bash
docker compose logs -f app
docker compose logs -f db
docker compose exec app php -v
docker compose exec app php phpunit.phar
docker compose exec app php -l config.php
```
