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

const ORDER_STATUSES = ['ALL', 'PENDING_PAYMENT', 'CONFIRMED', 'REJECTED', 'CANCELLED', 'DISPATCHED'];

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

function moneyExact(float|string|null $value): string
{
    return number_format((float) $value, 2, ',', '.');
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

function roleSummary(array $user): string
{
    $names = $user['role_names'] ?? [];
    return is_array($names) && $names !== [] ? implode(' · ', $names) : 'Sin rol';
}

function scopeLabel(string $scopeLevel): string
{
    return match ($scopeLevel) {
        'GLOBAL' => 'alcance global',
        'CITY' => 'alcance de ciudad',
        default => 'alcance de tienda',
    };
}

function viewUrl(string $basePath, string $view, array $params = []): string
{
    if ($view === 'orders' && $params === []) {
        return $basePath;
    }
    return $basePath . '?' . http_build_query(array_merge(['view' => $view], $params));
}

function redirectWithFlash(string $basePath, string $message, string $type, string $view = 'orders', array $params = []): never
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    header('Location: ' . viewUrl($basePath, $view, $params), true, 303);
    exit;
}

function slugPart(string $value): string
{
    $slug = strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: '';
    return trim($slug, '-') ?: 'producto';
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

function localUploadPath(?string $url): ?string
{
    $url = trim((string) $url);
    if (!preg_match('#^uploads/([a-f0-9]{24}\.(?:jpg|png|webp))$#', $url, $matches)) {
        return null;
    }
    return __DIR__ . '/uploads/' . $matches[1];
}

function removeLocalUploads(array $urls): void
{
    foreach (array_unique($urls) as $url) {
        $path = localUploadPath(is_string($url) ? $url : null);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }
}

function requireDestructiveConfirmation(): void
{
    if (($_POST['confirm_delete'] ?? '') !== '1') {
        throw new RuntimeException('Confirma la eliminación definitiva antes de continuar.');
    }
}

function normalizeIds(mixed $value): array
{
    if (!is_array($value)) {
        $value = $value === null || $value === '' ? [] : [$value];
    }
    $ids = [];
    foreach ($value as $candidate) {
        $id = filter_var($candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id !== false) {
            $ids[] = (int) $id;
        }
    }
    return array_values(array_unique($ids));
}

function ensureBootstrapAdmin(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $name = getenv('OPERATOR_ADMIN_NAME') ?: 'Admin';
    $email = getenv('OPERATOR_ADMIN_EMAIL') ?: 'admin@example.com';
    $password = getenv('OPERATOR_ADMIN_PASSWORD') ?: 'ChangeMe123!';
    $statement = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, 'ADMIN', TRUE)");
    $statement->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = 'ADMIN'")
        ->execute([$userId]);
}

function loadAuthorization(PDO $pdo, array $user): array
{
    $statement = $pdo->prepare(
        'SELECT r.code, r.name, r.scope_level
         FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id
         WHERE ur.user_id = ? AND r.active = TRUE
         ORDER BY r.id'
    );
    $statement->execute([(int) $user['id']]);
    $roles = $statement->fetchAll();
    $user['role_codes'] = array_column($roles, 'code');
    $user['role_names'] = array_column($roles, 'name');
    $user['scope_levels'] = array_values(array_unique(array_column($roles, 'scope_level')));

    $statement = $pdo->prepare(
        'SELECT DISTINCT p.code
         FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id AND r.active = TRUE
         JOIN role_permissions rp ON rp.role_id = r.id
         JOIN permissions p ON p.id = rp.permission_id
         WHERE ur.user_id = ?
         ORDER BY p.code'
    );
    $statement->execute([(int) $user['id']]);
    $user['permissions'] = $statement->fetchAll(PDO::FETCH_COLUMN);
    return $user;
}

function hasScope(array $user, string $scopeLevel): bool
{
    $scopes = $user['scope_levels'] ?? [];
    return match ($scopeLevel) {
        'GLOBAL' => in_array('GLOBAL', $scopes, true),
        'CITY' => in_array('GLOBAL', $scopes, true) || in_array('CITY', $scopes, true),
        'STORE' => $scopes !== [],
        default => false,
    };
}

function currentUser(PDO $pdo): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !ctype_digit((string) $userId)) {
        return null;
    }
    $statement = $pdo->prepare('SELECT * FROM users WHERE id = ? AND active = TRUE');
    $statement->execute([(int) $userId]);
    $user = $statement->fetch();
    return is_array($user) ? loadAuthorization($pdo, $user) : null;
}

function can(array $user, string $permission): bool
{
    return in_array($permission, $user['permissions'] ?? [], true);
}

