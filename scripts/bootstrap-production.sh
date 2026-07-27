#!/bin/sh
set -eu

compose="docker compose --env-file .env.production -f compose.prod.yaml"

$compose exec -T n8n node /workspace/scripts/render-n8n-mariadb-credential.mjs /tmp/mariadb-credential.json
$compose exec -T n8n n8n import:credentials --input=/tmp/mariadb-credential.json
$compose exec -T n8n n8n import:workflow --input=/workspace/n8n/workflows/01-local-order-intake.json
$compose exec -T n8n n8n import:workflow --input=/workspace/n8n/workflows/02-local-payment-approval.json
$compose exec -T n8n n8n import:workflow --input=/workspace/n8n/workflows/03-local-daily-summary.json
$compose exec -T n8n n8n publish:workflow --id=local-order-intake
$compose exec -T n8n n8n publish:workflow --id=local-payment-approval
$compose exec -T n8n n8n publish:workflow --id=local-daily-summary
$compose restart n8n

echo "n8n preparado. Abre el dominio configurado en .env.production"
