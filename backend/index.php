<?php
// Front controller for the API.

declare(strict_types=1);

require __DIR__ . '/config.php';

allow_cors();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    respond(['status' => 'ok']);
    exit;
}

try {
    $pdo = db();
} catch (Throwable $e) {
    fail('Database connection failed: ' . $e->getMessage(), 500);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$segments = path_segments();
$resource = $segments[0] ?? '';

$authUser = authenticate($pdo);

// Log auth attempt for debugging
if ($resource !== 'health' && $resource !== 'auth') {
    $token = bearer_token();
    if (!$token && $method !== 'GET') {
        log_event($pdo, 'debug', 'missing_token', ['resource' => $resource, 'method' => $method, 'header' => $_SERVER['HTTP_AUTHORIZATION'] ?? 'none']);
    }
}

try {
    switch ($resource) {
        case 'health':
            respond(['status' => 'ok', 'time' => date(DATE_ATOM)]);
            break;

        case 'auth':
            handle_auth($pdo, $method, $segments, $authUser);
            break;

        case 'parts':
            handle_parts($pdo, $method, $segments, $authUser);
            break;

        case 'stock':
            handle_stock($pdo, $method, $segments, $authUser);
            break;

        case 'suppliers':
            handle_suppliers($pdo, $method, $segments, $authUser);
            break;

        case 'purchase-orders':
            handle_purchase_orders($pdo, $method, $segments, $authUser);
            break;

        case 'reservations':
            handle_reservations($pdo, $method, $segments, $authUser);
            break;

        case 'alerts':
            handle_alerts($pdo, $method, $segments, $authUser);
            break;

        case 'attachments':
            handle_attachments($pdo, $method, $segments, $authUser);
            break;

        case 'reports':
            handle_reports($pdo, $method, $segments, $authUser);
            break;

        default:
            fail('Not found', 404);
            break;
    }
} catch (Throwable $e) {
    fail('Server error: ' . $e->getMessage(), 500);
}

function authenticate(PDO $pdo): ?array
{
    $token = bearer_token();
    if (!$token) {
        return null;
    }
    try {
        // First verify the token exists in the database
        $checkStmt = $pdo->prepare('SELECT id, user_id, expires_at FROM auth_tokens WHERE token = :token LIMIT 1');
        $checkStmt->execute(['token' => $token]);
        $tokenRow = $checkStmt->fetch();
        
        if (!$tokenRow) {
            log_event($pdo, 'debug', 'token_not_found_in_db', [
                'token_len' => strlen($token),
                'token_prefix' => substr($token, 0, 10),
            ]);
            return null;
        }
        
        // Check if token has expired
        $expiresAt = new DateTime($tokenRow['expires_at']);
        if ($expiresAt < new DateTime()) {
            log_event($pdo, 'debug', 'token_expired', ['expires_at' => $tokenRow['expires_at']]);
            return null;
        }
        
        // Get user details
        $userStmt = $pdo->prepare('SELECT id, username, role FROM users WHERE id = :uid');
        $userStmt->execute(['uid' => $tokenRow['user_id']]);
        $user = $userStmt->fetch();
        
        if (!$user) {
            log_event($pdo, 'debug', 'user_not_found', ['user_id' => $tokenRow['user_id']]);
            return null;
        }
        
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
        ];
    } catch (Throwable $e) {
        log_event($pdo, 'error', 'auth_check_failed', ['error' => $e->getMessage()]);
        return null;
    }
}

function require_auth(?array $user, string $role = null): void
{
    if (!$user) {
        fail('Unauthorized', 401);
        exit;
    }
    if ($role && strtolower($user['role']) !== strtolower($role)) {
        fail('Forbidden', 403);
        exit;
    }
}

