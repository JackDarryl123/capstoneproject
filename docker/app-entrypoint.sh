#!/bin/sh
set -e

for dir in cache temp_qr uploads users/temp_qr; do
  mkdir -p "/var/www/html/$dir"
  chmod -R 0777 "/var/www/html/$dir" 2>/dev/null || true
done

exec "$@"
