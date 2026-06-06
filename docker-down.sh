#!/bin/sh
set -e

docker rm -f pepo-app pepo-phpmyadmin pepo-db >/dev/null 2>&1 || true

echo "PEPO containers stopped."