function handle_auth(PDO $pdo, string $method, array $segments, ?array $user): void
{
    $action = $segments[1] ?? '';

    if ($method === 'POST' && $action === 'login') {
        $data = json_body();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $stmt = $pdo->prepare('SELECT id, username, password_hash, role FROM users WHERE username = :u');
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            fail('Invalid credentials', 401);
            return;
        }
        $token = random_token();
        $expires = (new DateTime('+2 days'))->format('Y-m-d H:i:s');
        try {
            $insertStmt = $pdo->prepare('INSERT INTO auth_tokens (user_id, token, expires_at) VALUES (:uid, :t, :exp)');
            $insertStmt->execute(['uid' => $row['id'], 't' => $token, 'exp' => $expires]);
            log_event($pdo, 'info', 'login', ['user' => $row['username'], 'token_len' => strlen($token)]);
        } catch (Throwable $e) {
            log_event($pdo, 'error', 'token_insert_failed', ['error' => $e->getMessage()]);
            fail('Token creation failed', 500);
            return;
        }
        respond(['token' => $token, 'user' => ['id' => (int) $row['id'], 'username' => $row['username'], 'role' => $row['role']]]);
        return;
    }

    if ($method === 'POST' && $action === 'logout') {
        require_auth($user);
        $token = bearer_token();
        if ($token) {
            $pdo->prepare('DELETE FROM auth_tokens WHERE token = :t')->execute(['t' => $token]);
        }
        respond(['logged_out' => true]);
        return;
    }

    if ($method === 'GET' && $action === 'me') {
        require_auth($user);
        respond(['user' => $user]);
        return;
    }

    fail('Unsupported auth operation', 400);
}

