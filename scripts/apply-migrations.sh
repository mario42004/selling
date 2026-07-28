#!/bin/sh
set -eu

compose="docker compose --env-file .env.production -f compose.prod.yaml"

for migration in db/migrations/*.sql; do
  echo "Aplicando $migration"
  $compose exec -T mariadb sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' < "$migration"
done

echo "Migraciones aplicadas."
