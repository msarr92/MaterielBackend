#!/bin/sh

set -e

mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chmod -R 775 storage bootstrap/cache

php artisan config:clear || true

attempt=1
max_attempts=30

until php -r '
$host = getenv("DB_HOST") ?: "host.docker.internal";
$port = (int) (getenv("DB_PORT") ?: 5432);

$connection = @fsockopen(
    $host,
    $port,
    $errno,
    $errstr,
    2
);

if (!$connection) {
    exit(1);
}

fclose($connection);
'; do
    if [ "$attempt" -ge "$max_attempts" ]; then
        echo "Impossible de joindre PostgreSQL local sur ${DB_HOST:-host.docker.internal}:${DB_PORT:-5432}."
        exit 1
    fi

    echo "Attente de PostgreSQL local... tentative $attempt/$max_attempts"

    attempt=$((attempt + 1))

    sleep 2
done

php artisan migrate --force

php artisan optimize:clear

exec "$@"
