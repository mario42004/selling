USE whatsapp_orders;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('PROVIDER','DISPATCHER','CITY_MANAGER','ADMIN') NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_active (role, active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cities_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS zones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  city_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_zones_city_name (city_id, name),
  KEY idx_zones_city (city_id),
  CONSTRAINT fk_zones_city FOREIGN KEY (city_id) REFERENCES cities(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stores (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  city_id BIGINT UNSIGNED NOT NULL,
  zone_id BIGINT UNSIGNED NULL,
  name VARCHAR(180) NOT NULL,
  address VARCHAR(500) NULL,
  phone VARCHAR(40) NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stores_city_name (city_id, name),
  KEY idx_stores_city_zone (city_id, zone_id),
  CONSTRAINT fk_stores_city FOREIGN KEY (city_id) REFERENCES cities(id),
  CONSTRAINT fk_stores_zone FOREIGN KEY (zone_id) REFERENCES zones(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_store_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_store_assignments (user_id, store_id),
  KEY idx_user_store_assignments_store (store_id),
  CONSTRAINT fk_user_store_assignments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_store_assignments_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_city_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  city_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_city_assignments (user_id, city_id),
  KEY idx_user_city_assignments_city (city_id),
  CONSTRAINT fk_user_city_assignments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_city_assignments_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS store_id BIGINT UNSIGNED NULL AFTER id;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS store_id BIGINT UNSIGNED NULL AFTER conversation_id,
  ADD COLUMN IF NOT EXISTS city_id BIGINT UNSIGNED NULL AFTER store_id,
  ADD COLUMN IF NOT EXISTS zone_id BIGINT UNSIGNED NULL AFTER city_id,
  ADD COLUMN IF NOT EXISTS payment_reviewed_by_user_id BIGINT UNSIGNED NULL AFTER payment_confirmed_by,
  ADD COLUMN IF NOT EXISTS dispatched_by_user_id BIGINT UNSIGNED NULL AFTER logistics_notified_at;

INSERT INTO cities (name, active)
SELECT 'Principal', TRUE
WHERE NOT EXISTS (SELECT 1 FROM cities WHERE name = 'Principal');

INSERT INTO zones (city_id, name, active)
SELECT c.id, 'General', TRUE
FROM cities c
WHERE c.name = 'Principal'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.city_id = c.id AND z.name = 'General');

INSERT INTO stores (city_id, zone_id, name, address, active)
SELECT c.id, z.id, 'Tienda principal', 'Pendiente de configurar', TRUE
FROM cities c
JOIN zones z ON z.city_id = c.id AND z.name = 'General'
WHERE c.name = 'Principal'
  AND NOT EXISTS (SELECT 1 FROM stores s WHERE s.city_id = c.id AND s.name = 'Tienda principal');

UPDATE products p
JOIN stores s ON s.name = 'Tienda principal'
SET p.store_id = COALESCE(p.store_id, s.id);

UPDATE orders o
JOIN stores s ON s.name = 'Tienda principal'
SET o.store_id = COALESCE(o.store_id, s.id),
    o.city_id = COALESCE(o.city_id, s.city_id),
    o.zone_id = COALESCE(o.zone_id, s.zone_id);

DELIMITER //

DROP TRIGGER IF EXISTS orders_default_location//
CREATE TRIGGER orders_default_location
BEFORE INSERT ON orders
FOR EACH ROW
BEGIN
  DECLARE v_store_id BIGINT UNSIGNED;
  DECLARE v_city_id BIGINT UNSIGNED;
  DECLARE v_zone_id BIGINT UNSIGNED;

  IF NEW.store_id IS NULL THEN
    SELECT s.id, s.city_id, s.zone_id
      INTO v_store_id, v_city_id, v_zone_id
      FROM stores s
      ORDER BY s.id
      LIMIT 1;
    SET NEW.store_id = v_store_id;
    SET NEW.city_id = COALESCE(NEW.city_id, v_city_id);
    SET NEW.zone_id = COALESCE(NEW.zone_id, v_zone_id);
  END IF;
END//

DELIMITER ;

CREATE OR REPLACE VIEW sales_stats_daily AS
SELECT
  DATE(o.payment_confirmed_at) AS sales_date,
  o.city_id,
  o.zone_id,
  o.store_id,
  COUNT(*) AS orders_count,
  SUM(o.total) AS sales_total,
  AVG(o.total) AS average_ticket
FROM orders o
WHERE o.status IN ('CONFIRMED','DISPATCHED')
  AND o.payment_confirmed_at IS NOT NULL
GROUP BY DATE(o.payment_confirmed_at), o.city_id, o.zone_id, o.store_id;

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
  AND pv.active = TRUE
  AND pv.stock > 0;
