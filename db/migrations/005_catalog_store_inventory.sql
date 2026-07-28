USE whatsapp_orders;

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER active;

CREATE TABLE IF NOT EXISTS stock_transfers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_store_id BIGINT UNSIGNED NOT NULL,
  to_store_id BIGINT UNSIGNED NOT NULL,
  from_product_id BIGINT UNSIGNED NOT NULL,
  from_variant_id BIGINT UNSIGNED NOT NULL,
  to_product_id BIGINT UNSIGNED NOT NULL,
  to_variant_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  notes VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_stock_transfers_from_store (from_store_id, created_at),
  KEY idx_stock_transfers_to_store (to_store_id, created_at),
  CONSTRAINT fk_stock_transfers_from_store FOREIGN KEY (from_store_id) REFERENCES stores(id),
  CONSTRAINT fk_stock_transfers_to_store FOREIGN KEY (to_store_id) REFERENCES stores(id),
  CONSTRAINT fk_stock_transfers_from_product FOREIGN KEY (from_product_id) REFERENCES products(id),
  CONSTRAINT fk_stock_transfers_from_variant FOREIGN KEY (from_variant_id) REFERENCES product_variants(id),
  CONSTRAINT fk_stock_transfers_to_product FOREIGN KEY (to_product_id) REFERENCES products(id),
  CONSTRAINT fk_stock_transfers_to_variant FOREIGN KEY (to_variant_id) REFERENCES product_variants(id),
  CONSTRAINT fk_stock_transfers_actor FOREIGN KEY (actor_user_id) REFERENCES users(id),
  CONSTRAINT chk_stock_transfers_quantity CHECK (quantity > 0)
) ENGINE=InnoDB;

CREATE OR REPLACE VIEW catalog_available_variants AS
SELECT
  p.id AS product_id,
  p.store_id,
  s.city_id,
  s.zone_id,
  pv.id AS variant_id,
  pv.sku,
  p.name,
  p.description,
  p.category,
  p.type,
  p.brand,
  p.color,
  p.gender,
  pv.size,
  pv.stock,
  pv.reserved_stock,
  COALESCE(p.sale_price, p.price) AS price,
  p.price AS regular_price,
  p.sale_price,
  COALESCE(pi.image_url, p.image_url) AS image_url,
  p.aliases
FROM product_variants pv
JOIN products p ON p.id = pv.product_id
LEFT JOIN stores s ON s.id = p.store_id
LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = TRUE
WHERE p.active = TRUE
  AND p.deleted_at IS NULL
  AND pv.active = TRUE
  AND pv.stock > 0;