function handle_parts(PDO $pdo, string $method, array $segments, ?array $user): void
{
    $id = $segments[1] ?? null;
    $sub = $segments[1] ?? '';

    if ($method === 'GET' && $id === null) {
        $search = trim((string) query_param('search', ''));
        $supplierId = query_param('supplier_id');
        $lowOnly = query_param('low_only') === '1';
        $activeOnly = query_param('active') !== '0';
        $page = max(1, (int) query_param('page', 1));
        $pageSize = min(100, max(1, (int) query_param('page_size', 25)));
        $offset = ($page - 1) * $pageSize;

        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(p.name LIKE :search OR p.sku LIKE :search OR p.barcode LIKE :search)';
            $params['search'] = "%{$search}%";
        }
        if ($supplierId !== null && $supplierId !== '') {
            $where[] = 'p.supplier_id = :sid';
            $params['sid'] = (int) $supplierId;
        }
        if ($activeOnly) {
            $where[] = 'p.is_active = 1';
        }
        if ($lowOnly) {
            $where[] = '(p.reorder_level IS NOT NULL AND p.quantity <= p.reorder_level)';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare(
            "SELECT SQL_CALC_FOUND_ROWS p.id, p.name, p.sku, p.description, p.quantity, p.reorder_level, p.price, p.updated_at,
                    p.barcode, p.location, p.lead_time_days, p.is_active,
                    s.id AS supplier_id, s.name AS supplier_name, s.phone AS supplier_phone, s.email AS supplier_email
             FROM parts p
             LEFT JOIN suppliers s ON s.id = p.supplier_id
             $whereSql
             ORDER BY p.name ASC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(":{$k}", $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $total = (int) $pdo->query('SELECT FOUND_ROWS()')->fetchColumn();
        $parts = array_map(static function ($row) {
            $row['is_low_stock'] = ($row['reorder_level'] !== null && (int) $row['quantity'] <= (int) $row['reorder_level']);
            return $row;
        }, $rows);
        respond(['items' => $parts, 'total' => $total, 'page' => $page, 'page_size' => $pageSize]);
        return;
    }

    if ($method === 'GET' && $id !== null) {
        $stmt = $pdo->prepare('SELECT * FROM parts WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $part = $stmt->fetch();
        if (!$part) {
            fail('Part not found', 404);
            return;
        }
        $part['is_low_stock'] = ($part['reorder_level'] !== null && (int) $part['quantity'] <= (int) $part['reorder_level']);
        respond($part);
        return;
    }

    if ($method === 'POST' && $id === null) {
        require_auth($user);
        $data = json_body();
        if (!isset($data['name'], $data['sku'])) {
            fail('Name and SKU are required');
            return;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO parts (name, sku, description, quantity, reorder_level, price, supplier_id, barcode, location, lead_time_days, is_active, updated_at)
             VALUES (:name, :sku, :description, :quantity, :reorder_level, :price, :supplier_id, :barcode, :location, :lead_time, :active, NOW())'
        );
        $stmt->execute([
            'name' => $data['name'],
            'sku' => $data['sku'],
            'description' => $data['description'] ?? '',
            'quantity' => (int) ($data['quantity'] ?? 0),
            'reorder_level' => $data['reorder_level'] !== null ? (int) $data['reorder_level'] : null,
            'price' => $data['price'] !== null ? (float) $data['price'] : null,
            'supplier_id' => $data['supplier_id'] !== null ? (int) $data['supplier_id'] : null,
            'barcode' => $data['barcode'] ?? null,
            'location' => $data['location'] ?? null,
            'lead_time' => (int) ($data['lead_time_days'] ?? 0),
            'active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
        ]);
        log_event($pdo, 'info', 'part_created', ['id' => $pdo->lastInsertId()]);
        respond(['id' => $pdo->lastInsertId()], 201);
        return;
    }

    if ($method === 'PUT' && $id !== null) {
        require_auth($user);
        $data = json_body();
        $stmt = $pdo->prepare(
            'UPDATE parts SET name = :name, sku = :sku, description = :description, quantity = :quantity,
                    reorder_level = :reorder_level, price = :price, supplier_id = :supplier_id, barcode = :barcode,
                    location = :location, lead_time_days = :lead_time, is_active = :active, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $data['name'] ?? '',
            'sku' => $data['sku'] ?? '',
            'description' => $data['description'] ?? '',
            'quantity' => (int) ($data['quantity'] ?? 0),
            'reorder_level' => $data['reorder_level'] !== null ? (int) $data['reorder_level'] : null,
            'price' => $data['price'] !== null ? (float) $data['price'] : null,
            'supplier_id' => $data['supplier_id'] !== null ? (int) $data['supplier_id'] : null,
            'barcode' => $data['barcode'] ?? null,
            'location' => $data['location'] ?? null,
            'lead_time' => (int) ($data['lead_time_days'] ?? 0),
            'active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
        ]);
        log_event($pdo, 'info', 'part_updated', ['id' => $id]);
        respond(['updated' => true]);
        return;
    }

    if ($method === 'DELETE' && $id !== null) {
        require_auth($user, 'admin');
        $pdo->prepare('DELETE FROM parts WHERE id = :id')->execute(['id' => $id]);
        log_event($pdo, 'info', 'part_deleted', ['id' => $id]);
        respond(['deleted' => true]);
        return;
    }

    fail('Unsupported parts operation', 400);
}

function handle_stock(PDO $pdo, string $method, array $segments, ?array $user): void
{
    $action = $segments[1] ?? '';

    if ($method === 'GET' && $action === 'movements') {
        $limit = min(500, max(1, (int) query_param('limit', 100)));
        $partId = query_param('part_id');
        $where = [];
        $params = [];
        if ($partId) {
            $where[] = 'sm.part_id = :pid';
            $params['pid'] = (int) $partId;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $pdo->prepare(
            "SELECT sm.id, sm.part_id, p.name AS part_name, sm.change_type, sm.quantity, sm.note, sm.created_at, u.username
             FROM stock_movements sm
             JOIN parts p ON p.id = sm.part_id
             LEFT JOIN users u ON u.id = sm.user_id
             $whereSql
             ORDER BY sm.created_at DESC
             LIMIT :limit"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(":{$k}", $v, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        respond(['items' => $stmt->fetchAll()]);
        return;
    }

    if ($method === 'POST' && ($action === 'in' || $action === 'out')) {
        require_auth($user);
        $data = json_body();
        if (!isset($data['part_id'], $data['quantity'])) {
            fail('part_id and quantity are required');
            return;
        }
        $partId = (int) $data['part_id'];
        $qty = max(0, (int) $data['quantity']);
        $note = $data['note'] ?? null;
        $changeType = $action === 'in' ? 'in' : 'out';

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT quantity FROM parts WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $partId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            $pdo->rollBack();
            fail('Part not found', 404);
            return;
        }

        // Account for open reservations when moving stock out.
        $resStmt = $pdo->prepare('SELECT COALESCE(SUM(reserved_qty),0) FROM reservations WHERE part_id = :pid AND status = "open"');
        $resStmt->execute(['pid' => $partId]);
        $openRes = (int) $resStmt->fetchColumn();

        $currentQty = (int) $current;
        $available = $currentQty - $openRes;
        $newQty = $changeType === 'in' ? $currentQty + $qty : $currentQty - $qty;
        if ($changeType === 'out' && $qty > $available) {
            $pdo->rollBack();
            fail('Insufficient available stock (reservations applied)', 400);
            return;
        }
        if ($newQty < 0) {
            $pdo->rollBack();
            fail('Insufficient stock', 400);
            return;
        }
        $pdo->prepare('UPDATE parts SET quantity = :qty, updated_at = NOW() WHERE id = :id')
            ->execute(['qty' => $newQty, 'id' => $partId]);
        $pdo->prepare(
            'INSERT INTO stock_movements (part_id, change_type, quantity, note, created_at, user_id)
             VALUES (:part_id, :change_type, :quantity, :note, NOW(), :user_id)'
        )->execute([
            'part_id' => $partId,
            'change_type' => $changeType,
            'quantity' => $qty,
            'note' => $note,
            'user_id' => $user['id'] ?? null,
        ]);
        $pdo->commit();
        log_event($pdo, 'info', 'stock_' . $changeType, ['part_id' => $partId, 'qty' => $qty]);
        respond(['new_quantity' => $newQty]);
        return;
    }

    fail('Unsupported stock operation', 400);
}

function handle_suppliers(PDO $pdo, string $method, array $segments, ?array $user): void
{
    $id = $segments[1] ?? null;

    if ($method === 'GET' && $id === null) {
        $stmt = $pdo->query('SELECT id, name, contact_name, phone, email, address FROM suppliers ORDER BY name');
        respond(['items' => $stmt->fetchAll()]);
        return;
    }

    if ($method === 'POST') {
        require_auth($user);
        $data = json_body();
        if (!isset($data['name'])) {
            fail('Name is required');
            return;
        }
        $pdo->prepare(
            'INSERT INTO suppliers (name, contact_name, phone, email, address)
             VALUES (:name, :contact_name, :phone, :email, :address)'
        )->execute([
            'name' => $data['name'],
            'contact_name' => $data['contact_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
        ]);
        respond(['id' => $pdo->lastInsertId()], 201);
        return;
    }

    if ($method === 'PUT' && $id !== null) {
        require_auth($user);
        $data = json_body();
        $pdo->prepare(
            'UPDATE suppliers SET name = :name, contact_name = :contact_name, phone = :phone,
                    email = :email, address = :address WHERE id = :id'
        )->execute([
            'id' => $id,
            'name' => $data['name'] ?? '',
            'contact_name' => $data['contact_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
        ]);
        respond(['updated' => true]);
        return;
    }

    if ($method === 'DELETE' && $id !== null) {
        require_auth($user, 'admin');
        $pdo->prepare('DELETE FROM suppliers WHERE id = :id')->execute(['id' => $id]);
        respond(['deleted' => true]);
        return;
    }

    fail('Unsupported supplier operation', 400);
}

function handle_purchase_orders(PDO $pdo, string $method, array $segments, ?array $user): void
{
    require_auth($user);
    $id = $segments[1] ?? null;
    $action = $segments[2] ?? '';

    if ($method === 'GET' && $id === null) {
        $stmt = $pdo->query('SELECT po.id, po.status, po.expected_date, po.notes, po.created_at, s.name AS supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON s.id = po.supplier_id ORDER BY po.created_at DESC LIMIT 200');
        respond(['items' => $stmt->fetchAll()]);
        return;
    }

    if ($method === 'GET' && $id !== null) {
        $stmt = $pdo->prepare('SELECT po.*, s.name AS supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON s.id = po.supplier_id WHERE po.id = :id');
        $stmt->execute(['id' => $id]);
        $po = $stmt->fetch();
        if (!$po) {
            fail('PO not found', 404);
            return;
        }
        $items = $pdo->prepare('SELECT i.*, p.name AS part_name FROM purchase_order_items i JOIN parts p ON p.id = i.part_id WHERE i.purchase_order_id = :id');
        $items->execute(['id' => $id]);
        $po['items'] = $items->fetchAll();
        respond($po);
        return;
    }

    if ($method === 'POST' && $id === null) {
        $data = json_body();
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO purchase_orders (supplier_id, status, expected_date, notes, created_by) VALUES (:supplier_id, :status, :expected, :notes, :created_by)')
            ->execute([
                'supplier_id' => $data['supplier_id'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'expected' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user['id'] ?? null,
            ]);
        $poId = (int) $pdo->lastInsertId();
        foreach ($data['items'] ?? [] as $item) {
            $pdo->prepare('INSERT INTO purchase_order_items (purchase_order_id, part_id, qty_ordered, price) VALUES (:po, :part, :qty, :price)')
                ->execute([
                    'po' => $poId,
                    'part' => (int) $item['part_id'],
                    'qty' => (int) $item['qty_ordered'],
                    'price' => $item['price'] ?? null,
                ]);
        }
        $pdo->commit();
        respond(['id' => $poId], 201);
        return;
    }

    if ($method === 'PUT' && $id !== null && $action === 'receive') {
        $data = json_body();
        $pdo->beginTransaction();
        foreach ($data['items'] ?? [] as $item) {
            $partId = (int) $item['part_id'];
            $qty = max(0, (int) $item['qty_received']);
            $rowStmt = $pdo->prepare('SELECT qty_ordered, qty_received FROM purchase_order_items WHERE purchase_order_id = :po AND part_id = :part FOR UPDATE');
            $rowStmt->execute(['po' => $id, 'part' => $partId]);
            $row = $rowStmt->fetch();
            if (!$row) {
                continue;
            }
            $remaining = max(0, (int) $row['qty_ordered'] - (int) $row['qty_received']);
            $applyQty = min($remaining, $qty);
            if ($applyQty <= 0) {
                continue;
            }
            $pdo->prepare('UPDATE purchase_order_items SET qty_received = qty_received + :qty WHERE purchase_order_id = :po AND part_id = :part')
                ->execute(['qty' => $applyQty, 'po' => $id, 'part' => $partId]);
            // Increase stock for received quantities.
            $pdo->prepare('UPDATE parts SET quantity = quantity + :qty, updated_at = NOW() WHERE id = :part')
                ->execute(['qty' => $applyQty, 'part' => $partId]);
            $pdo->prepare('INSERT INTO stock_movements (part_id, change_type, quantity, note, created_at, user_id) VALUES (:part, "in", :qty, :note, NOW(), :user)')
                ->execute(['part' => $partId, 'qty' => $applyQty, 'note' => 'PO #' . $id, 'user' => $user['id'] ?? null]);
        }
        $pdo->prepare('UPDATE purchase_orders SET status = :status WHERE id = :id')
            ->execute(['status' => $data['status'] ?? 'received', 'id' => $id]);
        $pdo->commit();
        respond(['received' => true]);
        return;
    }

    if ($method === 'PUT' && $id !== null && $action === '') {
        $data = json_body();
        $pdo->prepare('UPDATE purchase_orders SET status = :status, expected_date = :expected, notes = :notes WHERE id = :id')
            ->execute([
                'status' => $data['status'] ?? 'draft',
                'expected' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'id' => $id,
            ]);
        respond(['updated' => true]);
        return;
    }

    fail('Unsupported purchase order operation', 400);
}

function handle_reservations(PDO $pdo, string $method, array $segments, ?array $user): void
{
    require_auth($user);
    $id = $segments[1] ?? null;
    $action = $segments[2] ?? '';

    if ($method === 'GET' && $id === null) {
        $stmt = $pdo->query('SELECT r.*, p.name AS part_name FROM reservations r JOIN parts p ON p.id = r.part_id ORDER BY r.created_at DESC LIMIT 200');
        respond(['items' => $stmt->fetchAll()]);
        return;
    }

    if ($method === 'POST' && $id === null) {
        $data = json_body();
        $pdo->prepare('INSERT INTO reservations (part_id, reserved_qty, status, reference_code, note, created_by) VALUES (:part, :qty, :status, :ref, :note, :user)')
            ->execute([
                'part' => (int) $data['part_id'],
                'qty' => max(0, (int) ($data['reserved_qty'] ?? 0)),
                'status' => $data['status'] ?? 'open',
                'ref' => $data['reference_code'] ?? null,
                'note' => $data['note'] ?? null,
                'user' => $user['id'] ?? null,
            ]);
        respond(['id' => $pdo->lastInsertId()], 201);
        return;
    }

    if ($method === 'PUT' && $id !== null && $action === 'status') {
        $data = json_body();
        $newStatus = $data['status'] ?? 'open';
        $pdo->beginTransaction();
        $res = $pdo->prepare('SELECT part_id, reserved_qty, status FROM reservations WHERE id = :id FOR UPDATE');
        $res->execute(['id' => $id]);
        $row = $res->fetch();
        if (!$row) {
            $pdo->rollBack();
            fail('Reservation not found', 404);
            return;
        }
        if ($row['status'] === 'fulfilled') {
            $pdo->rollBack();
            fail('Already fulfilled', 400);
            return;
        }
        if ($newStatus === 'fulfilled') {
            // Perform stock out for reserved quantity.
            $partId = (int) $row['part_id'];
            $qty = (int) $row['reserved_qty'];
            $partStmt = $pdo->prepare('SELECT quantity FROM parts WHERE id = :id FOR UPDATE');
            $partStmt->execute(['id' => $partId]);
            $available = (int) $partStmt->fetchColumn();
            if ($qty > $available) {
                $pdo->rollBack();
                fail('Insufficient stock to fulfill reservation', 400);
                return;
            }
            $pdo->prepare('UPDATE parts SET quantity = quantity - :qty, updated_at = NOW() WHERE id = :id')->execute(['qty' => $qty, 'id' => $partId]);
            $pdo->prepare('INSERT INTO stock_movements (part_id, change_type, quantity, note, created_at, user_id) VALUES (:part, "out", :qty, :note, NOW(), :user)')
                ->execute(['part' => $partId, 'qty' => $qty, 'note' => 'Reservation #' . $id, 'user' => $user['id'] ?? null]);
        }
        $pdo->prepare('UPDATE reservations SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $id]);
        $pdo->commit();
        respond(['updated' => true]);
        return;
    }

    fail('Unsupported reservation operation', 400);
}

function handle_alerts(PDO $pdo, string $method, array $segments, ?array $user): void
{
    if ($method === 'GET' && ($segments[1] ?? '') === 'low') {
        $stmt = $pdo->query('SELECT id, name, sku, quantity, reorder_level FROM parts WHERE reorder_level IS NOT NULL AND quantity <= reorder_level ORDER BY quantity ASC');
        respond(['items' => $stmt->fetchAll()]);
        return;
    }
    fail('Unsupported alerts operation', 400);
}

function handle_attachments(PDO $pdo, string $method, array $segments, ?array $user): void
{
    require_auth($user);
    if ($method === 'GET') {
        $entity = $segments[1] ?? null;
        $entityId = $segments[2] ?? null;
        if (!$entity || !$entityId) {
            fail('entity and id required');
            return;
        }
        $stmt = $pdo->prepare('SELECT * FROM attachments WHERE entity_type = :etype AND entity_id = :eid ORDER BY created_at DESC');
        $stmt->execute(['etype' => $entity, 'eid' => (int) $entityId]);
        respond(['items' => $stmt->fetchAll()]);
        return;
    }

    if ($method === 'POST') {
        $data = json_body();
        $pdo->prepare('INSERT INTO attachments (entity_type, entity_id, file_name, file_url, mime_type) VALUES (:etype, :eid, :name, :url, :mime)')
            ->execute([
                'etype' => $data['entity_type'] ?? 'part',
                'eid' => (int) ($data['entity_id'] ?? 0),
                'name' => $data['file_name'] ?? 'file',
                'url' => $data['file_url'] ?? '',
                'mime' => $data['mime_type'] ?? null,
            ]);
        respond(['id' => $pdo->lastInsertId()], 201);
        return;
    }
    fail('Unsupported attachments operation', 400);
}

function handle_reports(PDO $pdo, string $method, array $segments, ?array $user): void
{
    require_auth($user);
    $topic = $segments[1] ?? 'summary';
    if ($method !== 'GET') {
        fail('Unsupported report operation', 400);
        return;
    }

    if ($topic === 'summary') {
        $stats = [
            'parts' => (int) $pdo->query('SELECT COUNT(*) FROM parts')->fetchColumn(),
            'suppliers' => (int) $pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn(),
            'low_stock' => (int) $pdo->query('SELECT COUNT(*) FROM parts WHERE reorder_level IS NOT NULL AND quantity <= reorder_level')->fetchColumn(),
            'total_quantity' => (int) $pdo->query('SELECT COALESCE(SUM(quantity),0) FROM parts')->fetchColumn(),
            'inventory_value' => (float) $pdo->query('SELECT COALESCE(SUM(quantity * IFNULL(price,0)),0) FROM parts')->fetchColumn(),
        ];
        respond($stats);
        return;
    }

    if ($topic === 'slow-movers') {
        $stmt = $pdo->query('SELECT p.id, p.name, p.sku, COALESCE(SUM(CASE WHEN sm.change_type = "out" THEN sm.quantity ELSE 0 END),0) AS moved_out FROM parts p LEFT JOIN stock_movements sm ON sm.part_id = p.id GROUP BY p.id, p.name, p.sku ORDER BY moved_out ASC LIMIT 20');
        respond(['items' => $stmt->fetchAll()]);
        return;
    }

    fail('Report not found', 404);
}

function csv_escape(string $value): string
{
    $value = (string) $value;
    $needsQuote = str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n");
    $value = str_replace('"', '""', $value);
    return $needsQuote ? '"' . $value . '"' : $value;
}
