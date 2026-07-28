<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data:; style-src 'unsafe-inline'; script-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$forwardedPrefix = rtrim((string) ($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? ''), '/');
$basePath = $forwardedPrefix === '' ? '/' : $forwardedPrefix . '/';
$isHttps = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'cookie_secure' => $isHttps,
    'cookie_path' => $basePath,
    'use_strict_mode' => true,
]);

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

const ORDER_STATUSES = [
    'ALL',
    'PENDING_PAYMENT',
    'CONFIRMED',
    'REJECTED',
    'CANCELLED',
    'DISPATCHED',
];

const VIEWS = ['orders', 'catalog', 'shipments'];

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("Falta la variable {$name}");
    }
    return $value;
}

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('MARIADB_HOST') ?: 'mariadb';
    $port = getenv('MARIADB_PORT') ?: '3306';
    $name = requiredEnvironment('MARIADB_DATABASE');

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        requiredEnvironment('MARIADB_USER'),
        requiredEnvironment('MARIADB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(float|string|null $value): string
{
    return number_format((float) $value, 0, ',', '.');
}

function statusLabel(string $status): string
{
    return match ($status) {
        'PENDING_PAYMENT' => 'Pago pendiente',
        'CONFIRMED' => 'Confirmado',
        'REJECTED' => 'Rechazado',
        'CANCELLED' => 'Cancelado',
        'DISPATCHED' => 'Despachado',
        default => $status,
    };
}

function viewUrl(string $basePath, string $view, array $params = []): string
{
    $query = array_merge(['view' => $view], $params);
    if ($view === 'orders' && $params === []) {
        return $basePath;
    }
    return $basePath . '?' . http_build_query($query);
}

function redirectWithFlash(string $basePath, string $message, string $type, string $view, array $params = []): never
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    header('Location: ' . viewUrl($basePath, $view, $params), true, 303);
    exit;
}

function lockOrderStatus(PDO $pdo, int $orderId): string
{
    $statement = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
    $statement->execute([$orderId]);
    $status = $statement->fetchColumn();
    if (!is_string($status)) {
        throw new RuntimeException('Pedido no encontrado');
    }
    return $status;
}

function slugPart(string $value): string
{
    $slug = strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: '';
    return trim($slug, '-') ?: 'producto';
}

function readAliases(string $aliasesText, string ...$fallbacks): string
{
    $aliases = [];
    foreach (preg_split('/\R+/', $aliasesText) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $aliases[] = $line;
        }
    }
    foreach ($fallbacks as $fallback) {
        $fallback = trim($fallback);
        if ($fallback !== '') {
            $aliases[] = $fallback;
        }
    }
    $aliases = array_values(array_unique($aliases));
    return json_encode($aliases, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function uploadImage(string $basePath): ?string
{
    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        return null;
    }
    if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir la imagen.');
    }
    if ((int) $_FILES['image']['size'] > 6 * 1024 * 1024) {
        throw new RuntimeException('La imagen no puede pesar más de 6 MB.');
    }

    $tmpName = (string) $_FILES['image']['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
    $extension = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => throw new RuntimeException('Formato de imagen no permitido. Usa JPG, PNG o WebP.'),
    };

    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        throw new RuntimeException('No se pudo crear la carpeta de imágenes.');
    }

    $filename = bin2hex(random_bytes(12)) . '.' . $extension;
    $target = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException('No se pudo guardar la imagen.');
    }

    return 'uploads/' . $filename;
}

function imageSrc(string $basePath, ?string $url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, 'data:')) {
        return $url;
    }
    return $basePath . ltrim($url, '/');
}

