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

function redirectWithFlash(string $basePath, string $message, string $type, string $filter): never
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    $query = $filter === 'ALL' ? '' : '?status=' . rawurlencode($filter);
    header('Location: ' . $basePath . $query, true, 303);
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
        redirectWithFlash($basePath, 'La sesión expiró. Vuelve a intentarlo.', 'error', $selectedStatus);
    }

    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $action = (string) ($_POST['action'] ?? '');
    if ($orderId === false || !in_array($action, ['approve', 'reject', 'dispatch'], true)) {
        redirectWithFlash($basePath, 'Acción no válida.', 'error', $selectedStatus);
    }

    $pdo = database();

    try {
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
            redirectWithFlash($basePath, "Pedido #{$orderId} confirmado y stock descontado.", 'success', $selectedStatus);
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
        redirectWithFlash($basePath, $message, 'success', $selectedStatus);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirectWithFlash($basePath, $error->getMessage(), 'error', $selectedStatus);
    }
}

try {
    $pdo = database();
    $counts = array_fill_keys(array_slice(ORDER_STATUSES, 1), 0);
    foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM orders GROUP BY status') as $row) {
        $counts[$row['status']] = (int) $row['total'];
    }

    $sql = 'SELECT id, customer_name, phone, delivery_address, raw_message, status, total, payment_confirmed_by, payment_confirmed_at, created_at, updated_at FROM orders';
    $parameters = [];
    if ($selectedStatus !== 'ALL') {
        $sql .= ' WHERE status = ?';
        $parameters[] = $selectedStatus;
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 200';

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $orders = $statement->fetchAll();

    $itemsStatement = $pdo->prepare('SELECT sku, product_name, quantity, unit_price, image_url FROM order_items WHERE order_id = ? ORDER BY id');
    foreach ($orders as &$order) {
        $itemsStatement->execute([$order['id']]);
        $order['items'] = $itemsStatement->fetchAll();
    }
    unset($order);
} catch (Throwable $error) {
    http_response_code(503);
    $databaseError = $error->getMessage();
    $counts = array_fill_keys(array_slice(ORDER_STATUSES, 1), 0);
    $orders = [];
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$totalOrders = array_sum($counts);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pedidos · Panel de operador</title>
  <style>
    :root { color-scheme: light; --ink:#18221d; --muted:#65716a; --paper:#f4f2eb; --card:#fff; --line:#dcded7; --green:#1c6b4a; --green-soft:#dceee5; --amber:#966114; --amber-soft:#fff0cf; --red:#a13832; --red-soft:#f9dfdc; --blue:#315f8a; --blue-soft:#e1edf8; }
    * { box-sizing: border-box; }
    body { margin:0; background:var(--paper); color:var(--ink); font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
    header { background:#13251d; color:#fff; padding:28px clamp(18px,4vw,54px); }
    header div { max-width:1400px; margin:auto; display:flex; justify-content:space-between; gap:24px; align-items:end; }
    h1 { margin:0 0 4px; font-size:clamp(25px,4vw,40px); letter-spacing:-.035em; }
    header p { margin:0; color:#b9c8c0; }
    .operator { font-size:14px; text-align:right; }
    main { max-width:1400px; margin:auto; padding:28px clamp(18px,4vw,54px) 60px; }
    .summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(145px,1fr)); gap:12px; margin-bottom:22px; }
    .metric { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:16px; text-decoration:none; color:inherit; }
    .metric strong { display:block; font-size:27px; margin-top:4px; }
    .metric.active { outline:3px solid #8bbda5; border-color:transparent; }
    .filters { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 24px; }
    .filters a { color:var(--ink); background:#e8e6de; padding:8px 12px; border-radius:999px; text-decoration:none; font-size:14px; }
    .filters a.active { background:#13251d; color:#fff; }
    .flash { padding:13px 16px; border-radius:10px; margin-bottom:18px; border:1px solid; }
    .flash.success { background:var(--green-soft); border-color:#9bcab3; color:#174d37; }
    .flash.error { background:var(--red-soft); border-color:#dda7a2; color:#742621; }
    .orders { display:grid; gap:16px; }
    .order { background:var(--card); border:1px solid var(--line); border-radius:16px; overflow:hidden; box-shadow:0 4px 18px rgb(30 42 35 / 5%); }
    .order-head { padding:17px 19px; border-bottom:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; gap:16px; }
    .order-title { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
    .order-title h2 { font-size:19px; margin:0; }
    .badge { border-radius:999px; padding:5px 9px; font-size:12px; font-weight:700; }
    .PENDING_PAYMENT { background:var(--amber-soft); color:var(--amber); }
    .CONFIRMED { background:var(--green-soft); color:var(--green); }
    .REJECTED,.CANCELLED { background:var(--red-soft); color:var(--red); }
    .DISPATCHED { background:var(--blue-soft); color:var(--blue); }
    .total { font-size:21px; font-weight:800; white-space:nowrap; }
    .order-body { display:grid; grid-template-columns:minmax(220px,.9fr) minmax(280px,1.5fr); gap:22px; padding:19px; }
    .facts { display:grid; gap:10px; font-size:14px; }
    .fact span { display:block; color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; margin-bottom:2px; }
    .items { display:grid; gap:8px; }
    .item { display:grid; grid-template-columns:48px 1fr auto; gap:11px; align-items:center; background:#f7f7f3; border-radius:10px; padding:8px; }
    .item img { width:48px; height:48px; border-radius:8px; object-fit:cover; background:#e6e7e1; }
    .item small { color:var(--muted); }
    .actions { display:flex; flex-wrap:wrap; gap:9px; padding:0 19px 19px; }
    .actions form { margin:0; }
    button { border:0; border-radius:9px; padding:10px 14px; color:#fff; font-weight:700; cursor:pointer; }
    button.approve { background:var(--green); }
    button.reject { background:var(--red); }
    button.dispatch { background:var(--blue); }
    .empty { text-align:center; background:var(--card); border:1px dashed #c5c8bf; border-radius:16px; padding:50px 20px; color:var(--muted); }
    .error-box { background:var(--red-soft); border:1px solid #dda7a2; color:#742621; padding:18px; border-radius:12px; }
    @media (max-width:720px) { header div { align-items:start; flex-direction:column; } .operator{text-align:left}.order-body{grid-template-columns:1fr}.order-head{align-items:start}.summary{grid-template-columns:repeat(2,1fr)} }
  </style>
</head>
<body>
<header>
  <div>
    <section>
      <h1>Panel de pedidos</h1>
      <p>Revisión de pagos, preparación y despacho</p>
    </section>
    <p class="operator">Sesión de operador<br><strong><?= escape($operator) ?></strong></p>
  </div>
</header>
<main>
  <?php if ($flash): ?>
    <div class="flash <?= escape($flash['type']) ?>" role="status"><?= escape($flash['message']) ?></div>
  <?php endif; ?>
  <?php if (isset($databaseError)): ?>
    <div class="error-box"><strong>No se pudo consultar MariaDB.</strong><br><?= escape($databaseError) ?></div>
  <?php else: ?>
    <nav class="summary" aria-label="Resumen de pedidos">
      <a class="metric <?= $selectedStatus === 'ALL' ? 'active' : '' ?>" href="<?= escape($basePath) ?>"><span>Todos</span><strong><?= $totalOrders ?></strong></a>
      <?php foreach ($counts as $status => $count): ?>
        <a class="metric <?= $selectedStatus === $status ? 'active' : '' ?>" href="<?= escape($basePath) ?>?status=<?= escape($status) ?>"><span><?= escape(statusLabel($status)) ?></span><strong><?= $count ?></strong></a>
      <?php endforeach; ?>
    </nav>
    <nav class="filters" aria-label="Filtros">
      <?php foreach (ORDER_STATUSES as $status): ?>
        <a class="<?= $selectedStatus === $status ? 'active' : '' ?>" href="<?= escape($basePath) ?><?= $status === 'ALL' ? '' : '?status=' . escape($status) ?>"><?= $status === 'ALL' ? 'Todos' : escape(statusLabel($status)) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php if ($orders === []): ?>
      <div class="empty">No hay pedidos en este estado.</div>
    <?php else: ?>
      <section class="orders">
      <?php foreach ($orders as $order): ?>
        <article class="order">
          <div class="order-head">
            <div class="order-title">
              <h2>Pedido #<?= (int) $order['id'] ?> · <?= escape($order['customer_name']) ?></h2>
              <span class="badge <?= escape($order['status']) ?>"><?= escape(statusLabel($order['status'])) ?></span>
            </div>
            <div class="total"><?= number_format((float) $order['total'], 2, ',', '.') ?> €</div>
          </div>
          <div class="order-body">
            <div class="facts">
              <div class="fact"><span>Teléfono</span><a href="tel:<?= escape($order['phone']) ?>"><?= escape($order['phone']) ?></a></div>
              <div class="fact"><span>Dirección</span><?= escape($order['delivery_address']) ?></div>
              <div class="fact"><span>Pedido original</span><?= nl2br(escape($order['raw_message'])) ?></div>
              <div class="fact"><span>Creado</span><?= escape($order['created_at']) ?></div>
              <?php if ($order['payment_confirmed_by']): ?><div class="fact"><span>Pago verificado por</span><?= escape($order['payment_confirmed_by']) ?> · <?= escape($order['payment_confirmed_at']) ?></div><?php endif; ?>
            </div>
            <div class="items">
              <?php foreach ($order['items'] as $item): ?>
                <div class="item">
                  <?php if ($item['image_url']): ?><img src="<?= escape($item['image_url']) ?>" alt="" loading="lazy"><?php else: ?><span></span><?php endif; ?>
                  <div><strong><?= (int) $item['quantity'] ?> × <?= escape($item['product_name']) ?></strong><br><small><?= escape($item['sku']) ?> · <?= number_format((float) $item['unit_price'], 2, ',', '.') ?> € / unidad</small></div>
                  <strong><?= number_format((float) $item['quantity'] * (float) $item['unit_price'], 2, ',', '.') ?> €</strong>
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
