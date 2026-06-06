# PEPO Equipment Pool Operations System

PEPO is a PHP/MariaDB web system for equipment pool operations. It was originally
developed for Windows/XAMPP, but this repository includes Docker files so it can
run on Manjaro/Linux from the terminal.

## Requirements

Install Docker on your system and make sure it is running.

On Manjaro, install Docker Compose if you want to use `docker compose` commands:

```bash
sudo pacman -S --needed docker docker-compose
sudo systemctl enable --now docker
```

If your user cannot run Docker without `sudo`, either run the commands with
`sudo` or add your user to the Docker group:

```bash
sudo usermod -aG docker "$USER"
```

After adding yourself to the Docker group, log out and log back in.

## Run With Plain Docker

This is the easiest path if `docker compose` is not available.

```bash
cd /path/to/capstoneproject
chmod +x docker-up.sh docker-down.sh
./docker-up.sh
```

Open the app:

```text
http://localhost:8080
```

Open phpMyAdmin:

```text
http://localhost:8081
```

Stop the system:

```bash
./docker-down.sh
```

## Run With Docker Compose

If Compose is installed, use:

```bash
cd /path/to/capstoneproject
docker compose up -d --build
```

If your system uses the older standalone command:

```bash
docker-compose up -d --build
```

Stop Compose containers:

```bash
docker compose down
```

## Demo Login Accounts

The Docker database uses the sanitized seed file `user_management.example.sql`.
All demo accounts use this password:

```text
password
```

Available demo emails:

```text
admin@example.test
staff@example.test
supply@example.test
user@example.test
pacco@example.test
gso@example.test
```

## Database

Docker starts MariaDB with:

```text
Database: user_management
User: root
Password: empty
Host inside Docker: db
Host from your computer: 127.0.0.1
Port from your computer: 3307
```

The first startup imports `user_management.example.sql`. After that, data is kept
in a Docker volume.

Reset the database when using the plain Docker scripts:

```bash
./docker-down.sh
docker volume rm pepo_db_data
./docker-up.sh
```

Reset the database when using Compose:

```bash
docker compose down
docker volume rm pepoupdated_db_data
docker compose up -d --build
```

## Useful Terminal Commands

Plain Docker:

```bash
docker logs -f pepo-app
docker logs -f pepo-db
docker exec pepo-app php -v
docker exec pepo-app php -l config.php
```

Docker Compose:

```bash
docker compose logs -f app
docker compose logs -f db
docker compose exec app php -v
docker compose exec app php -l config.php
```

Rebuild CSS:

```bash
npm install
npm run build:css
```

## Configuration

Use `.env.example` as the reference for environment variables. Do not commit a
real `.env` file or real SMTP passwords.

SMTP mail settings are read from environment variables:

```text
SMTP_HOST
SMTP_PORT
SMTP_USERNAME
SMTP_PASSWORD
SMTP_FROM_EMAIL
SMTP_FROM_NAME
APP_BASE_URL
```

More Docker details are in `DOCKER.md`.