function saveProduct(PDO $pdo, string $basePath): int
{
    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $name = trim((string) ($_POST['name'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $type = trim((string) ($_POST['type'] ?? ''));
    $brand = trim((string) ($_POST['brand'] ?? ''));
    $color = trim((string) ($_POST['color'] ?? ''));
    $gender = trim((string) ($_POST['gender'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $salePriceRaw = trim((string) ($_POST['sale_price'] ?? ''));
    $salePrice = $salePriceRaw === '' ? null : filter_var($salePriceRaw, FILTER_VALIDATE_FLOAT);
    $active = isset($_POST['active']) ? 1 : 0;
    $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
    $uploadedImage = uploadImage($basePath);
    if ($uploadedImage !== null) {
        $imageUrl = $uploadedImage;
    }

    if ($name === '' || $category === '' || $type === '') {
        throw new RuntimeException('Nombre, categoría y tipo son obligatorios.');
    }
    if ($price === false || $price < 0) {
        throw new RuntimeException('El precio debe ser un número mayor o igual a cero.');
    }
    if ($salePriceRaw !== '' && ($salePrice === false || $salePrice < 0)) {
        throw new RuntimeException('El precio promocional debe ser válido.');
    }

    $sizes = $_POST['variant_size'] ?? [];
    $stocks = $_POST['variant_stock'] ?? [];
    $skus = $_POST['variant_sku'] ?? [];
    if (!is_array($sizes) || !is_array($stocks) || !is_array($skus)) {
        throw new RuntimeException('Las variantes no son válidas.');
    }

    $variants = [];
    foreach ($sizes as $index => $rawSize) {
        $size = trim((string) $rawSize);
        $stock = filter_var($stocks[$index] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $sku = strtoupper(trim((string) ($skus[$index] ?? '')));
        if ($size === '' && ($stock === false || $sku === '')) {
            continue;
        }
        if ($size === '' || $stock === false) {
            throw new RuntimeException('Cada variante debe tener talla y cantidad válida.');
        }
        $variants[] = ['size' => $size, 'stock' => (int) $stock, 'sku' => $sku];
    }
    if ($variants === []) {
        throw new RuntimeException('Crea al menos una talla o variante.');
    }

    $baseSku = strtoupper(slugPart($name));
    foreach ($variants as $index => &$variant) {
        if ($variant['sku'] === '') {
            $variant['sku'] = substr($baseSku . '-' . strtoupper(slugPart($variant['size'])), 0, 64);
        }
        if ($index > 0 && $variant['sku'] === $variants[0]['sku']) {
            $variant['sku'] = substr($variant['sku'] . '-' . ($index + 1), 0, 64);
        }
    }
    unset($variant);

    $primarySku = $variants[0]['sku'];
    $totalStock = array_sum(array_column($variants, 'stock'));
    $aliases = readAliases((string) ($_POST['aliases'] ?? ''), $name, $category, $type, $brand, $color);

    $pdo->beginTransaction();
    try {
        if ($productId !== false) {
            $statement = $pdo->prepare('UPDATE products SET sku = ?, name = ?, description = ?, category = ?, type = ?, brand = ?, color = ?, gender = ?, aliases = ?, unit = ?, price = ?, sale_price = ?, stock = ?, image_url = ?, active = ? WHERE id = ?');
            $statement->execute([$primarySku, $name, $description, $category, $type, $brand ?: null, $color ?: null, $gender ?: null, $aliases, $type, $price, $salePrice ?: null, $totalStock, $imageUrl ?: null, $active, $productId]);
            $savedId = (int) $productId;
            $pdo->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$savedId]);
        } else {
            $statement = $pdo->prepare('INSERT INTO products (sku, name, description, category, type, brand, color, gender, aliases, unit, price, sale_price, stock, image_url, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([$primarySku, $name, $description, $category, $type, $brand ?: null, $color ?: null, $gender ?: null, $aliases, $type, $price, $salePrice ?: null, $totalStock, $imageUrl ?: null, $active]);
            $savedId = (int) $pdo->lastInsertId();
        }

        $variantStatement = $pdo->prepare('INSERT INTO product_variants (product_id, sku, size, stock, active) VALUES (?, ?, ?, ?, ?)');
        foreach ($variants as $variant) {
            $variantStatement->execute([$savedId, $variant['sku'], $variant['size'], $variant['stock'], $active]);
        }

        if ($imageUrl !== '') {
            $pdo->prepare('UPDATE product_images SET is_primary = FALSE WHERE product_id = ?')->execute([$savedId]);
            $imageStatement = $pdo->prepare('INSERT INTO product_images (product_id, image_path, image_url, is_primary) VALUES (?, ?, ?, TRUE)');
            $imageStatement->execute([$savedId, str_starts_with($imageUrl, 'uploads/') ? $imageUrl : null, $imageUrl]);
        }

        $pdo->commit();
        return $savedId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

$view = (string) ($_GET['view'] ?? $_POST['view'] ?? 'orders');
if (!in_array($view, VIEWS, true)) {
    $view = 'orders';
}

$selectedStatus = strtoupper((string) ($_GET['status'] ?? $_POST['return_status'] ?? 'ALL'));
if (!in_array($selectedStatus, ORDER_STATUSES, true)) {
    $selectedStatus = 'ALL';
}

$operator = trim((string) ($_SERVER['HTTP_X_OPERATOR_USER'] ?? 'operador'));
if ($operator === '') {
    $operator = 'operador';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf'], $csrf)) {
        redirectWithFlash($basePath, 'La sesión expiró. Vuelve a intentarlo.', 'error', $view);
    }

    $action = (string) ($_POST['action'] ?? '');
    $pdo = database();

    try {
        if ($action === 'save_product') {
            $savedId = saveProduct($pdo, $basePath);
            redirectWithFlash($basePath, "Producto #{$savedId} guardado.", 'success', 'catalog', ['edit' => $savedId]);
        }

        if ($action === 'toggle_product') {
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($productId === false) {
                throw new RuntimeException('Producto no válido.');
            }
            $pdo->prepare('UPDATE products SET active = NOT active WHERE id = ?')->execute([$productId]);
            $pdo->prepare('UPDATE product_variants SET active = (SELECT active FROM products WHERE products.id = product_variants.product_id) WHERE product_id = ?')->execute([$productId]);
            redirectWithFlash($basePath, "Producto #{$productId} actualizado.", 'success', 'catalog');
        }

        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false || !in_array($action, ['approve', 'reject', 'dispatch'], true)) {
            throw new RuntimeException('Acción no válida.');
        }

        if ($action === 'approve') {
            $statement = $pdo->prepare('CALL approve_order(?, ?)');
            $statement->execute([$orderId, $operator]);
            $row = $statement->fetch();
            while ($statement->nextRowset()) {
                // Consume every result set returned by MariaDB procedures.
            }
            $result = json_decode((string) ($row['result'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            if (($result['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($result['error'] ?? 'No se pudo aprobar el pedido'));
            }
            redirectWithFlash($basePath, "Pedido #{$orderId} confirmado y stock descontado.", 'success', 'orders', ['status' => $selectedStatus]);
        }

        $pdo->beginTransaction();
        $currentStatus = lockOrderStatus($pdo, $orderId);

        if ($action === 'reject') {
            if ($currentStatus !== 'PENDING_PAYMENT') {
                throw new RuntimeException("Solo se puede rechazar un pedido con pago pendiente; ahora está {$currentStatus}");
            }
            $statement = $pdo->prepare("UPDATE orders SET status = 'REJECTED' WHERE id = ?");
            $statement->execute([$orderId]);
            $eventType = 'PAYMENT_REJECTED';
            $message = "Pedido #{$orderId} rechazado.";
        } else {
            if ($currentStatus !== 'CONFIRMED') {
                throw new RuntimeException("Solo se puede despachar un pedido confirmado; ahora está {$currentStatus}");
            }
            $statement = $pdo->prepare("UPDATE orders SET status = 'DISPATCHED', logistics_notified_at = NOW() WHERE id = ?");
            $statement->execute([$orderId]);
            $eventType = 'ORDER_DISPATCHED';
            $message = "Pedido #{$orderId} marcado como despachado.";
        }

        $event = $pdo->prepare('INSERT INTO order_events (order_id, event_type, actor, details) VALUES (?, ?, ?, ?)');
        $event->execute([$orderId, $eventType, $operator, json_encode(['source' => 'operator-panel'], JSON_THROW_ON_ERROR)]);
        $pdo->commit();
        redirectWithFlash($basePath, $message, 'success', 'orders', ['status' => $selectedStatus]);
    } catch (Throwable $error) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirectWithFlash($basePath, $error->getMessage(), 'error', $view);
    }
}

try {
    $pdo = database();
    $counts = array_fill_keys(array_slice(ORDER_STATUSES, 1), 0);
    foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM orders GROUP BY status') as $row) {
        $counts[$row['status']] = (int) $row['total'];
    }

    $sql = 'SELECT id, customer_name, phone, delivery_address, delivery_notes, raw_message, status, total, payment_proof_url, payment_confirmed_by, payment_confirmed_at, created_at, updated_at FROM orders';
    $parameters = [];
    if ($selectedStatus !== 'ALL') {
        $sql .= ' WHERE status = ?';
        $parameters[] = $selectedStatus;
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 200';

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $orders = $statement->fetchAll();

    $itemsStatement = $pdo->prepare('SELECT sku, product_name, size, quantity, unit_price, image_url FROM order_items WHERE order_id = ? ORDER BY id');
    foreach ($orders as &$order) {
        $itemsStatement->execute([$order['id']]);
        $order['items'] = $itemsStatement->fetchAll();
    }
    unset($order);

    $products = $pdo->query(
        "SELECT p.*, COALESCE(v.total_variant_stock, p.stock) AS total_variant_stock, v.variant_summary
         FROM products p
         LEFT JOIN (
           SELECT product_id,
             SUM(stock) AS total_variant_stock,
             GROUP_CONCAT(CONCAT(size, ': ', stock) ORDER BY id SEPARATOR ', ') AS variant_summary
           FROM product_variants
           GROUP BY product_id
         ) v ON v.product_id = p.id
         ORDER BY p.updated_at DESC, p.id DESC"
    )->fetchAll();

    $editProduct = null;
    $editVariants = [];
    $editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($editId !== false && $editId !== null) {
        $statement = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $statement->execute([$editId]);
        $editProduct = $statement->fetch() ?: null;
        if ($editProduct !== null) {
            $statement = $pdo->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY id');
            $statement->execute([$editId]);
            $editVariants = $statement->fetchAll();
        }
    }

    $shipments = $pdo->query('SELECT * FROM daily_confirmed_orders ORDER BY payment_confirmed_at DESC, order_id DESC LIMIT 200')->fetchAll();
} catch (Throwable $error) {
    http_response_code(503);
    $databaseError = $error->getMessage();
    $counts = array_fill_keys(array_slice(ORDER_STATUSES, 1), 0);
    $orders = [];
    $products = [];
    $shipments = [];
    $editProduct = null;
    $editVariants = [];
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$totalOrders = array_sum($counts);
$formProduct = $editProduct ?? [
    'id' => '',
    'name' => '',
    'description' => '',
    'category' => '',
    'type' => '',
    'brand' => '',
    'color' => '',
    'gender' => '',
    'price' => '',
    'sale_price' => '',
    'image_url' => '',
    'aliases' => '[]',
    'active' => 1,
];
if ($editVariants === []) {
    $editVariants = [['sku' => '', 'size' => '', 'stock' => 0]];
}
$aliasesText = '';
if (isset($formProduct['aliases'])) {
    $decodedAliases = json_decode((string) $formProduct['aliases'], true);
    $aliasesText = is_array($decodedAliases) ? implode("\n", $decodedAliases) : '';
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ventas WhatsApp · Operador</title>
  <style>
    :root { color-scheme: light; --ink:#18221d; --muted:#65716a; --paper:#f4f2eb; --panel:#fff; --line:#dcded7; --green:#1c6b4a; --green-soft:#dceee5; --amber:#966114; --amber-soft:#fff0cf; --red:#a13832; --red-soft:#f9dfdc; --blue:#315f8a; --blue-soft:#e1edf8; --dark:#13251d; }
    * { box-sizing:border-box; }
    body { margin:0; background:var(--paper); color:var(--ink); font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
    header { background:var(--dark); color:#fff; padding:24px clamp(18px,4vw,54px); }
    header .wrap { max-width:1440px; margin:auto; display:flex; justify-content:space-between; gap:24px; align-items:end; }
    h1 { margin:0 0 4px; font-size:clamp(25px,4vw,38px); }
    h2 { margin:0 0 14px; font-size:22px; }
    h3 { margin:0 0 10px; font-size:17px; }
    p { margin:0; }
    header p { color:#b9c8c0; }
    a { color:var(--green); }
    main { max-width:1440px; margin:auto; padding:24px clamp(18px,4vw,54px) 60px; }
    .tabs, .filters, .actions { display:flex; flex-wrap:wrap; gap:8px; }
    .tabs { margin-bottom:18px; }
    .tab, .filters a { color:var(--ink); background:#e8e6de; padding:9px 13px; border-radius:8px; text-decoration:none; font-size:14px; font-weight:700; }
    .tab.active, .filters a.active { background:var(--dark); color:#fff; }
    .summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(145px,1fr)); gap:12px; margin-bottom:18px; }
    .metric { background:var(--panel); border:1px solid var(--line); border-radius:8px; padding:16px; text-decoration:none; color:inherit; }
    .metric strong { display:block; font-size:27px; margin-top:4px; }
    .metric.active { outline:3px solid #8bbda5; border-color:transparent; }
    .flash, .error-box { padding:13px 16px; border-radius:8px; margin-bottom:18px; border:1px solid; }
    .flash.success { background:var(--green-soft); border-color:#9bcab3; color:#174d37; }
    .flash.error, .error-box { background:var(--red-soft); border-color:#dda7a2; color:#742621; }
    .grid { display:grid; gap:16px; }
    .split { display:grid; grid-template-columns:minmax(320px,.8fr) minmax(360px,1.2fr); gap:18px; align-items:start; }
    .panel, .order, .product { background:var(--panel); border:1px solid var(--line); border-radius:8px; overflow:hidden; box-shadow:0 4px 18px rgb(30 42 35 / 5%); }
    .panel { padding:18px; }
    .order-head, .product-head { padding:16px 18px; border-bottom:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; gap:14px; }
    .order-title, .product-title { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .order-title h2, .product-title h2 { font-size:18px; margin:0; }
    .badge { border-radius:999px; padding:5px 9px; font-size:12px; font-weight:800; }
    .PENDING_PAYMENT { background:var(--amber-soft); color:var(--amber); }
    .CONFIRMED, .active-badge { background:var(--green-soft); color:var(--green); }
    .REJECTED,.CANCELLED,.inactive-badge { background:var(--red-soft); color:var(--red); }
    .DISPATCHED { background:var(--blue-soft); color:var(--blue); }
    .total, .price { font-size:20px; font-weight:800; white-space:nowrap; }
    .order-body, .product-body { display:grid; grid-template-columns:minmax(220px,.9fr) minmax(280px,1.5fr); gap:18px; padding:18px; }
    .facts { display:grid; gap:10px; font-size:14px; }
    .fact span, label span { display:block; color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; font-weight:800; }
    .items { display:grid; gap:8px; }
    .item { display:grid; grid-template-columns:52px 1fr auto; gap:11px; align-items:center; background:#f7f7f3; border-radius:8px; padding:8px; }
    .item img, .thumb { width:52px; height:52px; border-radius:8px; object-fit:cover; background:#e6e7e1; }
    .hero-img { width:100%; max-height:230px; object-fit:cover; border-radius:8px; background:#e6e7e1; }
    .item small, .muted { color:var(--muted); }
    .actions { padding:0 18px 18px; }
    .actions form { margin:0; }
    button, .button { border:0; border-radius:8px; padding:10px 14px; color:#fff; font-weight:800; cursor:pointer; text-decoration:none; display:inline-block; }
    button.approve, .primary { background:var(--green); }
    button.reject, .danger { background:var(--red); }
    button.dispatch, .secondary { background:var(--blue); }
    button.neutral { background:#5f6b64; }
    input, textarea, select { width:100%; border:1px solid #cfd3ca; border-radius:8px; padding:10px 11px; font:inherit; background:#fff; color:var(--ink); }
    input[type="checkbox"] { width:auto; margin-right:8px; }
    textarea { min-height:86px; resize:vertical; }
    form.catalog { display:grid; gap:14px; }
    .fields { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
    .fields .wide { grid-column:1/-1; }
    .variant-row { display:grid; grid-template-columns:1fr 1fr .8fr; gap:9px; margin-bottom:8px; }
    .empty { text-align:center; background:var(--panel); border:1px dashed #c5c8bf; border-radius:8px; padding:42px 20px; color:var(--muted); }
    .shipments { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--line); border-radius:8px; overflow:hidden; }
    .shipments th, .shipments td { text-align:left; padding:12px; border-bottom:1px solid var(--line); vertical-align:top; }
    .shipments th { background:#eef0ea; font-size:12px; text-transform:uppercase; color:var(--muted); }
    @media (max-width:860px) { header .wrap { align-items:start; flex-direction:column; } .split,.order-body,.product-body,.fields{grid-template-columns:1fr}.summary{grid-template-columns:repeat(2,1fr)} .variant-row{grid-template-columns:1fr} }
  </style>
</head>
<body>
<header>
  <div class="wrap">
    <section>
      <h1>Ventas WhatsApp</h1>
      <p>Catálogo, pagos y despacho</p>
    </section>
    <p>Operador<br><strong><?= escape($operator) ?></strong></p>
  </div>
</header>
<main>
  <nav class="tabs" aria-label="Secciones">
    <a class="tab <?= $view === 'orders' ? 'active' : '' ?>" href="<?= escape(viewUrl($basePath, 'orders')) ?>">Pedidos</a>
    <a class="tab <?= $view === 'catalog' ? 'active' : '' ?>" href="<?= escape(viewUrl($basePath, 'catalog')) ?>">Catálogo</a>
    <a class="tab <?= $view === 'shipments' ? 'active' : '' ?>" href="<?= escape(viewUrl($basePath, 'shipments')) ?>">Envíos</a>
  </nav>

  <?php if ($flash): ?>
    <div class="flash <?= escape($flash['type']) ?>" role="status"><?= escape($flash['message']) ?></div>
  <?php endif; ?>
  <?php if (isset($databaseError)): ?>
    <div class="error-box"><strong>No se pudo consultar MariaDB.</strong><br><?= escape($databaseError) ?></div>
  <?php elseif ($view === 'catalog'): ?>
    <section class="split">
      <form class="panel catalog" method="post" enctype="multipart/form-data" action="<?= escape($basePath) ?>">
        <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="save_product">
        <input type="hidden" name="view" value="catalog">
        <input type="hidden" name="product_id" value="<?= escape((string) $formProduct['id']) ?>">
        <h2><?= $editProduct ? 'Editar producto' : 'Crear producto' ?></h2>
        <div class="fields">
          <label class="wide"><span>Nombre</span><input name="name" required value="<?= escape((string) $formProduct['name']) ?>" placeholder="Zapato deportivo negro"></label>
          <label><span>Categoría</span><input name="category" required value="<?= escape((string) $formProduct['category']) ?>" placeholder="zapatos"></label>
          <label><span>Tipo o clase</span><input name="type" required value="<?= escape((string) $formProduct['type']) ?>" placeholder="deportivos"></label>
          <label><span>Marca</span><input name="brand" value="<?= escape((string) $formProduct['brand']) ?>" placeholder="Nike"></label>
          <label><span>Color</span><input name="color" value="<?= escape((string) $formProduct['color']) ?>" placeholder="negro"></label>
          <label><span>Género</span><input name="gender" value="<?= escape((string) $formProduct['gender']) ?>" placeholder="unisex"></label>
          <label><span>Precio</span><input name="price" required inputmode="decimal" value="<?= escape((string) $formProduct['price']) ?>" placeholder="145000"></label>
          <label><span>Precio promo</span><input name="sale_price" inputmode="decimal" value="<?= escape((string) $formProduct['sale_price']) ?>" placeholder="opcional"></label>
          <label class="wide"><span>Descripción</span><textarea name="description" placeholder="Detalle corto para vender mejor"><?= escape((string) $formProduct['description']) ?></textarea></label>
          <label class="wide"><span>Aliases, uno por línea</span><textarea name="aliases" placeholder="tenis&#10;zapatos deportivos&#10;sneakers"><?= escape($aliasesText) ?></textarea></label>
          <label class="wide"><span>Subir foto</span><input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
          <label class="wide"><span>URL de imagen</span><input name="image_url" value="<?= escape((string) $formProduct['image_url']) ?>" placeholder="opcional si no subes archivo"></label>
          <label class="wide checkbox"><input type="checkbox" name="active" value="1" <?= (int) $formProduct['active'] === 1 ? 'checked' : '' ?>> Producto activo</label>
        </div>
        <section>
          <h3>Tallas y cantidades</h3>
          <?php for ($i = 0; $i < max(5, count($editVariants)); $i++): $variant = $editVariants[$i] ?? ['sku' => '', 'size' => '', 'stock' => '']; ?>
            <div class="variant-row">
              <input name="variant_size[]" value="<?= escape((string) $variant['size']) ?>" placeholder="Talla M">
              <input name="variant_sku[]" value="<?= escape((string) $variant['sku']) ?>" placeholder="SKU opcional">
              <input name="variant_stock[]" value="<?= escape((string) $variant['stock']) ?>" inputmode="numeric" placeholder="Cantidad">
            </div>
          <?php endfor; ?>
        </section>
        <div class="actions">
          <button class="approve" type="submit">Guardar producto</button>
          <?php if ($editProduct): ?><a class="button neutral" href="<?= escape(viewUrl($basePath, 'catalog')) ?>">Nuevo</a><?php endif; ?>
        </div>
      </form>

      <section class="grid">
        <?php if ($products === []): ?>
          <div class="empty">Todavía no hay productos en el catálogo.</div>
        <?php else: ?>
          <?php foreach ($products as $product): ?>
            <article class="product">
              <div class="product-head">
                <div class="product-title">
                  <h2><?= escape($product['name']) ?></h2>
                  <span class="badge <?= (int) $product['active'] === 1 ? 'active-badge' : 'inactive-badge' ?>"><?= (int) $product['active'] === 1 ? 'Activo' : 'Inactivo' ?></span>
                </div>
                <div class="price">$<?= money($product['sale_price'] ?: $product['price']) ?></div>
              </div>
              <div class="product-body">
                <div>
                  <?php if ($product['image_url']): ?><img class="hero-img" src="<?= escape(imageSrc($basePath, $product['image_url'])) ?>" alt="" loading="lazy"><?php endif; ?>
                </div>
                <div class="facts">
                  <div class="fact"><span>Categoría</span><?= escape($product['category']) ?> · <?= escape($product['type']) ?></div>
                  <div class="fact"><span>Marca / color</span><?= escape(trim(($product['brand'] ?? '') . ' ' . ($product['color'] ?? '')) ?: 'Sin definir') ?></div>
                  <div class="fact"><span>Stock</span><?= (int) $product['total_variant_stock'] ?> unidades · <?= escape($product['variant_summary'] ?? '') ?></div>
                  <div class="fact"><span>Descripción</span><?= nl2br(escape($product['description'])) ?></div>
                </div>
              </div>
              <div class="actions">
                <a class="button secondary" href="<?= escape(viewUrl($basePath, 'catalog', ['edit' => (int) $product['id']])) ?>">Editar</a>
                <form method="post" action="<?= escape($basePath) ?>">
                  <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                  <input type="hidden" name="action" value="toggle_product">
                  <input type="hidden" name="view" value="catalog">
                  <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                  <button class="<?= (int) $product['active'] === 1 ? 'reject' : 'approve' ?>" type="submit"><?= (int) $product['active'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>
    </section>
  <?php elseif ($view === 'shipments'): ?>
    <?php if ($shipments === []): ?>
      <div class="empty">No hay envíos aprobados pendientes en la vista diaria.</div>
    <?php else: ?>
      <table class="shipments">
        <thead><tr><th>Pedido</th><th>Cliente</th><th>Dirección</th><th>Productos</th><th>Total</th><th>Aprobado</th></tr></thead>
        <tbody>
          <?php foreach ($shipments as $shipment): ?>
            <tr>
              <td>#<?= (int) $shipment['order_id'] ?></td>
              <td><?= escape($shipment['customer_name']) ?><br><span class="muted"><?= escape($shipment['phone']) ?></span></td>
              <td><?= escape($shipment['delivery_address']) ?><?php if ($shipment['delivery_notes']): ?><br><span class="muted"><?= escape($shipment['delivery_notes']) ?></span><?php endif; ?></td>
              <td><?= nl2br(escape($shipment['items'])) ?></td>
              <td>$<?= money($shipment['total']) ?></td>
              <td><?= escape($shipment['payment_confirmed_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php else: ?>
    <nav class="summary" aria-label="Resumen de pedidos">
      <a class="metric <?= $selectedStatus === 'ALL' ? 'active' : '' ?>" href="<?= escape(viewUrl($basePath, 'orders')) ?>"><span>Todos</span><strong><?= $totalOrders ?></strong></a>
      <?php foreach ($counts as $status => $count): ?>
        <a class="metric <?= $selectedStatus === $status ? 'active' : '' ?>" href="<?= escape(viewUrl($basePath, 'orders', ['status' => $status])) ?>"><span><?= escape(statusLabel($status)) ?></span><strong><?= $count ?></strong></a>
      <?php endforeach; ?>
    </nav>
    <nav class="filters" aria-label="Filtros">
      <?php foreach (ORDER_STATUSES as $status): ?>
        <a class="<?= $selectedStatus === $status ? 'active' : '' ?>" href="<?= escape(viewUrl($basePath, 'orders', $status === 'ALL' ? [] : ['status' => $status])) ?>"><?= $status === 'ALL' ? 'Todos' : escape(statusLabel($status)) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php if ($orders === []): ?>
      <div class="empty">No hay pedidos en este estado.</div>
    <?php else: ?>
      <section class="grid">
      <?php foreach ($orders as $order): ?>
        <article class="order">
          <div class="order-head">
            <div class="order-title">
              <h2>Pedido #<?= (int) $order['id'] ?> · <?= escape($order['customer_name']) ?></h2>
              <span class="badge <?= escape($order['status']) ?>"><?= escape(statusLabel($order['status'])) ?></span>
            </div>
            <div class="total">$<?= money($order['total']) ?></div>
          </div>
          <div class="order-body">
            <div class="facts">
              <div class="fact"><span>Teléfono</span><a href="tel:<?= escape($order['phone']) ?>"><?= escape($order['phone']) ?></a></div>
              <div class="fact"><span>Dirección</span><?= escape($order['delivery_address']) ?></div>
              <?php if ($order['delivery_notes']): ?><div class="fact"><span>Notas</span><?= escape($order['delivery_notes']) ?></div><?php endif; ?>
              <div class="fact"><span>Pedido original</span><?= nl2br(escape($order['raw_message'])) ?></div>
              <div class="fact"><span>Creado</span><?= escape($order['created_at']) ?></div>
              <?php if ($order['payment_proof_url']): ?><div class="fact"><span>Comprobante</span><a href="<?= escape(imageSrc($basePath, $order['payment_proof_url'])) ?>" target="_blank" rel="noopener">Ver imagen</a></div><?php endif; ?>
              <?php if ($order['payment_confirmed_by']): ?><div class="fact"><span>Pago verificado por</span><?= escape($order['payment_confirmed_by']) ?> · <?= escape($order['payment_confirmed_at']) ?></div><?php endif; ?>
            </div>
            <div class="items">
              <?php foreach ($order['items'] as $item): ?>
                <div class="item">
                  <?php if ($item['image_url']): ?><img src="<?= escape(imageSrc($basePath, $item['image_url'])) ?>" alt="" loading="lazy"><?php else: ?><span></span><?php endif; ?>
                  <div><strong><?= (int) $item['quantity'] ?> x <?= escape($item['product_name']) ?></strong><br><small><?= escape($item['sku']) ?><?= $item['size'] ? ' · talla ' . escape($item['size']) : '' ?> · $<?= money($item['unit_price']) ?> / unidad</small></div>
                  <strong>$<?= money((float) $item['quantity'] * (float) $item['unit_price']) ?></strong>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="actions">
            <?php if ($order['status'] === 'PENDING_PAYMENT'): ?>
              <form method="post" action="<?= escape($basePath) ?>">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="return_status" value="<?= escape($selectedStatus) ?>">
                <button class="approve" type="submit">Confirmar pago</button>
              </form>
              <form method="post" action="<?= escape($basePath) ?>">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="return_status" value="<?= escape($selectedStatus) ?>">
                <button class="reject" type="submit">Rechazar pago</button>
              </form>
            <?php elseif ($order['status'] === 'CONFIRMED'): ?>
              <form method="post" action="<?= escape($basePath) ?>">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <input type="hidden" name="action" value="dispatch">
                <input type="hidden" name="return_status" value="<?= escape($selectedStatus) ?>">
                <button class="dispatch" type="submit">Marcar como despachado</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
