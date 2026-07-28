.PHONY: up down logs import publish setup db migrate test-db reset

up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f --tail=100

import:
	docker compose exec -T n8n n8n import:credentials --input=/workspace/n8n/credentials/local-mariadb.json
	docker compose exec -T n8n n8n import:workflow --separate --input=/workspace/n8n/workflows

publish:
	docker compose exec -T n8n n8n publish:workflow --id=local-order-intake
	docker compose exec -T n8n n8n publish:workflow --id=local-payment-approval
	docker compose exec -T n8n n8n publish:workflow --id=local-daily-summary
	docker compose restart n8n

setup: up import publish

db:
	docker compose exec mariadb mariadb -uorders_app -porders_dev_password whatsapp_orders

migrate:
	for migration in db/migrations/*.sql; do docker compose exec -T mariadb mariadb -uorders_app -porders_dev_password whatsapp_orders < $$migration; done

test-db:
	docker compose exec -T mariadb mariadb -uorders_app -porders_dev_password whatsapp_orders -e "SELECT sku,name,price,stock FROM products ORDER BY id;"

reset:
	docker compose down -v