function assignedStoreIds(PDO $pdo, array $user): array
{
    if (hasScope($user, 'GLOBAL')) {
        return [];
    }
    $statement = $pdo->prepare('SELECT store_id FROM user_store_assignments WHERE user_id = ?');
    $statement->execute([(int) $user['id']]);
    $storeIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    if (hasScope($user, 'CITY')) {
        $statement = $pdo->prepare(
            'SELECT s.id FROM stores s JOIN user_city_assignments uca ON uca.city_id = s.city_id WHERE uca.user_id = ?'
        );
        $statement->execute([(int) $user['id']]);
        $storeIds = array_merge($storeIds, array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }
    return array_values(array_unique($storeIds));
}

function scopedWhere(PDO $pdo, array $user, string $alias, array &$params): string
{
    if (hasScope($user, 'GLOBAL')) {
        return '1=1';
    }
    $storeIds = assignedStoreIds($pdo, $user);
    if ($storeIds === []) {
        return '1=0';
    }
    $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
    foreach ($storeIds as $storeId) {
        $params[] = $storeId;
    }
    return "{$alias}.store_id IN ({$placeholders})";
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
    return json_encode(array_values(array_unique($aliases)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function uploadImage(): ?string
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
    if (!move_uploaded_file($tmpName, $uploadDir . '/' . $filename)) {
        throw new RuntimeException('No se pudo guardar la imagen.');
    }
    return 'uploads/' . $filename;
}

function assertStoreAccess(PDO $pdo, array $user, int $storeId): void
{
    if (hasScope($user, 'GLOBAL')) {
        return;
    }
    if (!in_array($storeId, assignedStoreIds($pdo, $user), true)) {
        throw new RuntimeException('No tienes acceso a esa tienda.');
    }
}

function saveProduct(PDO $pdo, array $user): int
{
    if (!can($user, 'catalog.write')) {
        throw new RuntimeException('No tienes permiso para modificar catálogo.');
    }
    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $storeId = filter_var($_POST['store_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($storeId === false) {
        throw new RuntimeException('Selecciona una tienda.');
    }
    assertStoreAccess($pdo, $user, (int) $storeId);

    $name = trim((string) ($_POST['name'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $type = trim((string) ($_POST['type'] ?? ''));
    $brand = trim((string) ($_POST['brand'] ?? ''));
    $color = trim((string) ($_POST['color'] ?? ''));
    $gender = trim((string) ($_POST['gender'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $canManageCost = can($user, 'catalog.cost');
    $canManagePrice = can($user, 'catalog.price');
    $supplierNetPrice = $canManageCost ? filter_var($_POST['supplier_net_price'] ?? null, FILTER_VALIDATE_FLOAT) : null;
    $supplierVatRate = $canManageCost ? filter_var($_POST['supplier_vat_rate'] ?? null, FILTER_VALIDATE_FLOAT) : null;
    $price = $canManagePrice ? filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT) : null;
    $salePriceRaw = trim((string) ($_POST['sale_price'] ?? ''));
    $salePrice = $canManagePrice && $salePriceRaw !== '' ? filter_var($salePriceRaw, FILTER_VALIDATE_FLOAT) : null;
    $active = isset($_POST['active']) ? 1 : 0;
    $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
    $uploadedImage = uploadImage();
    if ($uploadedImage !== null) {
        $imageUrl = $uploadedImage;
    }
    if ($name === '' || $category === '' || $type === '') {
        throw new RuntimeException('Nombre, categoría y tipo son obligatorios.');
    }
    if ($canManageCost && ($supplierNetPrice === false || $supplierNetPrice < 0)) {
        throw new RuntimeException('El importe neto del proveedor debe ser válido.');
    }
    if ($canManageCost && ($supplierVatRate === false || $supplierVatRate < 0 || $supplierVatRate > 100)) {
        throw new RuntimeException('El porcentaje de IVA debe estar entre 0 y 100.');
    }
    if ($canManagePrice && $productId !== false && ($price === false || $price < 0)) {
        throw new RuntimeException('El precio debe ser válido.');
    }
    if ($canManagePrice && $salePriceRaw !== '' && ($salePrice === false || $salePrice < 0)) {
        throw new RuntimeException('El precio promocional debe ser válido.');
    }

    $sizes = $_POST['variant_size'] ?? [];
    $stocks = $_POST['variant_stock'] ?? [];
    $skus = $_POST['variant_sku'] ?? [];
    $variantIds = $_POST['variant_id'] ?? [];
    $variants = [];
    foreach (is_array($sizes) ? $sizes : [] as $index => $rawSize) {
        $size = trim((string) $rawSize);
        $stock = filter_var($stocks[$index] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $sku = strtoupper(trim((string) ($skus[$index] ?? '')));
        if ($size === '' && ($stock === false || $sku === '')) {
            continue;
        }
        if ($size === '' || $stock === false) {
            throw new RuntimeException('Cada variante debe tener talla y cantidad válida.');
        }
        $variantId = filter_var($variantIds[$index] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $variants[] = ['id' => $variantId === false ? null : (int) $variantId, 'size' => $size, 'stock' => (int) $stock, 'sku' => $sku];
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
        $existingVariantIds = [];
        if ($productId !== false) {
            $statement = $pdo->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $statement->execute([(int) $productId]);
            $existingProduct = $statement->fetch();
            if (!is_array($existingProduct)) {
                throw new RuntimeException('Producto no encontrado.');
            }
            assertStoreAccess($pdo, $user, (int) $existingProduct['store_id']);
            if ($canManageCost) {
                $supplierVatAmount = round((float) $supplierNetPrice * (float) $supplierVatRate / 100, 2);
                $supplierTotalPrice = round((float) $supplierNetPrice + $supplierVatAmount, 2);
            } else {
                $supplierNetPrice = (float) $existingProduct['supplier_net_price'];
                $supplierVatRate = (float) $existingProduct['supplier_vat_rate'];
                $supplierVatAmount = (float) $existingProduct['supplier_vat_amount'];
                $supplierTotalPrice = (float) $existingProduct['supplier_total_price'];
            }
            if (!$canManagePrice) {
                $price = (float) $existingProduct['price'];
                $salePrice = $existingProduct['sale_price'] === null ? null : (float) $existingProduct['sale_price'];
            }
            $statement = $pdo->prepare('UPDATE products SET store_id = ?, sku = ?, name = ?, description = ?, category = ?, type = ?, brand = ?, color = ?, gender = ?, aliases = ?, unit = ?, supplier_net_price = ?, supplier_vat_rate = ?, supplier_vat_amount = ?, supplier_total_price = ?, price = ?, sale_price = ?, stock = ?, image_url = ?, active = ? WHERE id = ?');
            $statement->execute([(int) $storeId, $primarySku, $name, $description, $category, $type, $brand ?: null, $color ?: null, $gender ?: null, $aliases, $type, $supplierNetPrice, $supplierVatRate, $supplierVatAmount, $supplierTotalPrice, $price, $salePrice, $totalStock, $imageUrl ?: null, $active, (int) $productId]);
            $savedId = (int) $productId;
            $statement = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ? FOR UPDATE');
            $statement->execute([$savedId]);
            $existingVariantIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $supplierNetPrice = $canManageCost ? (float) $supplierNetPrice : 0.0;
            $supplierVatRate = $canManageCost ? (float) $supplierVatRate : 0.0;
            $supplierVatAmount = round($supplierNetPrice * $supplierVatRate / 100, 2);
            $supplierTotalPrice = round($supplierNetPrice + $supplierVatAmount, 2);
            $price = round($supplierTotalPrice * 1.30, 2);
            $statement = $pdo->prepare('INSERT INTO products (store_id, sku, name, description, category, type, brand, color, gender, aliases, unit, supplier_net_price, supplier_vat_rate, supplier_vat_amount, supplier_total_price, price, sale_price, stock, image_url, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)');
            $statement->execute([(int) $storeId, $primarySku, $name, $description, $category, $type, $brand ?: null, $color ?: null, $gender ?: null, $aliases, $type, $supplierNetPrice, $supplierVatRate, $supplierVatAmount, $supplierTotalPrice, $price, $totalStock, $imageUrl ?: null, $active]);
            $savedId = (int) $pdo->lastInsertId();
        }
        $variantInsert = $pdo->prepare('INSERT INTO product_variants (product_id, sku, size, stock, active) VALUES (?, ?, ?, ?, ?)');
        $variantUpdate = $pdo->prepare('UPDATE product_variants SET sku = ?, size = ?, stock = ?, active = ? WHERE id = ? AND product_id = ?');
        $savedVariantIds = [];
        foreach ($variants as $variant) {
            if ($variant['id'] !== null && in_array($variant['id'], $existingVariantIds, true)) {
                $variantUpdate->execute([$variant['sku'], $variant['size'], $variant['stock'], $active, $variant['id'], $savedId]);
                $savedVariantIds[] = $variant['id'];
            } else {
                $variantInsert->execute([$savedId, $variant['sku'], $variant['size'], $variant['stock'], $active]);
                $savedVariantIds[] = (int) $pdo->lastInsertId();
            }
        }
        $removedVariantIds = array_values(array_diff($existingVariantIds, $savedVariantIds));
        if ($removedVariantIds !== []) {
            $placeholders = implode(',', array_fill(0, count($removedVariantIds), '?'));
            $pdo->prepare("UPDATE product_variants SET stock = 0, active = FALSE WHERE product_id = ? AND id IN ({$placeholders})")
                ->execute(array_merge([$savedId], $removedVariantIds));
        }
        if ($imageUrl !== '') {
            $pdo->prepare('UPDATE product_images SET is_primary = FALSE WHERE product_id = ?')->execute([$savedId]);
            $pdo->prepare('INSERT INTO product_images (product_id, image_path, image_url, is_primary) VALUES (?, ?, ?, TRUE)')
                ->execute([$savedId, str_starts_with($imageUrl, 'uploads/') ? $imageUrl : null, $imageUrl]);
        }
        $pdo->prepare('INSERT INTO order_events (order_id, event_type, actor, details) SELECT id, ?, ?, ? FROM orders WHERE 1=0')
            ->execute(['CATALOG_UPDATED', (string) $user['email'], '{}']);
        $pdo->commit();
        return $savedId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function saveProductPrice(PDO $pdo, array $user): int
{
    if (!can($user, 'catalog.price')) {
        throw new RuntimeException('No tienes permiso para modificar el precio de venta.');
    }
    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $salePriceRaw = trim((string) ($_POST['sale_price'] ?? ''));
    $salePrice = $salePriceRaw === '' ? null : filter_var($salePriceRaw, FILTER_VALIDATE_FLOAT);
    if ($productId === false || $price === false || $price < 0) {
        throw new RuntimeException('Producto y precio normal deben ser válidos.');
    }
    if ($salePriceRaw !== '' && ($salePrice === false || $salePrice < 0)) {
        throw new RuntimeException('El precio promocional debe ser válido.');
    }
    $statement = $pdo->prepare('SELECT store_id FROM products WHERE id = ? AND deleted_at IS NULL');
    $statement->execute([(int) $productId]);
    $storeId = $statement->fetchColumn();
    if ($storeId === false) {
        throw new RuntimeException('Producto no encontrado.');
    }
    assertStoreAccess($pdo, $user, (int) $storeId);
    $pdo->prepare('UPDATE products SET price = ?, sale_price = ? WHERE id = ?')
        ->execute([(float) $price, $salePrice, (int) $productId]);
    return (int) $productId;
}

function deleteProduct(PDO $pdo, array $user): void
{
    if (!can($user, 'catalog.write')) {
        throw new RuntimeException('No tienes permiso para eliminar productos.');
    }
    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($productId === false) {
        throw new RuntimeException('Producto no válido.');
    }
    $statement = $pdo->prepare('SELECT store_id FROM products WHERE id = ? AND deleted_at IS NULL');
    $statement->execute([(int) $productId]);
    $storeId = $statement->fetchColumn();
    if ($storeId === false) {
        throw new RuntimeException('Producto no encontrado.');
    }
    assertStoreAccess($pdo, $user, (int) $storeId);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE products SET active = FALSE, deleted_at = NOW() WHERE id = ?')->execute([(int) $productId]);
        $pdo->prepare('UPDATE product_variants SET active = FALSE WHERE product_id = ?')->execute([(int) $productId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function restockInventory(PDO $pdo, array $user): void
{
    if (!can($user, 'inventory.restock')) {
        throw new RuntimeException('No tienes permiso para alimentar inventario.');
    }
    $variantId = filter_var($_POST['variant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $notes = trim((string) ($_POST['notes'] ?? ''));
    if ($variantId === false || $quantity === false) {
        throw new RuntimeException('Selecciona un producto y una cantidad mayor que cero.');
    }
    if (strlen($notes) > 500) {
        throw new RuntimeException('Las notas no pueden superar 500 caracteres.');
    }

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT pv.id AS variant_id, pv.product_id, p.store_id
             FROM product_variants pv
             JOIN products p ON p.id = pv.product_id
             WHERE pv.id = ? AND pv.active = TRUE AND p.active = TRUE AND p.deleted_at IS NULL
             FOR UPDATE'
        );
        $statement->execute([(int) $variantId]);
        $variant = $statement->fetch();
        if (!is_array($variant)) {
            throw new RuntimeException('Producto o variante no disponible.');
        }
        assertStoreAccess($pdo, $user, (int) $variant['store_id']);

        $pdo->prepare('UPDATE product_variants SET stock = stock + ? WHERE id = ?')
            ->execute([(int) $quantity, (int) $variantId]);
        $pdo->prepare('UPDATE products SET stock = (SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = products.id) WHERE id = ?')
            ->execute([(int) $variant['product_id']]);
        $pdo->prepare('INSERT INTO inventory_receipts (store_id, product_id, variant_id, quantity, actor_user_id, notes) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(int) $variant['store_id'], (int) $variant['product_id'], (int) $variantId, (int) $quantity, (int) $user['id'], $notes ?: null]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function transferStock(PDO $pdo, array $user): void
{
    if (!can($user, 'inventory.transfer')) {
        throw new RuntimeException('Solo el gerente de ciudad y el admin global pueden trasladar existencias entre tiendas.');
    }
    $variantId = filter_var($_POST['from_variant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $toStoreId = filter_var($_POST['to_store_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $notes = trim((string) ($_POST['notes'] ?? ''));
    if ($variantId === false || $toStoreId === false || $quantity === false) {
        throw new RuntimeException('Selecciona variante, tienda destino y cantidad válida.');
    }

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT pv.*, p.store_id, p.name, p.description, p.category, p.type, p.brand, p.color, p.gender, p.aliases, p.unit, p.supplier_net_price, p.supplier_vat_rate, p.supplier_vat_amount, p.supplier_total_price, p.price, p.sale_price, p.image_url, s.city_id
             FROM product_variants pv
             JOIN products p ON p.id = pv.product_id
             JOIN stores s ON s.id = p.store_id
             WHERE pv.id = ? AND p.deleted_at IS NULL
             FOR UPDATE'
        );
        $statement->execute([(int) $variantId]);
        $source = $statement->fetch();
        if (!is_array($source)) {
            throw new RuntimeException('Variante origen no encontrada.');
        }
        assertStoreAccess($pdo, $user, (int) $source['store_id']);
        assertStoreAccess($pdo, $user, (int) $toStoreId);
        if ((int) $source['stock'] < (int) $quantity) {
            throw new RuntimeException('No hay stock suficiente para trasladar.');
        }
        if ((int) $source['store_id'] === (int) $toStoreId) {
            throw new RuntimeException('La tienda destino debe ser diferente.');
        }

        $statement = $pdo->prepare('SELECT city_id FROM stores WHERE id = ?');
        $statement->execute([(int) $toStoreId]);
        $targetCityId = $statement->fetchColumn();
        if ($targetCityId === false) {
            throw new RuntimeException('Tienda destino no encontrada.');
        }
        if (!hasScope($user, 'GLOBAL') && (int) $targetCityId !== (int) $source['city_id']) {
            throw new RuntimeException('El gerente de ciudad solo puede trasladar existencias dentro de la misma ciudad.');
        }

        $statement = $pdo->prepare(
            'SELECT id FROM products
             WHERE store_id = ? AND deleted_at IS NULL
               AND name = ? AND category = ? AND type = ?
               AND COALESCE(brand, "") = COALESCE(?, "")
               AND COALESCE(color, "") = COALESCE(?, "")
               AND COALESCE(gender, "") = COALESCE(?, "")
             LIMIT 1'
        );
        $statement->execute([(int) $toStoreId, $source['name'], $source['category'], $source['type'], $source['brand'], $source['color'], $source['gender']]);
        $targetProductId = $statement->fetchColumn();
        if ($targetProductId === false) {
            $targetSku = substr((string) $source['sku'] . '-S' . (int) $toStoreId, 0, 64);
            $suffix = 1;
            while (true) {
                $statement = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
                $statement->execute([$targetSku]);
                if ((int) $statement->fetchColumn() === 0) {
                    break;
                }
                $targetSku = substr((string) $source['sku'] . '-S' . (int) $toStoreId . '-' . $suffix, 0, 64);
                $suffix++;
            }
            $statement = $pdo->prepare('INSERT INTO products (store_id, sku, name, description, category, type, brand, color, gender, aliases, unit, supplier_net_price, supplier_vat_rate, supplier_vat_amount, supplier_total_price, price, sale_price, stock, image_url, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, TRUE)');
            $statement->execute([(int) $toStoreId, $targetSku, $source['name'], $source['description'], $source['category'], $source['type'], $source['brand'], $source['color'], $source['gender'], $source['aliases'], $source['unit'], $source['supplier_net_price'], $source['supplier_vat_rate'], $source['supplier_vat_amount'], $source['supplier_total_price'], $source['price'], $source['sale_price'], $source['image_url']]);
            $targetProductId = (int) $pdo->lastInsertId();
            if ($source['image_url']) {
                $pdo->prepare('INSERT INTO product_images (product_id, image_url, is_primary) VALUES (?, ?, TRUE)')
                    ->execute([(int) $targetProductId, $source['image_url']]);
            }
        }

        $statement = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ? AND size = ? LIMIT 1 FOR UPDATE');
        $statement->execute([(int) $targetProductId, $source['size']]);
        $targetVariantId = $statement->fetchColumn();
        if ($targetVariantId === false) {
            $targetVariantSku = substr((string) $source['sku'] . '-S' . (int) $toStoreId, 0, 64);
            $suffix = 1;
            while (true) {
                $statement = $pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE sku = ?');
                $statement->execute([$targetVariantSku]);
                if ((int) $statement->fetchColumn() === 0) {
                    break;
                }
                $targetVariantSku = substr((string) $source['sku'] . '-S' . (int) $toStoreId . '-' . $suffix, 0, 64);
                $suffix++;
            }
            $statement = $pdo->prepare('INSERT INTO product_variants (product_id, sku, size, stock, active) VALUES (?, ?, ?, 0, TRUE)');
            $statement->execute([(int) $targetProductId, $targetVariantSku, $source['size']]);
            $targetVariantId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('UPDATE product_variants SET stock = stock - ? WHERE id = ?')->execute([(int) $quantity, (int) $variantId]);
        $pdo->prepare('UPDATE product_variants SET stock = stock + ?, active = TRUE WHERE id = ?')->execute([(int) $quantity, (int) $targetVariantId]);
        $pdo->prepare('UPDATE products SET stock = (SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = products.id), active = TRUE WHERE id IN (?, ?)')
            ->execute([(int) $source['product_id'], (int) $targetProductId]);
        $pdo->prepare('INSERT INTO stock_transfers (from_store_id, to_store_id, from_product_id, from_variant_id, to_product_id, to_variant_id, quantity, actor_user_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(int) $source['store_id'], (int) $toStoreId, (int) $source['product_id'], (int) $variantId, (int) $targetProductId, (int) $targetVariantId, (int) $quantity, (int) $user['id'], $notes ?: null]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function orderItemsForUpdate(PDO $pdo, int $orderId): array
{
    $statement = $pdo->prepare(
        'SELECT product_id, variant_id, sku, size, quantity
         FROM order_items
         WHERE order_id = ?
         ORDER BY id
         FOR UPDATE'
    );
    $statement->execute([$orderId]);
    return $statement->fetchAll();
}

function restoreOrderInventory(PDO $pdo, array $items): void
{
    $variantProductIds = [];
    foreach ($items as $item) {
        $quantity = (int) $item['quantity'];
        $productId = (int) $item['product_id'];
        $variantId = $item['variant_id'] === null ? null : (int) $item['variant_id'];

        if ($variantId !== null) {
            $statement = $pdo->prepare('UPDATE product_variants SET stock = stock + ?, active = TRUE WHERE id = ? AND product_id = ?');
            $statement->execute([$quantity, $variantId, $productId]);
            if ($statement->rowCount() !== 1) {
                $statement = $pdo->prepare(
                    'SELECT id FROM product_variants
                     WHERE product_id = ? AND (sku = ? OR size = ?)
                     ORDER BY (sku = ?) DESC, id
                     LIMIT 1 FOR UPDATE'
                );
                $statement->execute([$productId, (string) $item['sku'], (string) $item['size'], (string) $item['sku']]);
                $replacementVariantId = $statement->fetchColumn();
                if ($replacementVariantId === false) {
                    $restoredSku = 'RESTORED-' . $productId . '-' . bin2hex(random_bytes(6));
                    $statement = $pdo->prepare('INSERT INTO product_variants (product_id, sku, size, stock, active) VALUES (?, ?, ?, ?, TRUE)');
                    $statement->execute([$productId, $restoredSku, trim((string) $item['size']) ?: 'Restaurada', $quantity]);
                } else {
                    $statement = $pdo->prepare('UPDATE product_variants SET stock = stock + ?, active = TRUE WHERE id = ?');
                    $statement->execute([$quantity, (int) $replacementVariantId]);
                }
            }
        } else {
            $statement = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
            $statement->execute([$quantity, $productId]);
        }
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException("No se pudo devolver al inventario el producto #{$productId}.");
        }
        if ($variantId !== null) {
            $variantProductIds[$productId] = true;
        }
    }

    if ($variantProductIds !== []) {
        $productIds = array_keys($variantProductIds);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $pdo->prepare(
            "UPDATE products p
             SET p.stock = (SELECT COALESCE(SUM(pv.stock), 0) FROM product_variants pv WHERE pv.product_id = p.id)
             WHERE p.id IN ({$placeholders})"
        )->execute($productIds);
    }
}

function cancelOrder(PDO $pdo, array $user): int
{
    if (!can($user, 'orders.cancel')) {
        throw new RuntimeException('No tienes permiso para cancelar pedidos.');
    }
    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($orderId === false) {
        throw new RuntimeException('Pedido no válido.');
    }
    assertOrderAccess($pdo, $user, (int) $orderId);

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
        $statement->execute([(int) $orderId]);
        $status = $statement->fetchColumn();
        if (!is_string($status)) {
            throw new RuntimeException('Pedido no encontrado.');
        }
        if ($status === 'CANCELLED') {
            throw new RuntimeException('El pedido ya está cancelado.');
        }
        if (in_array($status, ['CONFIRMED', 'DISPATCHED'], true)) {
            restoreOrderInventory($pdo, orderItemsForUpdate($pdo, (int) $orderId));
        }
        $pdo->prepare("UPDATE orders SET status = 'CANCELLED' WHERE id = ?")->execute([(int) $orderId]);
        $pdo->prepare('INSERT INTO order_events (order_id, event_type, actor, details) VALUES (?, ?, ?, ?)')
            ->execute([(int) $orderId, 'ORDER_CANCELLED', (string) $user['email'], json_encode(['stock_restored' => in_array($status, ['CONFIRMED', 'DISPATCHED'], true), 'source' => 'sales-backoffice'], JSON_THROW_ON_ERROR)]);
        $pdo->commit();
        return (int) $orderId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function deleteOrder(PDO $pdo, array $user): int
{
    if (!can($user, 'orders.delete')) {
        throw new RuntimeException('Solo el admin global puede eliminar pedidos.');
    }
    requireDestructiveConfirmation();
    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($orderId === false) {
        throw new RuntimeException('Pedido no válido.');
    }

    $paymentProofUrl = '';
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT status, payment_proof_url FROM orders WHERE id = ? FOR UPDATE');
        $statement->execute([(int) $orderId]);
        $order = $statement->fetch();
        if (!is_array($order)) {
            throw new RuntimeException('Pedido no encontrado.');
        }
        $paymentProofUrl = (string) ($order['payment_proof_url'] ?? '');

        if (in_array((string) $order['status'], ['CONFIRMED', 'DISPATCHED'], true)) {
            restoreOrderInventory($pdo, orderItemsForUpdate($pdo, (int) $orderId));
        }

        $pdo->prepare('UPDATE conversations SET active_order_id = NULL WHERE active_order_id = ?')->execute([(int) $orderId]);
        $pdo->prepare('DELETE FROM order_events WHERE order_id = ?')->execute([(int) $orderId]);
        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([(int) $orderId]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([(int) $orderId]);
        $pdo->commit();
        removeLocalUploads([$paymentProofUrl]);
        return (int) $orderId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function saveLocation(PDO $pdo): void
{
    $kind = (string) ($_POST['location_kind'] ?? '');
    if ($kind === 'city') {
        $name = trim((string) ($_POST['city_name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Nombre de ciudad obligatorio.');
        }
        $pdo->prepare('INSERT INTO cities (name, active) VALUES (?, TRUE) ON DUPLICATE KEY UPDATE active = TRUE')->execute([$name]);
        return;
    }
    if ($kind === 'zone') {
        $cityId = filter_var($_POST['city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $name = trim((string) ($_POST['zone_name'] ?? ''));
        if ($cityId === false || $name === '') {
            throw new RuntimeException('Ciudad y zona son obligatorias.');
        }
        $pdo->prepare('INSERT INTO zones (city_id, name, active) VALUES (?, ?, TRUE) ON DUPLICATE KEY UPDATE active = TRUE')->execute([$cityId, $name]);
        return;
    }
    if ($kind === 'store') {
        $cityId = filter_var($_POST['city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $zoneId = filter_var($_POST['zone_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $name = trim((string) ($_POST['store_name'] ?? ''));
        $address = trim((string) ($_POST['store_address'] ?? ''));
        $phone = trim((string) ($_POST['store_phone'] ?? ''));
        if ($cityId === false || $name === '') {
            throw new RuntimeException('Ciudad y tienda son obligatorias.');
        }
        $pdo->prepare('INSERT INTO stores (city_id, zone_id, name, address, phone, active) VALUES (?, ?, ?, ?, ?, TRUE) ON DUPLICATE KEY UPDATE zone_id = VALUES(zone_id), address = VALUES(address), phone = VALUES(phone), active = TRUE')
            ->execute([$cityId, $zoneId === false ? null : $zoneId, $name, $address ?: null, $phone ?: null]);
        return;
    }
    throw new RuntimeException('Ubicación no válida.');
}

function saveUser(PDO $pdo): int
{
    $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $roleCodes = array_values(array_unique(array_filter(array_map(
        static fn ($role): string => strtoupper(trim((string) $role)),
        is_array($_POST['role_codes'] ?? null) ? $_POST['role_codes'] : []
    ))));
    $password = (string) ($_POST['password'] ?? '');
    $active = isset($_POST['active']) ? 1 : 0;
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $roleCodes === []) {
        throw new RuntimeException('Nombre, email y al menos un rol son obligatorios.');
    }
    $placeholders = implode(',', array_fill(0, count($roleCodes), '?'));
    $statement = $pdo->prepare("SELECT code, scope_level FROM roles WHERE active = TRUE AND code IN ({$placeholders})");
    $statement->execute($roleCodes);
    $selectedRoles = $statement->fetchAll();
    if (count($selectedRoles) !== count($roleCodes)) {
        throw new RuntimeException('Uno o más roles no son válidos.');
    }
    $selectedScopes = array_column($selectedRoles, 'scope_level');
    $hasGlobalScope = in_array('GLOBAL', $selectedScopes, true);
    $hasCityScope = in_array('CITY', $selectedScopes, true);
    $hasStoreScope = in_array('STORE', $selectedScopes, true);
    $assignedCityIds = normalizeIds($_POST['assigned_city_ids'] ?? []);
    $assignedStoreIds = normalizeIds($_POST['assigned_store_ids'] ?? []);
    if ($hasGlobalScope) {
        $assignedCityIds = [];
        $assignedStoreIds = [];
    } elseif (!$hasStoreScope) {
        $assignedStoreIds = [];
    }
    if ($assignedStoreIds !== []) {
        $placeholders = implode(',', array_fill(0, count($assignedStoreIds), '?'));
        $statement = $pdo->prepare("SELECT DISTINCT city_id FROM stores WHERE id IN ({$placeholders})");
        $statement->execute($assignedStoreIds);
        $storeCityIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $assignedCityIds = array_values(array_unique(array_merge($assignedCityIds, $storeCityIds)));
    }
    if (!$hasGlobalScope && $hasStoreScope && $assignedStoreIds === []) {
        throw new RuntimeException('Los roles con alcance de tienda deben tener al menos una tienda asignada.');
    }
    if (!$hasGlobalScope && $hasCityScope && $assignedCityIds === []) {
        throw new RuntimeException('Los roles con alcance de ciudad deben tener al menos una ciudad asignada.');
    }
    $isAdmin = in_array('ADMIN', $roleCodes, true);
    $legacyRole = $isAdmin ? 'ADMIN' : $roleCodes[0];
    if ($userId !== false && (!$isAdmin || $active !== 1)) {
        $statement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id AND r.code = 'ADMIN'
             WHERE u.active = TRUE AND u.id <> ?"
        );
        $statement->execute([(int) $userId]);
        $otherActiveAdmins = (int) $statement->fetchColumn();
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.code = 'ADMIN'"
        );
        $statement->execute([(int) $userId]);
        if ((int) $statement->fetchColumn() > 0 && $otherActiveAdmins === 0) {
            throw new RuntimeException('No puedes retirar o desactivar el último admin global activo.');
        }
    }

    $pdo->beginTransaction();
    try {
        if ($userId !== false) {
            if ($password !== '') {
                $statement = $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ?, active = ?, password_hash = ? WHERE id = ?');
                $statement->execute([$name, $email, $legacyRole, $active, password_hash($password, PASSWORD_DEFAULT), (int) $userId]);
            } else {
                $statement = $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ?, active = ? WHERE id = ?');
                $statement->execute([$name, $email, $legacyRole, $active, (int) $userId]);
            }
            $savedId = (int) $userId;
            $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$savedId]);
            $pdo->prepare('DELETE FROM user_store_assignments WHERE user_id = ?')->execute([$savedId]);
            $pdo->prepare('DELETE FROM user_city_assignments WHERE user_id = ?')->execute([$savedId]);
        } else {
            if ($password === '') {
                throw new RuntimeException('La contraseña es obligatoria para usuarios nuevos.');
            }
            $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $legacyRole, $active]);
            $savedId = (int) $pdo->lastInsertId();
        }

        $roleStatement = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = ? AND active = TRUE');
        foreach ($roleCodes as $roleCode) {
            $roleStatement->execute([$savedId, $roleCode]);
        }
        $cityStatement = $pdo->prepare('INSERT IGNORE INTO user_city_assignments (user_id, city_id) VALUES (?, ?)');
        foreach ($assignedCityIds as $cityId) {
            $cityStatement->execute([$savedId, $cityId]);
        }
        $storeStatement = $pdo->prepare('INSERT IGNORE INTO user_store_assignments (user_id, store_id) VALUES (?, ?)');
        foreach ($assignedStoreIds as $storeId) {
            $storeStatement->execute([$savedId, $storeId]);
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

function assertOrderAccess(PDO $pdo, array $user, int $orderId): void
{
    if (hasScope($user, 'GLOBAL')) {
        return;
    }
    $statement = $pdo->prepare('SELECT store_id FROM orders WHERE id = ?');
    $statement->execute([$orderId]);
    $storeId = $statement->fetchColumn();
    if ($storeId === false || $storeId === null || !in_array((int) $storeId, assignedStoreIds($pdo, $user), true)) {
        throw new RuntimeException('No tienes acceso a ese pedido.');
    }
}

$pdo = null;
$databaseError = null;
try {
    $pdo = database();
    ensureBootstrapAdmin($pdo);
} catch (Throwable $error) {
    $databaseError = $error->getMessage();
}

$view = (string) ($_GET['view'] ?? $_POST['view'] ?? 'orders');
$selectedStatus = strtoupper((string) ($_GET['status'] ?? $_POST['return_status'] ?? 'ALL'));
if (!in_array($selectedStatus, ORDER_STATUSES, true)) {
    $selectedStatus = 'ALL';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login' && $pdo instanceof PDO) {
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf'], $csrf)) {
        redirectWithFlash($basePath, 'La sesión expiró. Vuelve a intentarlo.', 'error', 'login');
    }
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $statement = $pdo->prepare('SELECT * FROM users WHERE email = ? AND active = TRUE');
    $statement->execute([$email]);
    $loginUser = $statement->fetch();
    if (is_array($loginUser) && password_verify($password, (string) $loginUser['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $loginUser['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $loginUser['id']]);
        redirectWithFlash($basePath, 'Sesión iniciada.', 'success');
    }
    redirectWithFlash($basePath, 'Credenciales no válidas.', 'error', 'login');
}

if (($_GET['logout'] ?? '') === '1') {
    session_destroy();
    header('Location: ' . $basePath, true, 303);
    exit;
}

$user = $pdo instanceof PDO ? currentUser($pdo) : null;
$allowedViews = $user === null ? ['login'] : [];
if ($user !== null && can($user, 'orders.view')) {
    $allowedViews[] = 'orders';
}
if ($user !== null && (can($user, 'catalog.write') || can($user, 'catalog.price') || can($user, 'catalog.cost'))) {
    $allowedViews[] = 'catalog';
}
if ($user !== null && can($user, 'shipments.view')) {
    $allowedViews[] = 'shipments';
}
if ($user !== null && can($user, 'inventory.view')) {
    $allowedViews[] = 'inventory';
}
if ($user !== null && can($user, 'stats.view')) {
    $allowedViews[] = 'stats';
}
if ($user !== null && can($user, 'locations.manage')) {
    $allowedViews[] = 'locations';
}
if ($user !== null && can($user, 'users.manage')) {
    $allowedViews[] = 'users';
}
if ($user === null) {
    $view = 'login';
} elseif (!in_array($view, $allowedViews, true)) {
    $view = $allowedViews[0] ?? 'no_access';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null && $pdo instanceof PDO) {
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf'], $csrf)) {
        redirectWithFlash($basePath, 'La sesión expiró. Vuelve a intentarlo.', 'error', $view);
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_product') {
            $savedId = saveProduct($pdo, $user);
            redirectWithFlash($basePath, "Producto #{$savedId} guardado.", 'success', 'catalog', ['edit' => $savedId]);
        }
        if ($action === 'save_product_price') {
            $savedId = saveProductPrice($pdo, $user);
            redirectWithFlash($basePath, "Precio del producto #{$savedId} actualizado.", 'success', 'catalog');
        }
        if ($action === 'toggle_product') {
            if (!can($user, 'catalog.write')) {
                throw new RuntimeException('No tienes permiso para modificar catálogo.');
            }
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($productId === false) {
                throw new RuntimeException('Producto no válido.');
            }
            $statement = $pdo->prepare('SELECT store_id FROM products WHERE id = ?');
            $statement->execute([(int) $productId]);
            $storeId = $statement->fetchColumn();
            if ($storeId === false) {
                throw new RuntimeException('Producto no encontrado.');
            }
            assertStoreAccess($pdo, $user, (int) $storeId);
            $pdo->prepare('UPDATE products SET active = NOT active WHERE id = ?')->execute([(int) $productId]);
            $pdo->prepare('UPDATE product_variants SET active = (SELECT active FROM products WHERE products.id = product_variants.product_id) WHERE product_id = ?')->execute([(int) $productId]);
            redirectWithFlash($basePath, "Producto #{$productId} actualizado.", 'success', 'catalog');
        }
        if ($action === 'delete_product') {
            deleteProduct($pdo, $user);
            redirectWithFlash($basePath, 'Producto eliminado del catálogo.', 'success', 'catalog');
        }
        if ($action === 'restock_inventory') {
            restockInventory($pdo, $user);
            redirectWithFlash($basePath, 'Entrada de inventario registrada.', 'success', 'inventory');
        }
        if ($action === 'transfer_stock') {
            transferStock($pdo, $user);
            redirectWithFlash($basePath, 'Existencias trasladadas.', 'success', 'catalog');
        }
        if ($action === 'save_location') {
            if (!can($user, 'locations.manage')) {
                throw new RuntimeException('No tienes permiso para administrar ubicaciones.');
            }
            saveLocation($pdo);
            redirectWithFlash($basePath, 'Ubicación guardada.', 'success', 'locations');
        }
        if ($action === 'save_user') {
            if (!can($user, 'users.manage')) {
                throw new RuntimeException('No tienes permiso para administrar usuarios.');
            }
            $savedId = saveUser($pdo);
            redirectWithFlash($basePath, "Usuario #{$savedId} guardado.", 'success', 'users');
        }
        if ($action === 'cancel_order') {
            $cancelledId = cancelOrder($pdo, $user);
            redirectWithFlash($basePath, "Pedido #{$cancelledId} cancelado. Permanece en el historial y, si había descontado stock, las unidades fueron devueltas.", 'success', 'orders', ['status' => $selectedStatus]);
        }
        if ($action === 'delete_order') {
            $deletedId = deleteOrder($pdo, $user);
            redirectWithFlash($basePath, "Pedido #{$deletedId} eliminado definitivamente. El inventario fue restaurado cuando correspondía.", 'success', 'orders', ['status' => $selectedStatus]);
        }

        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false || !in_array($action, ['approve', 'reject', 'dispatch'], true)) {
            throw new RuntimeException('Acción no válida.');
        }
        if (!can($user, 'orders.approve')) {
            throw new RuntimeException('No tienes permiso para gestionar pedidos.');
        }
        assertOrderAccess($pdo, $user, (int) $orderId);

        if ($action === 'approve') {
            $statement = $pdo->prepare('CALL approve_order(?, ?)');
            $statement->execute([(int) $orderId, (string) $user['email']]);
            $row = $statement->fetch();
            while ($statement->nextRowset()) {
            }
            $result = json_decode((string) ($row['result'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            if (($result['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($result['error'] ?? 'No se pudo aprobar el pedido'));
            }
            $pdo->prepare('UPDATE orders SET payment_reviewed_by_user_id = ? WHERE id = ?')->execute([(int) $user['id'], (int) $orderId]);
            redirectWithFlash($basePath, "Pedido #{$orderId} confirmado y stock descontado.", 'success', 'orders', ['status' => $selectedStatus]);
        }

        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
        $statement->execute([(int) $orderId]);
        $currentStatus = $statement->fetchColumn();
        if (!is_string($currentStatus)) {
            throw new RuntimeException('Pedido no encontrado.');
        }
        if ($action === 'reject') {
            if ($currentStatus !== 'PENDING_PAYMENT') {
                throw new RuntimeException("Solo se puede rechazar un pedido con pago pendiente; ahora está {$currentStatus}");
            }
            $pdo->prepare("UPDATE orders SET status = 'REJECTED', payment_reviewed_by_user_id = ? WHERE id = ?")->execute([(int) $user['id'], (int) $orderId]);
            $eventType = 'PAYMENT_REJECTED';
            $message = "Pedido #{$orderId} rechazado.";
        } else {
            if ($currentStatus !== 'CONFIRMED') {
                throw new RuntimeException("Solo se puede despachar un pedido confirmado; ahora está {$currentStatus}");
            }
            $pdo->prepare("UPDATE orders SET status = 'DISPATCHED', logistics_notified_at = NOW(), dispatched_by_user_id = ? WHERE id = ?")->execute([(int) $user['id'], (int) $orderId]);
            $eventType = 'ORDER_DISPATCHED';
            $message = "Pedido #{$orderId} marcado como despachado.";
        }
        $pdo->prepare('INSERT INTO order_events (order_id, event_type, actor, details) VALUES (?, ?, ?, ?)')
            ->execute([(int) $orderId, $eventType, (string) $user['email'], json_encode(['source' => 'sales-backoffice'], JSON_THROW_ON_ERROR)]);
        $pdo->commit();
        redirectWithFlash($basePath, $message, 'success', 'orders', ['status' => $selectedStatus]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirectWithFlash($basePath, $error->getMessage(), 'error', $view);
    }
}

$counts = array_fill_keys(array_slice(ORDER_STATUSES, 1), 0);
$orders = $products = $shipments = $cities = $zones = $stores = $users = $stats = $transferVariants = $restockVariants = $inventoryStore = $inventoryCity = [];
$visibleStores = [];
$editProduct = null;
$editVariants = [];
$editUser = null;
$availableRoles = $editUserRoleCodes = $editUserCityIds = $editUserStoreIds = [];

if ($pdo instanceof PDO && $user !== null) {
    $cities = $pdo->query('SELECT * FROM cities ORDER BY name')->fetchAll();
    $zones = $pdo->query('SELECT z.*, c.name AS city_name FROM zones z JOIN cities c ON c.id = z.city_id ORDER BY c.name, z.name')->fetchAll();
    $stores = $pdo->query(
        "SELECT s.*, c.name AS city_name, z.name AS zone_name,
          (SELECT COUNT(*) FROM products p WHERE p.store_id = s.id) AS products_count,
          (SELECT COUNT(*) FROM orders o WHERE o.store_id = s.id) AS orders_count
         FROM stores s
         JOIN cities c ON c.id = s.city_id
         LEFT JOIN zones z ON z.id = s.zone_id
         ORDER BY c.name, z.name, s.name"
    )->fetchAll();
    $visibleStores = $stores;
    if (!hasScope($user, 'GLOBAL')) {
        $storeIds = assignedStoreIds($pdo, $user);
        $visibleStores = array_values(array_filter($stores, fn ($store) => in_array((int) $store['id'], $storeIds, true)));
    }

    if (can($user, 'orders.view')) {
        $params = [];
        $where = [scopedWhere($pdo, $user, 'o', $params)];
        if ($selectedStatus !== 'ALL') {
            $where[] = 'o.status = ?';
            $params[] = $selectedStatus;
        }
        $countParams = [];
        $countWhere = scopedWhere($pdo, $user, 'o', $countParams);
        $countSql = "SELECT o.status, COUNT(*) AS total FROM orders o WHERE {$countWhere} GROUP BY o.status";
        $statement = $pdo->prepare($countSql);
        $statement->execute($countParams);
        foreach ($statement->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }
        $sql = 'SELECT o.*, s.name AS store_name, c.name AS city_name, z.name AS zone_name, u.name AS reviewer_name
                FROM orders o
                LEFT JOIN stores s ON s.id = o.store_id
                LEFT JOIN cities c ON c.id = o.city_id
                LEFT JOIN zones z ON z.id = o.zone_id
                LEFT JOIN users u ON u.id = o.payment_reviewed_by_user_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY o.created_at DESC, o.id DESC LIMIT 200';
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $orders = $statement->fetchAll();
        $itemsStatement = $pdo->prepare('SELECT sku, product_name, size, quantity, unit_price, image_url FROM order_items WHERE order_id = ? ORDER BY id');
        foreach ($orders as &$order) {
            $itemsStatement->execute([(int) $order['id']]);
            $order['items'] = $itemsStatement->fetchAll();
        }
        unset($order);
    }

    if (can($user, 'catalog.write') || can($user, 'catalog.price') || can($user, 'catalog.cost')) {
        $params = [];
        $where = scopedWhere($pdo, $user, 'p', $params);
        $statement = $pdo->prepare(
            "SELECT p.*, s.name AS store_name, c.name AS city_name, COALESCE(v.total_variant_stock, p.stock) AS total_variant_stock, v.variant_summary
             FROM products p
             LEFT JOIN stores s ON s.id = p.store_id
             LEFT JOIN cities c ON c.id = s.city_id
             LEFT JOIN (
               SELECT product_id, SUM(stock) AS total_variant_stock, GROUP_CONCAT(CONCAT(size, ': ', stock) ORDER BY id SEPARATOR ', ') AS variant_summary
               FROM product_variants GROUP BY product_id
             ) v ON v.product_id = p.id
             WHERE {$where} AND p.deleted_at IS NULL
             ORDER BY p.updated_at DESC, p.id DESC"
        );
        $statement->execute($params);
        $products = $statement->fetchAll();
        $editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (can($user, 'catalog.write') && $editId !== false && $editId !== null) {
            $params = [(int) $editId];
            $scope = scopedWhere($pdo, $user, 'p', $params);
            $statement = $pdo->prepare("SELECT * FROM products p WHERE p.id = ? AND p.deleted_at IS NULL AND {$scope}");
            $statement->execute($params);
            $editProduct = $statement->fetch() ?: null;
            if ($editProduct !== null) {
                $statement = $pdo->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY id');
                $statement->execute([(int) $editId]);
                $editVariants = $statement->fetchAll();
            }
        }
    }

    if (can($user, 'inventory.transfer')) {
        $params = [];
        $where = scopedWhere($pdo, $user, 'p', $params);
        $statement = $pdo->prepare(
            "SELECT pv.id AS variant_id, pv.stock, pv.size, pv.sku, p.id AS product_id, p.name, p.store_id, s.name AS store_name, c.name AS city_name
             FROM product_variants pv
             JOIN products p ON p.id = pv.product_id
             JOIN stores s ON s.id = p.store_id
             JOIN cities c ON c.id = s.city_id
             WHERE {$where} AND p.deleted_at IS NULL AND pv.active = TRUE AND pv.stock > 0
             ORDER BY c.name, s.name, p.name, pv.size"
        );
        $statement->execute($params);
        $transferVariants = $statement->fetchAll();
    }

    if (can($user, 'inventory.restock')) {
        $params = [];
        $where = scopedWhere($pdo, $user, 'p', $params);
        $statement = $pdo->prepare(
            "SELECT pv.id AS variant_id, pv.stock, pv.size, pv.sku, p.name, s.name AS store_name, c.name AS city_name
             FROM product_variants pv
             JOIN products p ON p.id = pv.product_id
             JOIN stores s ON s.id = p.store_id
             JOIN cities c ON c.id = s.city_id
             WHERE {$where} AND p.deleted_at IS NULL AND p.active = TRUE AND pv.active = TRUE
             ORDER BY c.name, s.name, p.name, pv.size"
        );
        $statement->execute($params);
        $restockVariants = $statement->fetchAll();
    }

    if (can($user, 'inventory.view')) {
        $params = [];
        $where = scopedWhere($pdo, $user, 'ibs', $params);
        $statement = $pdo->prepare(
            "SELECT ibs.*
             FROM inventory_by_store ibs
             WHERE {$where}
             ORDER BY ibs.city_name, ibs.store_name, ibs.product_name, ibs.size"
        );
        $statement->execute($params);
        $inventoryStore = $statement->fetchAll();

        if (hasScope($user, 'GLOBAL')) {
            $statement = $pdo->query('SELECT * FROM inventory_by_city ORDER BY city_name, product_name, size');
            $inventoryCity = $statement->fetchAll();
        } else {
            $params = [];
            $where = scopedWhere($pdo, $user, 'ibs', $params);
            $statement = $pdo->prepare(
                "SELECT ibs.city_id, ibs.city_name, ibs.product_name, ibs.category, ibs.type, ibs.brand, ibs.color, ibs.size,
                        SUM(ibs.stock) AS city_stock,
                        SUM(ibs.reserved_stock) AS city_reserved_stock,
                        COUNT(DISTINCT ibs.store_id) AS stores_with_product
                 FROM inventory_by_store ibs
                 WHERE {$where}
                 GROUP BY ibs.city_id, ibs.city_name, ibs.product_name, ibs.category, ibs.type, ibs.brand, ibs.color, ibs.size
                 ORDER BY ibs.city_name, ibs.product_name, ibs.size"
            );
            $statement->execute($params);
            $inventoryCity = $statement->fetchAll();
        }
    }

    if (can($user, 'shipments.view')) {
        $params = [];
        $where = scopedWhere($pdo, $user, 'o', $params);
        $statement = $pdo->prepare(
            "SELECT o.id AS order_id, o.customer_name, o.phone, o.delivery_address, o.delivery_notes, o.total, o.payment_confirmed_at, s.name AS store_name,
              GROUP_CONCAT(CONCAT(oi.quantity, ' x ', oi.product_name, IF(oi.size IS NULL OR oi.size = '', '', CONCAT(' talla ', oi.size))) ORDER BY oi.id SEPARATOR '\n') AS items
             FROM orders o
             JOIN order_items oi ON oi.order_id = o.id
             LEFT JOIN stores s ON s.id = o.store_id
             WHERE o.status IN ('CONFIRMED','DISPATCHED') AND {$where}
             GROUP BY o.id, o.customer_name, o.phone, o.delivery_address, o.delivery_notes, o.total, o.payment_confirmed_at, s.name
             ORDER BY o.payment_confirmed_at DESC, o.id DESC LIMIT 200"
        );
        $statement->execute($params);
        $shipments = $statement->fetchAll();
    }

    if (can($user, 'stats.view')) {
        $params = [];
        $where = scopedWhere($pdo, $user, 'o', $params);
        $statement = $pdo->prepare(
            "SELECT COUNT(*) AS orders_count, COALESCE(SUM(o.total), 0) AS sales_total, COALESCE(AVG(o.total), 0) AS average_ticket
             FROM orders o WHERE o.status IN ('CONFIRMED','DISPATCHED') AND {$where}"
        );
        $statement->execute($params);
        $stats['summary'] = $statement->fetch() ?: ['orders_count' => 0, 'sales_total' => 0, 'average_ticket' => 0];
        $statement = $pdo->prepare(
            "SELECT s.name AS store_name, COUNT(*) AS orders_count, COALESCE(SUM(o.total), 0) AS sales_total
             FROM orders o LEFT JOIN stores s ON s.id = o.store_id
             WHERE o.status IN ('CONFIRMED','DISPATCHED') AND {$where}
             GROUP BY s.name ORDER BY sales_total DESC LIMIT 20"
        );
        $statement->execute($params);
        $stats['stores'] = $statement->fetchAll();

        $params = [];
        $where = scopedWhere($pdo, $user, 'p', $params);
        $statement = $pdo->prepare(
            "SELECT COALESCE(SUM(pv.stock), 0) AS stock_units,
                    COUNT(DISTINCT p.id) AS products_count,
                    COUNT(DISTINCT p.store_id) AS stores_count
             FROM products p
             JOIN product_variants pv ON pv.product_id = p.id
             WHERE {$where} AND p.deleted_at IS NULL AND p.active = TRUE AND pv.active = TRUE"
        );
        $statement->execute($params);
        $stats['inventory'] = $statement->fetch() ?: ['stock_units' => 0, 'products_count' => 0, 'stores_count' => 0];
    }

    if (can($user, 'users.manage')) {
        $availableRoles = $pdo->query('SELECT code, name, scope_level FROM roles WHERE active = TRUE ORDER BY id')->fetchAll();
        $users = $pdo->query(
            "SELECT u.*, GROUP_CONCAT(r.name ORDER BY r.id SEPARATOR ' · ') AS role_names_text
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             GROUP BY u.id
             ORDER BY u.active DESC, u.name"
        )->fetchAll();
        $editUserId = filter_var($_GET['edit_user'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($editUserId !== false && $editUserId !== null) {
            $statement = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $statement->execute([(int) $editUserId]);
            $editUser = $statement->fetch() ?: null;
            if ($editUser !== null) {
                $statement = $pdo->prepare('SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.id');
                $statement->execute([(int) $editUserId]);
                $editUserRoleCodes = $statement->fetchAll(PDO::FETCH_COLUMN);
                $statement = $pdo->prepare('SELECT city_id FROM user_city_assignments WHERE user_id = ?');
                $statement->execute([(int) $editUserId]);
                $editUserCityIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
                $statement = $pdo->prepare('SELECT store_id FROM user_store_assignments WHERE user_id = ?');
                $statement->execute([(int) $editUserId]);
                $editUserStoreIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            }
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$totalOrders = array_sum($counts);
$formProduct = $editProduct ?? ['id' => '', 'store_id' => $visibleStores[0]['id'] ?? '', 'name' => '', 'description' => '', 'category' => '', 'type' => '', 'brand' => '', 'color' => '', 'gender' => '', 'supplier_net_price' => '', 'supplier_vat_rate' => '', 'supplier_vat_amount' => '', 'supplier_total_price' => '', 'price' => '', 'sale_price' => '', 'image_url' => '', 'aliases' => '[]', 'active' => 1];
if ($editVariants === []) {
    $editVariants = [['id' => '', 'sku' => '', 'size' => '', 'stock' => 0]];
}
$decodedAliases = json_decode((string) ($formProduct['aliases'] ?? '[]'), true);
$aliasesText = is_array($decodedAliases) ? implode("\n", $decodedAliases) : '';
$formUser = $editUser ?? ['id' => '', 'name' => '', 'email' => '', 'active' => 1];
$formRoleCodes = $editUser === null ? ['SELLER'] : $editUserRoleCodes;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ventas WhatsApp</title>
  <style>
    :root { color-scheme:light; --ink:#18221d; --muted:#65716a; --paper:#f4f2eb; --panel:#fff; --line:#dcded7; --green:#1c6b4a; --green-soft:#dceee5; --amber:#966114; --amber-soft:#fff0cf; --red:#a13832; --red-soft:#f9dfdc; --blue:#315f8a; --blue-soft:#e1edf8; --dark:#13251d; }
    * { box-sizing:border-box; }
    body { margin:0; background:var(--paper); color:var(--ink); font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
    header { background:var(--dark); color:#fff; padding:24px clamp(18px,4vw,54px); }
    header .wrap, main { max-width:1440px; margin:auto; }
    header .wrap { display:flex; justify-content:space-between; gap:24px; align-items:end; }
    main { padding:24px clamp(18px,4vw,54px) 60px; }
    h1 { margin:0 0 4px; font-size:clamp(25px,4vw,38px); }
    h2 { margin:0 0 14px; font-size:22px; }
    h3 { margin:0 0 10px; font-size:17px; }
    p { margin:0; } header p { color:#b9c8c0; }
    a { color:var(--green); }
    .tabs,.filters,.actions { display:flex; flex-wrap:wrap; gap:8px; }
    .tabs { margin-bottom:18px; }
    .tab,.filters a { color:var(--ink); background:#e8e6de; padding:9px 13px; border-radius:8px; text-decoration:none; font-size:14px; font-weight:800; }
    .tab.active,.filters a.active { background:var(--dark); color:#fff; }
    .summary,.cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:18px; }
    .metric,.panel,.order,.product { background:var(--panel); border:1px solid var(--line); border-radius:8px; box-shadow:0 4px 18px rgb(30 42 35 / 5%); }
    .metric { padding:16px; text-decoration:none; color:inherit; }
    .metric strong { display:block; font-size:27px; margin-top:4px; }
    .panel { padding:18px; overflow:hidden; }
    .flash,.error-box { padding:13px 16px; border-radius:8px; margin-bottom:18px; border:1px solid; }
    .flash.success { background:var(--green-soft); border-color:#9bcab3; color:#174d37; }
    .flash.error,.error-box { background:var(--red-soft); border-color:#dda7a2; color:#742621; }
    .grid { display:grid; gap:16px; }
    .split { display:grid; grid-template-columns:minmax(320px,.8fr) minmax(380px,1.2fr); gap:18px; align-items:start; }
    .head { padding:16px 18px; border-bottom:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; gap:14px; }
    .title { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .title h2 { font-size:18px; margin:0; }
    .badge { border-radius:999px; padding:5px 9px; font-size:12px; font-weight:800; }
    .PENDING_PAYMENT { background:var(--amber-soft); color:var(--amber); }
    .CONFIRMED,.active-badge { background:var(--green-soft); color:var(--green); }
    .REJECTED,.CANCELLED,.inactive-badge { background:var(--red-soft); color:var(--red); }
    .DISPATCHED { background:var(--blue-soft); color:var(--blue); }
    .body { display:grid; grid-template-columns:minmax(220px,.9fr) minmax(280px,1.5fr); gap:18px; padding:18px; }
    .facts { display:grid; gap:10px; font-size:14px; }
    .fact span,label span { display:block; color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; font-weight:800; }
    .items { display:grid; gap:8px; }
    .item { display:grid; grid-template-columns:52px 1fr auto; gap:11px; align-items:center; background:#f7f7f3; border-radius:8px; padding:8px; }
    .item img,.thumb { width:52px; height:52px; border-radius:8px; object-fit:cover; background:#e6e7e1; }
    .hero-img { width:100%; max-height:230px; object-fit:cover; border-radius:8px; background:#e6e7e1; }
    .muted,.item small { color:var(--muted); }
    .actions { padding:0 18px 18px; }
    .actions form { margin:0; }
    .destructive-confirm { display:flex; align-items:center; gap:7px; padding:6px 9px; border:1px solid #dda7a2; border-radius:8px; color:var(--red); font-size:12px; font-weight:800; }
    .destructive-confirm input { width:auto; margin:0; }
    button,.button { border:0; border-radius:8px; padding:10px 14px; color:#fff; font-weight:800; cursor:pointer; text-decoration:none; display:inline-block; }
    .approve,.primary { background:var(--green); }
    .reject,.danger { background:var(--red); }
    .dispatch,.secondary { background:var(--blue); }
    .neutral { background:#5f6b64; }
    input,textarea,select { width:100%; border:1px solid #cfd3ca; border-radius:8px; padding:10px 11px; font:inherit; background:#fff; color:var(--ink); }
    input[type="checkbox"] { width:auto; margin-right:8px; }
    textarea { min-height:86px; resize:vertical; }
    form.catalog,form.stacked { display:grid; gap:14px; }
    .fields { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
    .wide { grid-column:1/-1; }
    fieldset { min-width:0; margin:0; padding:0; border:0; }
    fieldset legend { color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; margin-bottom:7px; font-weight:800; }
    .role-list { display:grid; gap:7px; }
    .role-option { display:flex; gap:10px; align-items:flex-start; border:1px solid #cfd3ca; border-radius:8px; padding:10px 11px; cursor:pointer; background:#fff; }
    .role-option:has(input:checked) { border-color:var(--green); background:var(--green-soft); }
    .role-option input { margin:3px 0 0; flex:0 0 auto; }
    .role-option strong,.role-option small { display:block; }
    .role-option small { color:var(--muted); margin-top:2px; }
    .price-form { display:grid; grid-template-columns:1fr 1fr auto; align-items:end; gap:9px; }
    [hidden] { display:none !important; }
    .variant-row { display:grid; grid-template-columns:1fr 1fr .8fr; gap:9px; margin-bottom:8px; }
    .empty { text-align:center; background:var(--panel); border:1px dashed #c5c8bf; border-radius:8px; padding:42px 20px; color:var(--muted); }
    table { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--line); border-radius:8px; overflow:hidden; }
    th,td { text-align:left; padding:12px; border-bottom:1px solid var(--line); vertical-align:top; }
    th { background:#eef0ea; font-size:12px; text-transform:uppercase; color:var(--muted); }
    .login { max-width:420px; margin:40px auto; }
    @media (max-width:860px) { header .wrap{align-items:start;flex-direction:column}.split,.body,.fields,.price-form{grid-template-columns:1fr}.summary{grid-template-columns:repeat(2,1fr)}.variant-row{grid-template-columns:1fr} }
  </style>
</head>
<body>
<header>
  <div class="wrap">
    <section>
      <h1>Ventas WhatsApp</h1>
      <p>Catálogo, pagos, usuarios y despacho</p>
    </section>
    <?php if ($user): ?>
      <p><?= escape($user['name']) ?><br><strong><?= escape(roleSummary($user)) ?></strong> · <a href="<?= escape($basePath) ?>?logout=1" style="color:#fff">Salir</a></p>
    <?php endif; ?>
  </div>
</header>
<main>
  <?php if ($flash): ?><div class="flash <?= escape($flash['type']) ?>"><?= escape($flash['message']) ?></div><?php endif; ?>
  <?php if ($databaseError): ?><div class="error-box"><strong>No se pudo consultar MariaDB.</strong><br><?= escape($databaseError) ?></div><?php endif; ?>

  <?php if ($view === 'login'): ?>
    <form class="panel login stacked" method="post" action="<?= escape($basePath) ?>">
      <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="login">
      <h2>Entrar al backoffice</h2>
      <label><span>Email</span><input name="email" type="email" required autocomplete="username"></label>
      <label><span>Contraseña</span><input name="password" type="password" required autocomplete="current-password"></label>
      <button class="primary" type="submit">Entrar</button>
      <p class="muted">Si es la primera instalación, se crea un admin inicial con `OPERATOR_ADMIN_EMAIL` y `OPERATOR_ADMIN_PASSWORD`.</p>
    </form>
  <?php elseif ($user): ?>
    <nav class="tabs">
      <?php foreach ($allowedViews as $allowedView): if ($allowedView === 'login') continue; ?>
        <a class="tab <?= $view === $allowedView ? 'active' : '' ?>" href="<?= escape(viewUrl($basePath, $allowedView)) ?>"><?= escape(match ($allowedView) { 'orders' => 'Pedidos', 'catalog' => 'Catálogo', 'shipments' => 'Envíos', 'inventory' => 'Inventario', 'stats' => 'Estadísticas', 'locations' => 'Ubicaciones', 'users' => 'Usuarios', default => $allowedView }) ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($view === 'catalog'): ?>
      <section class="<?= can($user, 'catalog.write') ? 'split' : 'grid' ?>">
        <?php if (can($user, 'catalog.write')): ?>
        <form class="panel catalog" method="post" enctype="multipart/form-data" action="<?= escape($basePath) ?>">
          <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
          <input type="hidden" name="action" value="save_product">
          <input type="hidden" name="view" value="catalog">
          <input type="hidden" name="product_id" value="<?= escape((string) $formProduct['id']) ?>">
          <h2><?= $editProduct ? 'Editar producto' : 'Crear producto' ?></h2>
          <p class="muted">Cada producto pertenece a una tienda. Esa tienda define quién puede verlo, venderlo y mover sus existencias.</p>
          <div class="fields">
            <label class="wide"><span>Tienda propietaria del producto</span><select name="store_id" required><?php foreach ($visibleStores as $store): ?><option value="<?= (int) $store['id'] ?>" <?= (int) $formProduct['store_id'] === (int) $store['id'] ? 'selected' : '' ?>><?= escape($store['city_name'] . ' · ' . ($store['zone_name'] ?? 'Sin zona') . ' · ' . $store['name']) ?></option><?php endforeach; ?></select><small class="muted">Si necesitas mover unidades entre tiendas de la misma ciudad, usa el traslado de existencias.</small></label>
            <label class="wide"><span>Nombre</span><input name="name" required value="<?= escape((string) $formProduct['name']) ?>"></label>
            <label><span>Categoría</span><input name="category" required value="<?= escape((string) $formProduct['category']) ?>"></label>
            <label><span>Tipo</span><input name="type" required value="<?= escape((string) $formProduct['type']) ?>"></label>
            <label><span>Marca</span><input name="brand" value="<?= escape((string) $formProduct['brand']) ?>"></label>
            <label><span>Color</span><input name="color" value="<?= escape((string) $formProduct['color']) ?>"></label>
            <label><span>Género</span><input name="gender" value="<?= escape((string) $formProduct['gender']) ?>"></label>
            <?php if (can($user, 'catalog.cost')): ?>
              <label><span>Importe neto proveedor</span><input id="supplier-net-price" name="supplier_net_price" required inputmode="decimal" value="<?= escape((string) $formProduct['supplier_net_price']) ?>"></label>
              <label><span>IVA (%)</span><input id="supplier-vat-rate" name="supplier_vat_rate" required inputmode="decimal" value="<?= escape((string) $formProduct['supplier_vat_rate']) ?>"></label>
              <div class="fact"><span>Importe IVA</span><strong id="supplier-vat-amount"><?= moneyExact($formProduct['supplier_vat_amount']) ?></strong></div>
              <div class="fact"><span>Total proveedor con IVA</span><strong id="supplier-total-price"><?= moneyExact($formProduct['supplier_total_price']) ?></strong></div>
              <div class="fact wide"><span>Precio inicial sugerido (+30 %)</span><strong id="suggested-catalog-price"><?= moneyExact($formProduct['id'] ? $formProduct['supplier_total_price'] * 1.30 : 0) ?></strong><small class="muted">Se aplica automáticamente al crear el producto.</small></div>
            <?php else: ?>
              <div class="fact wide"><span>Valores del proveedor</span>Solo el proveedor y el admin global pueden modificarlos.</div>
            <?php endif; ?>
            <?php if (can($user, 'catalog.price') && $editProduct): ?>
              <label><span>Precio normal de venta</span><input name="price" required inputmode="decimal" value="<?= escape((string) $formProduct['price']) ?>"></label>
              <label><span>Precio promocional</span><input name="sale_price" inputmode="decimal" value="<?= escape((string) $formProduct['sale_price']) ?>"></label>
            <?php else: ?>
              <div class="fact wide"><span>Precio de catálogo</span><?= $editProduct ? money($formProduct['sale_price'] ?: $formProduct['price']) : 'Se calculará al guardar' ?><small class="muted">Solo vendedor, gerente de tienda y admin global pueden modificar el precio final.</small></div>
            <?php endif; ?>
            <label class="wide"><span>Descripción</span><textarea name="description"><?= escape((string) $formProduct['description']) ?></textarea></label>
            <label class="wide"><span>Aliases, uno por línea</span><textarea name="aliases"><?= escape($aliasesText) ?></textarea></label>
            <label class="wide"><span>Subir foto</span><input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
            <label class="wide"><span>URL de imagen</span><input name="image_url" value="<?= escape((string) $formProduct['image_url']) ?>"></label>
            <label class="wide"><input type="checkbox" name="active" value="1" <?= (int) $formProduct['active'] === 1 ? 'checked' : '' ?>> Producto activo</label>
          </div>
          <h3>Tallas y cantidades</h3>
          <?php for ($i = 0; $i < max(5, count($editVariants)); $i++): $variant = $editVariants[$i] ?? ['id' => '', 'sku' => '', 'size' => '', 'stock' => '']; ?>
            <div class="variant-row"><input type="hidden" name="variant_id[]" value="<?= escape((string) ($variant['id'] ?? '')) ?>"><input name="variant_size[]" value="<?= escape((string) ($variant['size'] ?? '')) ?>" placeholder="Talla M"><input name="variant_sku[]" value="<?= escape((string) ($variant['sku'] ?? '')) ?>" placeholder="SKU opcional"><input name="variant_stock[]" value="<?= escape((string) ($variant['stock'] ?? '')) ?>" inputmode="numeric" placeholder="Cantidad"></div>
          <?php endfor; ?>
          <div class="actions"><button class="approve" type="submit">Guardar producto</button><?php if ($editProduct): ?><a class="button neutral" href="<?= escape(viewUrl($basePath, 'catalog')) ?>">Nuevo</a><?php endif; ?></div>
        </form>
        <?php endif; ?>
        <section class="grid">
          <?php if (can($user, 'inventory.transfer')): ?>
            <form class="panel stacked" method="post" action="<?= escape($basePath) ?>">
              <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
              <input type="hidden" name="action" value="transfer_stock">
              <input type="hidden" name="view" value="catalog">
              <h2>Trasladar existencias</h2>
              <p class="muted">El gerente de ciudad puede mover stock entre sus tiendas asociadas; el admin global puede hacerlo en todo su alcance. Si el producto no existe en destino, se crea automáticamente con la misma ficha.</p>
              <label><span>Producto origen</span><select name="from_variant_id" required><?php foreach ($transferVariants as $variant): ?><option value="<?= (int) $variant['variant_id'] ?>"><?= escape($variant['city_name'] . ' · ' . $variant['store_name'] . ' · ' . $variant['name'] . ' · talla ' . $variant['size'] . ' · stock ' . $variant['stock']) ?></option><?php endforeach; ?></select></label>
              <label><span>Tienda destino</span><select name="to_store_id" required><?php foreach ($visibleStores as $store): ?><option value="<?= (int) $store['id'] ?>"><?= escape($store['city_name'] . ' · ' . ($store['zone_name'] ?? 'Sin zona') . ' · ' . $store['name']) ?></option><?php endforeach; ?></select></label>
              <label><span>Cantidad</span><input name="quantity" inputmode="numeric" required></label>
              <label><span>Notas</span><input name="notes" placeholder="Motivo del traslado"></label>
              <button class="secondary" type="submit">Trasladar stock</button>
            </form>
          <?php endif; ?>
          <?php foreach ($products as $product): ?>
            <article class="product">
              <div class="head"><div class="title"><h2><?= escape($product['name']) ?></h2><span class="badge <?= (int) $product['active'] === 1 ? 'active-badge' : 'inactive-badge' ?>"><?= (int) $product['active'] === 1 ? 'Activo' : 'Inactivo' ?></span></div><strong>$<?= money($product['sale_price'] ?: $product['price']) ?></strong></div>
              <div class="body"><div><?php if ($product['image_url']): ?><img class="hero-img" src="<?= escape(imageSrc($basePath, $product['image_url'])) ?>" alt="" loading="lazy"><?php endif; ?></div><div class="facts"><div class="fact"><span>Tienda</span><?= escape(($product['city_name'] ?? '') . ' · ' . ($product['store_name'] ?? '')) ?></div><div class="fact"><span>Categoría</span><?= escape($product['category']) ?> · <?= escape($product['type']) ?></div><div class="fact"><span>Stock</span><?= (int) $product['total_variant_stock'] ?> · <?= escape($product['variant_summary'] ?? '') ?></div><?php if (can($user, 'catalog.cost')): ?><div class="fact"><span>Proveedor</span>Neto <?= moneyExact($product['supplier_net_price']) ?> · IVA <?= escape((string) $product['supplier_vat_rate']) ?> % (<?= moneyExact($product['supplier_vat_amount']) ?>) · total <?= moneyExact($product['supplier_total_price']) ?></div><?php endif; ?><div class="fact"><span>Descripción</span><?= nl2br(escape($product['description'])) ?></div></div></div>
              <?php if (can($user, 'catalog.price')): ?><form class="actions price-form" method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="save_product_price"><input type="hidden" name="view" value="catalog"><input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>"><label><span>Precio normal</span><input name="price" required inputmode="decimal" value="<?= escape((string) $product['price']) ?>"></label><label><span>Precio promo</span><input name="sale_price" inputmode="decimal" value="<?= escape((string) $product['sale_price']) ?>"></label><button class="primary" type="submit">Guardar precio</button></form><?php endif; ?>
              <?php if (can($user, 'catalog.write')): ?><div class="actions"><a class="button secondary" href="<?= escape(viewUrl($basePath, 'catalog', ['edit' => (int) $product['id']])) ?>">Editar ficha</a><form method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="toggle_product"><input type="hidden" name="view" value="catalog"><input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>"><button class="<?= (int) $product['active'] === 1 ? 'reject' : 'approve' ?>" type="submit"><?= (int) $product['active'] === 1 ? 'Desactivar' : 'Activar' ?></button></form><form method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="view" value="catalog"><input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>"><button class="danger" type="submit">Eliminar</button></form></div><?php endif; ?>
            </article>
          <?php endforeach; ?>
          <?php if ($products === []): ?><div class="empty">No hay productos visibles para tu rol.</div><?php endif; ?>
        </section>
      </section>
    <?php elseif ($view === 'locations' && can($user, 'locations.manage')): ?>
      <section class="split">
        <div class="grid">
          <form class="panel stacked" method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="save_location"><input type="hidden" name="view" value="locations"><input type="hidden" name="location_kind" value="city"><h2>Crear ciudad</h2><label><span>Nombre</span><input name="city_name" required></label><button class="primary" type="submit">Guardar ciudad</button></form>
          <form class="panel stacked" method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="save_location"><input type="hidden" name="view" value="locations"><input type="hidden" name="location_kind" value="zone"><h2>Crear zona</h2><label><span>Ciudad</span><select name="city_id"><?php foreach ($cities as $city): ?><option value="<?= (int) $city['id'] ?>"><?= escape($city['name']) ?></option><?php endforeach; ?></select></label><label><span>Zona</span><input name="zone_name" required></label><button class="primary" type="submit">Guardar zona</button></form>
          <form class="panel stacked" method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="save_location"><input type="hidden" name="view" value="locations"><input type="hidden" name="location_kind" value="store"><h2>Crear tienda</h2><p class="muted">La tienda es el centro de acción: a ella se asignan vendedores, proveedores, despachadores, gerentes, catálogo y pedidos.</p><label><span>Ciudad</span><select name="city_id"><?php foreach ($cities as $city): ?><option value="<?= (int) $city['id'] ?>"><?= escape($city['name']) ?></option><?php endforeach; ?></select></label><label><span>Zona</span><select name="zone_id"><option value="">Sin zona</option><?php foreach ($zones as $zone): ?><option value="<?= (int) $zone['id'] ?>"><?= escape($zone['city_name'] . ' · ' . $zone['name']) ?></option><?php endforeach; ?></select></label><label><span>Nombre de tienda</span><input name="store_name" required placeholder="Tienda Centro"></label><label><span>Dirección</span><input name="store_address" placeholder="Dirección física o referencia"></label><label><span>Teléfono</span><input name="store_phone" placeholder="Contacto operativo"></label><button class="primary" type="submit">Guardar tienda</button></form>
        </div>
        <div class="panel"><h2>Centros de acción</h2><table><thead><tr><th>Ciudad</th><th>Zona</th><th>Tienda</th><th>Catálogo</th><th>Pedidos</th><th>Contacto</th></tr></thead><tbody><?php foreach ($stores as $store): ?><tr><td><?= escape($store['city_name']) ?></td><td><?= escape($store['zone_name'] ?? '') ?></td><td><strong><?= escape($store['name']) ?></strong><br><span class="muted"><?= escape($store['address']) ?></span></td><td><?= (int) $store['products_count'] ?></td><td><?= (int) $store['orders_count'] ?></td><td><?= escape($store['phone']) ?></td></tr><?php endforeach; ?></tbody></table></div>
      </section>
    <?php elseif ($view === 'users' && can($user, 'users.manage')): ?>
      <section class="split">
        <form class="panel stacked" method="post">
          <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="save_user"><input type="hidden" name="view" value="users"><input type="hidden" name="user_id" value="<?= escape((string) $formUser['id']) ?>">
          <h2><?= $editUser ? 'Editar usuario' : 'Crear usuario' ?></h2>
          <p class="muted">El alcance del rol determina la asignación: gerente de ciudad solo requiere ciudad; los roles de tienda requieren tienda; el admin global no requiere ninguna.</p>
          <div class="fields">
            <label><span>Nombre</span><input name="name" required value="<?= escape((string) $formUser['name']) ?>"></label>
            <label><span>Email</span><input name="email" type="email" required value="<?= escape((string) $formUser['email']) ?>"></label>
            <fieldset class="wide"><legend>Roles</legend><div class="role-list" id="user-role-list"><?php foreach ($availableRoles as $role): ?><label class="role-option"><input type="checkbox" name="role_codes[]" value="<?= escape($role['code']) ?>" data-scope="<?= escape($role['scope_level']) ?>" <?= in_array($role['code'], $formRoleCodes, true) ? 'checked' : '' ?>><div><strong><?= escape($role['name']) ?></strong><small><?= escape(scopeLabel($role['scope_level'])) ?></small></div></label><?php endforeach; ?></div><small class="muted">Escoge uno o varios roles. Sus permisos se acumulan y se aplica el alcance más amplio.</small></fieldset>
            <label><span>Contraseña</span><input name="password" type="password" placeholder="<?= $editUser ? 'Dejar vacío para no cambiar' : '' ?>"></label>
            <label class="wide"><input type="checkbox" name="active" value="1" <?= (int) $formUser['active'] === 1 ? 'checked' : '' ?>> Usuario activo</label>
          </div>
          <label id="user-city-assignments"><span>Ciudades asignadas</span><select name="assigned_city_ids[]" multiple size="4"><?php foreach ($cities as $city): ?><option value="<?= (int) $city['id'] ?>" <?= in_array((int) $city['id'], $editUserCityIds, true) ? 'selected' : '' ?>><?= escape($city['name']) ?></option><?php endforeach; ?></select><small class="muted">Obligatorio para los roles con alcance de ciudad.</small></label>
          <label id="user-store-assignments"><span>Tiendas asignadas</span><select name="assigned_store_ids[]" multiple size="6"><?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>" <?= in_array((int) $store['id'], $editUserStoreIds, true) ? 'selected' : '' ?>><?= escape($store['city_name'] . ' · ' . ($store['zone_name'] ?? 'Sin zona') . ' · ' . $store['name']) ?></option><?php endforeach; ?></select><small class="muted">Obligatorio para vendedor, proveedor, despachador y gerente de tienda.</small></label>
          <div class="actions"><button class="primary" type="submit">Guardar usuario</button><?php if ($editUser): ?><a class="button neutral" href="<?= escape(viewUrl($basePath, 'users')) ?>">Nuevo</a><?php endif; ?></div>
        </form>
        <div class="panel"><h2>Usuarios</h2><table><thead><tr><th>Nombre</th><th>Email</th><th>Roles</th><th>Estado</th><th></th></tr></thead><tbody><?php foreach ($users as $listedUser): ?><tr><td><?= escape($listedUser['name']) ?></td><td><?= escape($listedUser['email']) ?></td><td><?= escape($listedUser['role_names_text'] ?: 'Sin rol') ?></td><td><?= (int) $listedUser['active'] === 1 ? 'Activo' : 'Inactivo' ?></td><td><a href="<?= escape(viewUrl($basePath, 'users', ['edit_user' => (int) $listedUser['id']])) ?>">Editar</a></td></tr><?php endforeach; ?></tbody></table></div>
      </section>
    <?php elseif ($view === 'inventory' && can($user, 'inventory.view')): ?>
      <section class="grid">
        <?php if (can($user, 'inventory.restock')): ?>
          <form class="panel stacked" method="post" action="<?= escape($basePath) ?>">
            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="restock_inventory">
            <input type="hidden" name="view" value="inventory">
            <h2>Alimentar inventario</h2>
            <p class="muted">Añade unidades a un producto existente de una tienda asignada. La entrada queda registrada con tu usuario y no permite reducir cantidades.</p>
            <?php if ($restockVariants === []): ?>
              <div class="empty">No hay productos activos disponibles en tus tiendas.</div>
            <?php else: ?>
              <label><span>Producto y variante</span><select name="variant_id" required><?php foreach ($restockVariants as $variant): ?><option value="<?= (int) $variant['variant_id'] ?>"><?= escape($variant['city_name'] . ' · ' . $variant['store_name'] . ' · ' . $variant['name'] . ' · talla ' . $variant['size'] . ' · stock actual ' . $variant['stock']) ?></option><?php endforeach; ?></select></label>
              <label><span>Unidades recibidas</span><input name="quantity" type="number" min="1" step="1" required></label>
              <label><span>Notas</span><input name="notes" maxlength="500" placeholder="Factura, lote o referencia de entrega"></label>
              <button class="approve" type="submit">Registrar entrada</button>
            <?php endif; ?>
          </form>
        <?php endif; ?>
        <div class="panel">
          <h2>Inventario por tienda</h2>
          <p class="muted">Cada tienda tiene su propio inventario. Las ventas descuentan la variante de la tienda asociada al producto vendido.</p>
          <table><thead><tr><th>Ciudad</th><th>Tienda</th><th>Producto</th><th>Talla</th><th>Stock</th><th>Reservado</th><th>Precio</th></tr></thead><tbody><?php foreach ($inventoryStore as $row): ?><tr><td><?= escape($row['city_name']) ?></td><td><?= escape($row['store_name']) ?></td><td><?= escape($row['product_name']) ?><br><span class="muted"><?= escape(trim(($row['category'] ?? '') . ' · ' . ($row['type'] ?? ''), ' ·')) ?></span></td><td><?= escape($row['size']) ?></td><td><strong><?= (int) $row['stock'] ?></strong></td><td><?= (int) $row['reserved_stock'] ?></td><td>$<?= money($row['price']) ?></td></tr><?php endforeach; ?></tbody></table>
        </div>
        <div class="panel">
          <h2>Inventario consolidado por ciudad</h2>
          <p class="muted">El inventario de una ciudad es la suma del stock de todas sus tiendas visibles.</p>
          <table><thead><tr><th>Ciudad</th><th>Producto</th><th>Talla</th><th>Stock ciudad</th><th>Tiendas</th></tr></thead><tbody><?php foreach ($inventoryCity as $row): ?><tr><td><?= escape($row['city_name']) ?></td><td><?= escape($row['product_name']) ?><br><span class="muted"><?= escape(trim(($row['category'] ?? '') . ' · ' . ($row['type'] ?? ''), ' ·')) ?></span></td><td><?= escape($row['size']) ?></td><td><strong><?= (int) $row['city_stock'] ?></strong></td><td><?= (int) $row['stores_with_product'] ?></td></tr><?php endforeach; ?></tbody></table>
        </div>
      </section>
    <?php elseif ($view === 'stats' && can($user, 'stats.view')): ?>
      <section class="grid">
        <div class="summary"><div class="metric"><span>Ventas</span><strong>$<?= money($stats['summary']['sales_total'] ?? 0) ?></strong></div><div class="metric"><span>Pedidos</span><strong><?= (int) ($stats['summary']['orders_count'] ?? 0) ?></strong></div><div class="metric"><span>Ticket promedio</span><strong>$<?= money($stats['summary']['average_ticket'] ?? 0) ?></strong></div><div class="metric"><span>Unidades en inventario</span><strong><?= (int) ($stats['inventory']['stock_units'] ?? 0) ?></strong></div><div class="metric"><span>Productos activos</span><strong><?= (int) ($stats['inventory']['products_count'] ?? 0) ?></strong></div><div class="metric"><span>Tiendas visibles</span><strong><?= (int) ($stats['inventory']['stores_count'] ?? 0) ?></strong></div></div>
        <div class="panel"><h2>Ventas por tienda</h2><table><thead><tr><th>Tienda</th><th>Pedidos</th><th>Ventas</th></tr></thead><tbody><?php foreach (($stats['stores'] ?? []) as $row): ?><tr><td><?= escape($row['store_name'] ?? 'Sin tienda') ?></td><td><?= (int) $row['orders_count'] ?></td><td>$<?= money($row['sales_total']) ?></td></tr><?php endforeach; ?></tbody></table></div>
      </section>
    <?php elseif ($view === 'shipments'): ?>
      <?php if ($shipments === []): ?><div class="empty">No hay envíos visibles para tu rol.</div><?php else: ?><table><thead><tr><th>Pedido</th><th>Tienda</th><th>Cliente</th><th>Dirección</th><th>Productos</th><th>Total</th></tr></thead><tbody><?php foreach ($shipments as $shipment): ?><tr><td>#<?= (int) $shipment['order_id'] ?></td><td><?= escape($shipment['store_name']) ?></td><td><?= escape($shipment['customer_name']) ?><br><span class="muted"><?= escape($shipment['phone']) ?></span></td><td><?= escape($shipment['delivery_address']) ?><?php if ($shipment['delivery_notes']): ?><br><span class="muted"><?= escape($shipment['delivery_notes']) ?></span><?php endif; ?></td><td><?= nl2br(escape($shipment['items'])) ?></td><td>$<?= money($shipment['total']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    <?php else: ?>
      <?php if (!can($user, 'orders.view')): ?><div class="empty">Tu rol no tiene bandeja de pedidos.</div><?php else: ?>
      <nav class="summary"><a class="metric <?= $selectedStatus === 'ALL' ? 'active' : '' ?>" href="<?= escape(viewUrl($basePath, 'orders')) ?>"><span>Todos</span><strong><?= $totalOrders ?></strong></a><?php foreach ($counts as $status => $count): ?><a class="metric <?= $selectedStatus === $status ? 'active' : '' ?>" href="<?= escape(viewUrl($basePath, 'orders', ['status' => $status])) ?>"><span><?= escape(statusLabel($status)) ?></span><strong><?= $count ?></strong></a><?php endforeach; ?></nav>
      <section class="grid">
        <?php foreach ($orders as $order): ?>
          <article class="order">
            <div class="head"><div class="title"><h2>Pedido #<?= (int) $order['id'] ?> · <?= escape($order['customer_name']) ?></h2><span class="badge <?= escape($order['status']) ?>"><?= escape(statusLabel($order['status'])) ?></span></div><strong>$<?= money($order['total']) ?></strong></div>
            <div class="body"><div class="facts"><div class="fact"><span>Tienda</span><?= escape(($order['city_name'] ?? '') . ' · ' . ($order['zone_name'] ?? '') . ' · ' . ($order['store_name'] ?? '')) ?></div><div class="fact"><span>Teléfono</span><a href="tel:<?= escape($order['phone']) ?>"><?= escape($order['phone']) ?></a></div><div class="fact"><span>Dirección</span><?= escape($order['delivery_address']) ?></div><div class="fact"><span>Pedido original</span><?= nl2br(escape($order['raw_message'])) ?></div><?php if ($order['payment_proof_url']): ?><div class="fact"><span>Comprobante</span><a href="<?= escape(imageSrc($basePath, $order['payment_proof_url'])) ?>" target="_blank" rel="noopener">Ver imagen</a></div><?php endif; ?><?php if ($order['reviewer_name']): ?><div class="fact"><span>Revisado por</span><?= escape($order['reviewer_name']) ?></div><?php endif; ?></div><div class="items"><?php foreach ($order['items'] as $item): ?><div class="item"><?php if ($item['image_url']): ?><img src="<?= escape(imageSrc($basePath, $item['image_url'])) ?>" alt="" loading="lazy"><?php else: ?><span></span><?php endif; ?><div><strong><?= (int) $item['quantity'] ?> x <?= escape($item['product_name']) ?></strong><br><small><?= escape($item['sku']) ?><?= $item['size'] ? ' · talla ' . escape($item['size']) : '' ?> · $<?= money($item['unit_price']) ?></small></div><strong>$<?= money((float) $item['quantity'] * (float) $item['unit_price']) ?></strong></div><?php endforeach; ?></div></div>
            <div class="actions">
              <?php if (can($user, 'orders.approve') && $order['status'] === 'PENDING_PAYMENT'): ?>
                <form method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="return_status" value="<?= escape($selectedStatus) ?>"><button class="approve" type="submit">Confirmar pago</button></form>
                <form method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="return_status" value="<?= escape($selectedStatus) ?>"><button class="reject" type="submit">Rechazar pago</button></form>
              <?php elseif (can($user, 'orders.approve') && $order['status'] === 'CONFIRMED'): ?>
                <form method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="dispatch"><input type="hidden" name="return_status" value="<?= escape($selectedStatus) ?>"><button class="dispatch" type="submit">Marcar despachado</button></form>
              <?php endif; ?>
              <?php if (can($user, 'orders.cancel') && in_array($order['status'], ['PENDING_PAYMENT', 'CONFIRMED', 'DISPATCHED'], true)): ?>
                <form method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="cancel_order"><input type="hidden" name="return_status" value="<?= escape($selectedStatus) ?>"><button class="neutral" type="submit">Cancelar pedido</button></form>
              <?php endif; ?>
              <?php if (can($user, 'orders.delete')): ?>
                <form method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="delete_order"><input type="hidden" name="return_status" value="<?= escape($selectedStatus) ?>"><label class="destructive-confirm"><input type="checkbox" name="confirm_delete" value="1" required> Confirmar borrado definitivo</label><button class="danger" type="submit">Eliminar pedido</button></form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
        <?php if ($orders === []): ?><div class="empty">No hay pedidos visibles en este estado.</div><?php endif; ?>
      </section>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</main>
<script>
  (() => {
    const supplierNet = document.getElementById('supplier-net-price');
    const supplierVatRate = document.getElementById('supplier-vat-rate');
    const supplierVatAmount = document.getElementById('supplier-vat-amount');
    const supplierTotalPrice = document.getElementById('supplier-total-price');
    const suggestedCatalogPrice = document.getElementById('suggested-catalog-price');
    if (supplierNet && supplierVatRate && supplierVatAmount && supplierTotalPrice && suggestedCatalogPrice) {
      const updateSupplierPrices = () => {
        const net = Number.parseFloat(supplierNet.value) || 0;
        const vatRate = Number.parseFloat(supplierVatRate.value) || 0;
        const vat = Math.round(net * vatRate) / 100;
        const total = Math.round((net + vat) * 100) / 100;
        supplierVatAmount.textContent = vat.toFixed(2).replace('.', ',');
        supplierTotalPrice.textContent = total.toFixed(2).replace('.', ',');
        suggestedCatalogPrice.textContent = (Math.round(total * 130) / 100).toFixed(2).replace('.', ',');
      };
      supplierNet.addEventListener('input', updateSupplierPrices);
      supplierVatRate.addEventListener('input', updateSupplierPrices);
      updateSupplierPrices();
    }

    const roles = Array.from(document.querySelectorAll('#user-role-list input[name="role_codes[]"]'));
    const cityGroup = document.getElementById('user-city-assignments');
    const storeGroup = document.getElementById('user-store-assignments');
    if (roles.length === 0 || !cityGroup || !storeGroup) return;

    const updateAssignments = () => {
      const scopes = roles.filter((role) => role.checked).map((role) => role.dataset.scope);
      const globalScope = scopes.includes('GLOBAL');
      const needsCity = !globalScope && scopes.includes('CITY');
      const needsStore = !globalScope && scopes.includes('STORE');
      cityGroup.hidden = !needsCity;
      storeGroup.hidden = !needsStore;
      cityGroup.querySelector('select').disabled = !needsCity;
      storeGroup.querySelector('select').disabled = !needsStore;
    };

    roles.forEach((role) => role.addEventListener('change', updateAssignments));
    updateAssignments();
  })();
</script>
</body>
</html>
