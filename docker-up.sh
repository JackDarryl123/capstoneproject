#!/bin/sh
set -e

APP_IMAGE=pepo-app:local
NETWORK=pepo_network
DB_VOLUME=pepo_db_data
VENDOR_VOLUME=pepo_vendor_data

docker network inspect "$NETWORK" >/dev/null 2>&1 || docker network create "$NETWORK"
docker volume inspect "$DB_VOLUME" >/dev/null 2>&1 || docker volume create "$DB_VOLUME"
docker volume inspect "$VENDOR_VOLUME" >/dev/null 2>&1 || docker volume create "$VENDOR_VOLUME"

docker build -t "$APP_IMAGE" .

docker rm -f pepo-db pepo-app pepo-phpmyadmin >/dev/null 2>&1 || true

docker run -d \
  --name pepo-db \
  --network "$NETWORK" \
  -p 3307:3306 \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  -e MYSQL_DATABASE=user_management \
  -v "$DB_VOLUME":/var/lib/mysql \
  -v "$(pwd)/user_management.example.sql":/docker-entrypoint-initdb.d/01-user-management.sql:ro \
  mariadb:10.4

docker run -d \
  --name pepo-app \
  --network "$NETWORK" \
  -p 8080:80 \
  -e DB_HOST=pepo-db \
  -e DB_USER=root \
  -e DB_PASS= \
  -e DB_NAME=user_management \
  -e APP_INTERNAL_BASE_URL=http://127.0.0.1 \
  -v "$(pwd)":/var/www/html \
  -v "$VENDOR_VOLUME":/var/www/html/vendor \
  "$APP_IMAGE"

docker run -d \
  --name pepo-phpmyadmin \
  --network "$NETWORK" \
  -p 8081:80 \
  -e PMA_HOST=pepo-db \
  -e PMA_USER=root \
  -e PMA_PASSWORD= \
  phpmyadmin:5

echo "PEPO app: http://localhost:8080"
echo "phpMyAdmin: http://localhost:8081"
