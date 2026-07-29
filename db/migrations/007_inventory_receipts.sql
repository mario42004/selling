USE whatsapp_orders;

CREATE TABLE IF NOT EXISTS inventory_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  notes VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inventory_receipts_store_created (store_id, created_at),
  KEY idx_inventory_receipts_variant_created (variant_id, created_at),
  KEY idx_inventory_receipts_actor_created (actor_user_id, created_at),
  CONSTRAINT fk_inventory_receipts_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_inventory_receipts_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_inventory_receipts_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id),
  CONSTRAINT fk_inventory_receipts_actor FOREIGN KEY (actor_user_id) REFERENCES users(id),
  CONSTRAINT chk_inventory_receipts_quantity CHECK (quantity > 0)
) ENGINE=InnoDB;
