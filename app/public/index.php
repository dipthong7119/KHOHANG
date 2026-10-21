<?php
declare(strict_types=1);
session_start();

$host = getenv('MYSQL_HOST') ?: 'db';
$db = getenv('MYSQL_DATABASE') ?: 'inventory';
$user = getenv('MYSQL_USER') ?: 'inventory_user';
$pass = getenv('MYSQL_PASSWORD') ?: '';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(503);
    exit('Không kết nối được cơ sở dữ liệu. Kiểm tra log container.');
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Phiên không hợp lệ. Hãy tải lại trang.');
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'supplier') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Tên nhà cung cấp không được để trống.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO suppliers (name, phone, email) VALUES (?, ?, ?)'
            );
            $stmt->execute([
                $name,
                trim((string)($_POST['phone'] ?? '')),
                trim((string)($_POST['email'] ?? '')),
            ]);
            $_SESSION['flash'] = ['ok', 'Đã thêm nhà cung cấp.'];

        } elseif ($action === 'product') {
            $sku = trim((string)($_POST['sku'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $price = $_POST['unit_price'] ?? '0';
            $supplier = trim((string)($_POST['supplier_id'] ?? ''));

            if ($sku === '' || $name === '' || !is_numeric($price) || (float)$price < 0) {
                throw new RuntimeException('Kiểm tra mã SKU, tên mặt hàng và đơn giá.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO products (sku, name, supplier_id, unit_price)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([
                $sku,
                $name,
                $supplier === '' ? null : (int)$supplier,
                (float)$price,
            ]);
            $_SESSION['flash'] = ['ok', 'Đã thêm mặt hàng.'];

        } elseif ($action === 'movement') {
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
            $type = (string)($_POST['movement_type'] ?? '');
            $note = trim((string)($_POST['note'] ?? ''));

            if (!$productId || !$quantity || $quantity < 1 ||
                !in_array($type, ['IN', 'OUT'], true)) {
                throw new RuntimeException('Chọn mặt hàng, loại giao dịch và số lượng hợp lệ.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT quantity FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            if (!$product) {
                throw new RuntimeException('Không tìm thấy mặt hàng.');
            }

            $current = (int)$product['quantity'];
            if ($type === 'OUT' && $quantity > $current) {
                throw new RuntimeException('Số lượng xuất vượt quá tồn kho.');
            }

            $newQuantity = $type === 'IN'
                ? $current + $quantity
                : $current - $quantity;

            $stmt = $pdo->prepare('UPDATE products SET quantity = ? WHERE id = ?');
            $stmt->execute([$newQuantity, $productId]);

            $stmt = $pdo->prepare(
                'INSERT INTO stock_movements (product_id, movement_type, quantity, note)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$productId, $type, $quantity, $note]);
            $pdo->commit();

            $_SESSION['flash'] = ['ok', 'Đã ghi nhận giao dịch kho.'];

        } else {
            throw new RuntimeException('Thao tác không hợp lệ.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Không lưu được dữ liệu. Hãy kiểm tra mã SKU không bị trùng.';
        error_log($e->getMessage());
        $_SESSION['flash'] = ['error', $message];
    }

    header('Location: /');
    exit;
}

$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();

$products = $pdo->query(
    'SELECT p.id, p.sku, p.name, p.unit_price, p.quantity, s.name AS supplier_name
     FROM products p
     LEFT JOIN suppliers s ON s.id = p.supplier_id
     ORDER BY p.name'
)->fetchAll();

$movements = $pdo->query(
    'SELECT m.movement_type, m.quantity, m.note, m.created_at, p.sku, p.name
     FROM stock_movements m
     JOIN products p ON p.id = m.product_id
     ORDER BY m.id DESC
     LIMIT 20'
)->fetchAll();
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quản lý kho hàng</title>
    <style>
        body { font: 15px Arial, sans-serif; margin: 30px; background: #f3f6fa; color: #182230; }
        main { max-width: 1100px; margin: auto; }
        h1, h2 { color: #1457a6; }
        section, .panel { background: white; padding: 20px; margin: 16px 0; border-radius: 10px; }
        .forms { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
        input, select, button { box-sizing: border-box; width: 100%; padding: 10px; margin: 5px 0; }
        button { background: #1457a6; color: white; border: 0; border-radius: 5px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; background: white; }
        th, td { border-bottom: 1px solid #dce3ec; padding: 10px; text-align: left; }
        .ok { background: #e7f7ed; padding: 12px; }
        .error { background: #ffeded; padding: 12px; }
        .scroll { overflow-x: auto; }
    </style>
</head>
<body>
<main>
    <h1>Hệ thống quản lý kho hàng</h1>

    <?php if ($flash): ?>
        <p class="<?= h($flash[0]) ?>"><?= h($flash[1]) ?></p>
    <?php endif; ?>

    <div class="forms">
        <section>
            <h2>Thêm nhà cung cấp</h2>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="supplier">
                <input name="name" placeholder="Tên nhà cung cấp" required>
                <input name="phone" placeholder="Số điện thoại">
                <input name="email" type="email" placeholder="Email">
                <button type="submit">Lưu nhà cung cấp</button>
            </form>
        </section>

        <section>
            <h2>Thêm mặt hàng</h2>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="product">
                <input name="sku" placeholder="Mã SKU" required>
                <input name="name" placeholder="Tên mặt hàng" required>
                <input name="unit_price" type="number" min="0" step="0.01"
                       placeholder="Đơn giá" value="0" required>
                <select name="supplier_id">
                    <option value="">Chưa chọn nhà cung cấp</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= h($supplier['id']) ?>"><?= h($supplier['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Lưu mặt hàng</button>
            </form>
        </section>

        <section>
            <h2>Nhập hoặc xuất kho</h2>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="movement">
                <select name="product_id" required>
                    <option value="">Chọn mặt hàng</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= h($product['id']) ?>">
                            <?= h($product['sku']) ?> — <?= h($product['name']) ?>
                            (tồn <?= h($product['quantity']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="movement_type" required>
                    <option value="IN">Nhập kho</option>
                    <option value="OUT">Xuất kho</option>
                </select>
                <input name="quantity" type="number" min="1" placeholder="Số lượng" required>
                <input name="note" placeholder="Ghi chú">
                <button type="submit">Ghi nhận</button>
            </form>
        </section>
    </div>

    <section>
        <h2>Tồn kho</h2>
        <div class="scroll">
            <table>
                <tr><th>SKU</th><th>Mặt hàng</th><th>Nhà cung cấp</th><th>Đơn giá</th><th>Tồn</th></tr>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?= h($product['sku']) ?></td>
                        <td><?= h($product['name']) ?></td>
                        <td><?= h($product['supplier_name'] ?? '') ?></td>
                        <td><?= h($product['unit_price']) ?></td>
                        <td><?= h($product['quantity']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </section>

    <section>
        <h2>Lịch sử nhập/xuất gần đây</h2>
        <div class="scroll">
            <table>
                <tr><th>Thời gian</th><th>SKU</th><th>Mặt hàng</th><th>Loại</th><th>Số lượng</th><th>Ghi chú</th></tr>
                <?php foreach ($movements as $movement): ?>
                    <tr>
                        <td><?= h($movement['created_at']) ?></td>
                        <td><?= h($movement['sku']) ?></td>
                        <td><?= h($movement['name']) ?></td>
                        <td><?= $movement['movement_type'] === 'IN' ? 'Nhập' : 'Xuất' ?></td>
                        <td><?= h($movement['quantity']) ?></td>
                        <td><?= h($movement['note'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </section>
</main>
</body>
</html>
