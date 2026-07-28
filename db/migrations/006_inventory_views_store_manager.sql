USE whatsapp_orders;

ALTER TABLE users
  MODIFY role ENUM('SELLER','PROVIDER','DISPATCHER','STORE_MANAGER','CITY_MANAGER','ADMIN') NOT NULL;

CREATE OR REPLACE VIEW inventory_by_store AS
SELECT
  c.id AS city_id,
  c.name AS city_name,
  z.id AS zone_id,
  z.name AS zone_name,
  s.id AS store_id,
  s.name AS store_name,
  p.id AS product_id,
  p.name AS product_name,
  p.category,
  p.type,
  p.brand,
  p.color,
  pv.id AS variant_id,
  pv.sku,
  pv.size,
  pv.stock,
  pv.reserved_stock,
  COALESCE(p.sale_price, p.price) AS price,
  COALESCE(pi.image_url, p.image_url) AS image_url
FROM stores s
JOIN cities c ON c.id = s.city_id
LEFT JOIN zones z ON z.id = s.zone_id
JOIN products p ON p.store_id = s.id
JOIN product_variants pv ON pv.product_id = p.id
LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = TRUE
WHERE p.deleted_at IS NULL
  AND p.active = TRUE
  AND pv.active = TRUE;

CREATE OR REPLACE VIEW inventory_by_city AS
SELECT
  city_id,
  city_name,
  product_name,
  category,
  type,
  brand,
  color,
  size,
  SUM(stock) AS city_stock,
  SUM(reserved_stock) AS city_reserved_stock,
  COUNT(DISTINCT store_id) AS stores_with_product
FROM inventory_by_store
GROUP BY city_id, city_name, product_name, category, type, brand, color, size;

DELIMITER //

DROP TRIGGER IF EXISTS order_items_set_order_location//
CREATE TRIGGER order_items_set_order_location
AFTER INSERT ON order_items
FOR EACH ROW
BEGIN
  UPDATE orders o
  JOIN products p ON p.id = NEW.product_id
  JOIN stores s ON s.id = p.store_id
  SET o.store_id = p.store_id,
      o.city_id = s.city_id,
      o.zone_id = s.zone_id
  WHERE o.id = NEW.order_id;
END//

DELIMITER ;

UPDATE orders o
JOIN (
  SELECT oi.order_id, MIN(oi.id) AS first_item_id
  FROM order_items oi
  GROUP BY oi.order_id
) first_item ON first_item.order_id = o.id
JOIN order_items oi ON oi.id = first_item.first_item_id
JOIN products p ON p.id = oi.product_id
JOIN stores s ON s.id = p.store_id
SET o.store_id = p.store_id,
    o.city_id = s.city_id,
    o.zone_id = s.zone_id;
