#!/bin/sh
set -eu

compose="docker compose --env-file .env.production -f compose.prod.yaml"
workflow="/workspace/n8n/workflows/04-whatsapp-order-intake.json"

$compose exec -T n8n n8n import:workflow --input="$workflow"
$compose exec -T n8n n8n publish:workflow --id=EOdbOslXlvVg04pf
$compose restart n8n

echo "Workflow de WhatsApp actualizado y publicado."
