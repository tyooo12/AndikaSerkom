<?php
session_start();
require_once 'koneksi.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

 $message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'register') {
        $name     = trim($_POST['name']);
        $email    = trim($_POST['email']);
        $password = trim($_POST['password']);

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $message = "Email sudah terdaftar!";
            $message_type = "danger";
        } else {
            $hashedPass = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'user')");
            $stmt->execute([$name, $email, $hashedPass]);

            $_SESSION['user'] = [
                'id'    => $pdo->lastInsertId(),
                'name'  => $name,
                'email' => $email,
                'role'  => 'user'
            ];
            $message = "Registrasi berhasil! Anda telah masuk.";
            $message_type = "success";
        }
    }

    elseif ($action === 'login') {
        $email    = trim($_POST['email']);
        $password = trim($_POST['password']);

        if ($email === 'admin@AndikaBooks.com' && $password === 'admin123') {
            $_SESSION['user'] = [
                'id'    => 1,
                'name'  => 'Admin Andika',
                'email' => 'admin@AndikaBooks.com',
                'role'  => 'admin'
            ];
            $message = "Selamat datang kembali, Admin!";
            $message_type = "success";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user'] = [
                    'id'    => $user['id'],
                    'name'  => $user['name'],
                    'email' => $user['email'],
                    'role'  => $user['role']
                    ];
                    $message = "Login berhasil! Selamat datang, " . htmlspecialchars($user['name']);
                    $message_type = "success";
                } else {
                    $message = "Email atau Password salah!";
                    $message_type = "danger";
                }
            }
        }

    elseif ($action === 'logout') {
        unset($_SESSION['user']);
        $message = "Anda telah keluar.";
        $message_type = "info";
    }

    elseif ($action === 'add_to_cart') {
        $book_id = (int)$_POST['book_id'];
        $stmt = $pdo->prepare("SELECT * FROM books WHERE id = ?");
        $stmt->execute([$book_id]);
        $book = $stmt->fetch();

        if ($book) {
            $discountedPrice = round($book['original_price'] * (1 - ($book['discount_percent'] / 100)));
            $found = false;
            foreach ($_SESSION['cart'] as &$item) {
                if ($item['book_id'] == $book_id) {
                    $item['qty'] += 1;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $_SESSION['cart'][] = [
                    'book_id'   => $book['id'],
                    'title'     => $book['title'],
                    'price'     => $discountedPrice,
                    'qty'       => 1,
                    'cover_url' => $book['cover_url']
                ];
            }
            $message = "Buku ditambahkan ke keranjang!";
            $message_type = "success";
        }
    }

    elseif ($action === 'update_cart') {
        $index  = (int)$_POST['index'];
        $change = (int)$_POST['change'];
        if (isset($_SESSION['cart'][$index])) {
            $_SESSION['cart'][$index]['qty'] += $change;
            if ($_SESSION['cart'][$index]['qty'] <= 0) {
                array_splice($_SESSION['cart'], $index, 1);
            }
        }
    } elseif ($action === 'remove_cart') {
        $index = (int)$_POST['index'];
        if (isset($_SESSION['cart'][$index])) {
            array_splice($_SESSION['cart'], $index, 1);
        }
    } elseif ($action === 'clear_cart') {
        $_SESSION['cart'] = [];
    }

    elseif ($action === 'checkout') {
        if (!isset($_SESSION['user'])) {
            $message = "Silakan login terlebih dahulu untuk checkout!";
            $message_type = "warning";
        } elseif (empty($_SESSION['cart'])) {
            $message = "Keranjang belanja Anda kosong!";
            $message_type = "warning";
        } else {
            $address = trim($_POST['address']);
            $courier = trim($_POST['courier']);
            $payment = trim($_POST['payment_method']);
            
            $totalAmount = 0;
            foreach ($_SESSION['cart'] as $item) {
                $totalAmount += ($item['price'] * $item['qty']);
            }

            $orderCode = 'AB-' . date('Ymd') . rand(100, 999);
            
            $stmt = $pdo->prepare("INSERT INTO orders (order_code, user_id, user_name, user_email, address, courier, payment_method, total_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Diproses')");
            $stmt->execute([$orderCode, $_SESSION['user']['id'], $_SESSION['user']['name'], $_SESSION['user']['email'], $address, $courier, $payment, $totalAmount]);
            $orderId = $pdo->lastInsertId();

            $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, book_id, book_title, price, qty, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($_SESSION['cart'] as $item) {
                $subtotal = $item['price'] * $item['qty'];
                $stmtItem->execute([$orderId, $item['book_id'], $item['title'], $item['price'], $item['qty'], $subtotal]);
            }

            $_SESSION['cart'] = [];
            $message = "Pesanan Berhasil dibuat! Kode Order: #$orderCode";
            $message_type = "success";
        }
    }

    elseif ($action === 'save_book' && isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin') {
        $id               = $_POST['book_id'] ?? '';
        $title            = trim($_POST['title']);
        $author           = trim($_POST['author']);
        $category_id      = (int)$_POST['category_id'];
        $original_price   = (float)$_POST['original_price'];
        $discount_percent = (int)$_POST['discount_percent'];
        $cover_url        = trim($_POST['cover_url']);

        if (!empty($id)) {
            $stmt = $pdo->prepare("UPDATE books SET category_id=?, title=?, author=?, original_price=?, discount_percent=?, cover_url=? WHERE id=?");
            $stmt->execute([$category_id, $title, $author, $original_price, $discount_percent, $cover_url, $id]);
            $message = "Data buku berhasil diperbarui!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO books (category_id, title, author, original_price, discount_percent, cover_url) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$category_id, $title, $author, $original_price, $discount_percent, $cover_url]);
            $message = "Buku baru berhasil ditambahkan!";
        }
        $message_type = "success";
    } elseif ($action === 'delete_book' && isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin') {
        $id = (int)$_POST['book_id'];
        $stmt = $pdo->prepare("DELETE FROM books WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Buku berhasil dihapus!";
        $message_type = "success";
    }

    elseif ($action === 'add_category' && isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin') {
        $name = trim($_POST['name']);
        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
            $stmt->execute([$name]);
            $message = "Kategori berhasil ditambahkan!";
            $message_type = "success";
        }
    } elseif ($action === 'delete_category' && isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin') {
        $id = (int)$_POST['category_id'];
        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Kategori berhasil dihapus!";
        $message_type = "success";
    }

    elseif ($action === 'update_order_status' && isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin') {
        $order_id = (int)$_POST['order_id'];
        $status   = $_POST['status'];
        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$status, $order_id]);
        $message = "Status pesanan berhasil diperbarui!";
        $message_type = "success";
    }
}

$selectedCategory = $_GET['cat'] ?? 'Semua';
$searchQuery      = $_GET['q'] ?? '';

$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

$sql = "SELECT b.*, c.name as category_name FROM books b JOIN categories c ON b.category_id = c.id WHERE 1=1";
$params = [];

if ($selectedCategory !== 'Semua') {
    $sql .= " AND c.name = ?";
    $params[] = $selectedCategory;
}

if (!empty($searchQuery)) {
    $sql .= " AND (b.title LIKE ? OR b.author LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

$sql .= " ORDER BY b.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll();

$userOrders = [];
$adminOrders = [];

if (isset($_SESSION['user'])) {
    if ($_SESSION['user']['role'] === 'admin') {
        $adminOrders = $pdo->query("SELECT * FROM orders ORDER BY id DESC")->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC");
        $stmt->execute([$_SESSION['user']['id']]);
        $userOrders = $stmt->fetchAll();
    }
}

$cartCount = array_reduce($_SESSION['cart'], function($sum, $item) { return $sum + $item['qty']; }, 0);
    ?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Andika Books - Toko Buku Online</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --brand-primary: #4f46e5;
            --brand-primary-dark: #4338ca;
            --brand-green: #10b981;
            --brand-red: #ef4444;
            --bg-light: #f8fafc;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-light);
            color: #0f172a;
        }

        .top-navbar {
            background-color: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-primary-dark) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 800;
            font-size: 1.2rem;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25);
        }

        .brand-text .sub {
            font-size: 0.65rem;
            font-weight: 800;
            color: var(--brand-primary);
            letter-spacing: 1px;
            display: block;
        }

        .brand-text .main {
            font-size: 1.25rem;
            font-weight: 800;
            color: #0f172a;
        }

        .search-input {
            border-radius: 25px;
            background-color: #f1f5f9;
            border: 1px solid #e2e8f0;
            padding-left: 18px;
            font-size: 0.9rem;
        }

        .category-bar {
            background-color: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            overflow-x: auto;
            white-space: nowrap;
        }

        .category-item {
            display: inline-block;
            padding: 12px 18px;
            font-size: 0.88rem;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }

        .category-item:hover, .category-item.active {
            color: var(--brand-primary);
            border-bottom-color: var(--brand-primary);
        }

        .book-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
        }

        .book-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.06);
            border-color: rgba(79, 70, 229, 0.3);
        }

        .book-cover-wrapper {
            position: relative;
            width: 100%;
            height: 210px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #f8fafc;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .book-cover {
            max-height: 100%;
            max-width: 100%;
            object-fit: contain;
            border-radius: 4px;
        }

        .book-title {
            font-size: 0.92rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            height: 2.6em;
        }

        .book-author {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 10px;
        }

        .price-old {
            font-size: 0.78rem;
            color: var(--brand-red);
            text-decoration: line-through;
            font-weight: 600;
        }

        .price-new {
            font-size: 1rem;
            font-weight: 800;
            color: var(--brand-green);
        }

        .discount-badge {
            position: absolute;
            bottom: 0;
            right: 0;
            background-color: var(--brand-red);
            color: white;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .btn-brand {
            background-color: var(--brand-primary);
            color: white;
            font-weight: 600;
            border-radius: 8px;
            border: none;
        }

        .btn-brand:hover {
            background-color: var(--brand-primary-dark);
            color: white;
        }

        .btn-outline-brand {
            border: 1px solid var(--brand-primary);
            color: var(--brand-primary);
            font-weight: 600;
            border-radius: 8px;
        }

        .btn-outline-brand:hover {
            background-color: var(--brand-primary);
            color: white;
        }

        .badge-admin {
            background-color: #f59e0b;
            color: #ffffff;
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 700;
        }
    </style>
</head>
<body>

   <nav class="top-navbar py-2 px-3 px-md-4">
        <div class="container-fluid d-flex align-items-center justify-content-between gap-3">
            <a href="index.php" class="brand-logo">
                <div class="logo-icon"><i class="bi bi-book-half"></i></div>
                <div class="brand-text">
                    <span class="sub">ONLINE BOOKSTORE</span>
                    <span class="main">ANDIKA BOOKS</span>
                </div>
            </a>

            <form action="index.php" method="GET" class="flex-grow-1 mx-2 mx-md-4" style="max-width: 500px;">
                <div class="position-relative">
                    <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" class="form-control search-input" placeholder="Mau cari buku apa hari ini?">
                    <button type="submit" class="btn p-0 position-absolute top-50 end-0 translate-middle-y me-3 border-0 bg-transparent">
                        <i class="bi bi-search text-muted"></i>
                    </button>
                </div>
            </form>

            <div class="d-flex align-items-center gap-2 gap-md-3">
                <button class="btn btn-light btn-sm rounded-circle p-2 text-muted" data-bs-toggle="modal" data-bs-target="#aboutModal" title="About Us">
                    <i class="bi bi-info-circle fs-5"></i>
                </button>
                <button class="btn btn-light btn-sm rounded-circle p-2 text-muted" data-bs-toggle="modal" data-bs-target="#contactModal" title="Contact Admin">
                    <i class="bi bi-headset fs-5"></i>
                </button>

                <button class="btn btn-light btn-sm rounded-circle p-2 position-relative text-primary" data-bs-toggle="modal" data-bs-target="#cartModal" title="Keranjang Belanja">
                    <i class="bi bi-bag-check-fill fs-5"></i>
                    <?php if ($cartCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $cartCount ?></span>
                    <?php endif; ?>
                </button>

                <?php if (isset($_SESSION['user'])): ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-primary btn-sm dropdown-toggle fw-bold" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-1"></i> <?= htmlspecialchars($_SESSION['user']['name']) ?>
                            <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                <span class="badge-admin">ADMIN</span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                <li>
                                    <button class="dropdown-item text-primary fw-bold" data-bs-toggle="modal" data-bs-target="#adminModal">
                                        <i class="bi bi-speedometer2 me-2"></i>Panel Admin
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                            <?php endif; ?>
                            <li>
                                <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#userOrdersModal">
                                    <i class="bi bi-bag me-2"></i>Pesanan Saya
                                </button>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form action="index.php" method="POST">
                                    <input type="hidden" name="action" value="logout">
                                    <button type="submit" class="dropdown-item text-danger fw-bold"><i class="bi bi-box-arrow-right me-2"></i>Keluar</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <button class="btn btn-brand btn-sm px-3" data-bs-toggle="modal" data-bs-target="#loginModal">Masuk</button>
                    <button class="btn btn-outline-brand btn-sm px-3" data-bs-toggle="modal" data-bs-target="#registerModal">Daftar</button>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="category-bar px-3">
        <div class="container-fluid d-flex gap-1">
            <a href="index.php" class="category-item <?= $selectedCategory === 'Semua' ? 'active' : '' ?>">Semua</a>
            <?php foreach ($categories as $cat): ?>
                <a href="index.php?cat=<?= urlencode($cat['name']) ?>" class="category-item <?= $selectedCategory === $cat['name'] ? 'active' : '' ?>">
                    <?= htmlspecialchars($cat['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <main class="container-fluid py-4 px-3 px-md-4">
        
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin'): ?>
            <div class="alert alert-primary d-flex justify-content-between align-items-center mb-4">
                <div>
                    <i class="bi bi-shield-lock-fill me-2 fs-5"></i>
                    <strong>Akses Admin Andika Books Aktif!</strong> Anda dapat mengelola data buku, kategori, dan pesanan user.
                </div>
                <button class="btn btn-dark btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#adminModal">
                    <i class="bi bi-speedometer2 me-1"></i> Buka Panel Admin
                </button>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <?= $selectedCategory === 'Semua' ? 'Katalog Buku Pilihan' : 'Kategori: ' . htmlspecialchars($selectedCategory) ?>
                </h5>
                <small class="text-muted">Menampilkan <?= count($books) ?> koleksi buku</small>
            </div>
        </div>

        <div class="row g-3 g-md-4">
            <?php if (empty($books)): ?>
                <div class="col-12 text-center py-5 text-muted">
                    <i class="bi bi-book fs-1 d-block mb-2"></i>Buku tidak ditemukan.
                </div>
            <?php else: ?>
                <?php foreach ($books as $b): ?>
                    <?php $discountedPrice = round($b['original_price'] * (1 - ($b['discount_percent'] / 100))); ?>
                    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                        <div class="book-card">
                            <div class="book-cover-wrapper">
                                <img src="<?= htmlspecialchars($b['cover_url']) ?>" class="book-cover" alt="<?= htmlspecialchars($b['title']) ?>">
                                <?php if ($b['discount_percent'] > 0): ?>
                                    <span class="discount-badge"><?= $b['discount_percent'] ?>%</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="book-title" title="<?= htmlspecialchars($b['title']) ?>"><?= htmlspecialchars($b['title']) ?></div>
                                <div class="book-author"><?= htmlspecialchars($b['author']) ?></div>
                                <?php if ($b['discount_percent'] > 0): ?>
                                    <div class="price-old">Rp <?= number_format($b['original_price'], 0, ',', '.') ?>,-</div>
                                <?php endif; ?>
                                <div class="price-new">Rp <?= number_format($discountedPrice, 0, ',', '.') ?>,-</div>
                            </div>
                            
                            <form action="index.php" method="POST" class="mt-2">
                                <input type="hidden" name="action" value="add_to_cart">
                                <input type="hidden" name="book_id" value="<?= $b['id'] ?>">
                                <button type="submit" class="btn btn-outline-brand btn-sm w-100">
                                    <i class="bi bi-cart-plus me-1"></i> Beli
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <div class="modal fade" id="cartModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold text-primary"><i class="bi bi-cart3 me-2"></i>Keranjang Belanja Saya</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="fw-bold mb-0">Item dalam Keranjang</h6>
                                <?php if (!empty($_SESSION['cart'])): ?>
                                    <form action="index.php" method="POST">
                                        <input type="hidden" name="action" value="clear_cart">
                                        <button type="submit" class="btn btn-sm btn-link text-danger text-decoration-none p-0">
                                            <i class="bi bi-trash me-1"></i> Kosongkan
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <div style="max-height: 320px; overflow-y: auto; padding-right: 5px;">
                                <?php if (empty($_SESSION['cart'])): ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="bi bi-cart-x fs-1 d-block mb-2 text-secondary"></i>
                                        <p class="mb-0">Keranjang belanja Anda masih kosong.</p>
                                    </div>
                                <?php else: ?>
                                    <?php 
                                    $grandTotal = 0;
                                    foreach ($_SESSION['cart'] as $index => $item): 
                                        $subtotal = $item['price'] * $item['qty'];
                                        $grandTotal += $subtotal;
                                    ?>
                                        <div class="d-flex align-items-center gap-3 border rounded p-2 mb-2 bg-white">
                                            <img src="<?= htmlspecialchars($item['cover_url']) ?>" width="45" height="60" class="rounded object-fit-cover">
                                            <div class="flex-grow-1">
                                                <strong class="d-block text-truncate small" style="max-width: 180px;"><?= htmlspecialchars($item['title']) ?></strong>
                                                <small class="text-success fw-bold">Rp <?= number_format($item['price'], 0, ',', '.') ?></small>
                                            </div>
                                            
                                            <div class="d-flex align-items-center gap-1">
                                                <form action="index.php" method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="update_cart">
                                                    <input type="hidden" name="index" value="<?= $index ?>">
                                                    <input type="hidden" name="change" value="-1">
                                                    <button type="submit" class="btn btn-sm btn-light border px-2 py-0">-</button>
                                                </form>
                                                <span class="fw-bold small px-1"><?= $item['qty'] ?></span>
                                                <form action="index.php" method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="update_cart">
                                                    <input type="hidden" name="index" value="<?= $index ?>">
                                                    <input type="hidden" name="change" value="1">
                                                    <button type="submit" class="btn btn-sm btn-light border px-2 py-0">+</button>
                                                </form>
                                            </div>

                                            <div class="text-end" style="min-width: 80px;">
                                                <div class="small fw-bold">Rp <?= number_format($subtotal, 0, ',', '.') ?></div>
                                                <form action="index.php" method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="remove_cart">
                                                    <input type="hidden" name="index" value="<?= $index ?>">
                                                    <button type="submit" class="btn btn-link text-danger p-0 border-0 fs-7 text-decoration-none">Hapus</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-lg-5 bg-light p-3 rounded-3 border">
                            <h6 class="fw-bold mb-3"><i class="bi bi-truck me-2"></i>Pengiriman & Pembayaran</h6>
                            <form action="index.php" method="POST">
                                <input type="hidden" name="action" value="checkout">
                                <div class="mb-2">
                                    <label class="form-label extra-small text-muted mb-1 fw-semibold">Alamat Lengkap Pengiriman</label>
                                    <textarea name="address" class="form-control form-control-sm" rows="2" placeholder="Nama Jalan, Kota, Kode Pos" required></textarea>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label extra-small text-muted mb-1 fw-semibold">Opsi Kurir Pengiriman</label>
                                    <select name="courier" class="form-select form-select-sm" required>
                                        <option value="JNE Express (Rp 10.000)">JNE Express (Rp 10.000)</option>
                                        <option value="J&T Express (Rp 12.000)">J&T Express (Rp 12.000)</option>
                                        <option value="SiCepat (Rp 11.000)">SiCepat (Rp 11.000)</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label extra-small text-muted mb-1 fw-semibold">Metode Pembayaran</label>
                                    <select name="payment_method" class="form-select form-select-sm" required>
                                        <option value="COD (Bayar di Tempat)">COD (Bayar di Tempat)</option>
                                        <option value="Transfer Bank BCA">Transfer Bank BCA</option>
                                        <option value="Transfer Bank Mandiri">Transfer Bank Mandiri</option>
                                        <option value="QRIS / e-Wallet">QRIS / e-Wallet</option>
                                    </select>
                                </div>

                                <hr class="my-2">
                                <div class="d-flex justify-content-between align-items-center fw-bold fs-6 mb-3">
                                    <span>Total Bayar:</span>
                                    <span class="text-success fs-5">Rp <?= number_format($grandTotal ?? 0, 0, ',', '.') ?></span>
                                </div>

                                <button type="submit" class="btn btn-brand w-100 py-2 fw-bold" <?= empty($_SESSION['cart']) ? 'disabled' : '' ?>>
                                    <i class="bi bi-credit-card me-1"></i> Proses Checkout Sekarang
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="loginModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 rounded-4 shadow">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-primary"><i class="bi bi-box-arrow-in-right me-2"></i>Masuk Akun</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form action="index.php" method="POST">
                        <input type="hidden" name="action" value="login">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="nama@email.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Password</label>
                            <input type="password" name="password" class="form-control" placeholder="******" required>
                        </div>
                        <button type="submit" class="btn btn-brand w-100 py-2 mb-2">Masuk</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="registerModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 rounded-4 shadow">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-primary"><i class="bi bi-person-plus me-2"></i>Daftar Akun</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form action="index.php" method="POST">
                        <input type="hidden" name="action" value="register">
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Nama Lengkap</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-brand w-100 py-2 mb-2">Daftar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="userOrdersModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold text-primary"><i class="bi bi-clock-history me-2"></i>Riwayat Pesanan Saya</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <?php if (empty($userOrders)): ?>
                        <p class="text-muted text-center py-4">Belum ada riwayat pesanan.</p>
                    <?php else: ?>
                        <?php foreach ($userOrders as $ord): ?>
                            <div class="card border mb-3">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>#<?= htmlspecialchars($ord['order_code']) ?></strong>
                                        <small class="text-muted">(<?= date('d/m/Y H:i', strtotime($ord['created_at'])) ?>)</small>
                                    </div>
                                    <span class="badge bg-info text-dark"><?= $ord['status'] ?></span>
                                </div>
                                <div class="card-body">
                                    <p class="small mb-1"><b>Kurir:</b> <?= htmlspecialchars($ord['courier']) ?> | <b>Pembayaran:</b> <?= htmlspecialchars($ord['payment_method']) ?></p>
                                    <p class="small mb-2"><b>Alamat:</b> <?= htmlspecialchars($ord['address']) ?></p>
                                    <hr class="my-2">
                                    <div class="fw-bold text-success text-end">Total: Rp <?= number_format($ord['total_amount'], 0, ',', '.') ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="aboutModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold text-primary"><i class="bi bi-info-circle me-2"></i>About Us</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div class="logo-icon mx-auto mb-3" style="width: 50px; height: 50px; font-size: 1.5rem;"><i class="bi bi-book-half"></i></div>
                    <h5 class="fw-bold text-primary mb-1">Andika Books</h5>
                    <p class="text-muted small">Toko Buku Online Modern</p>
                    <hr>
                    <p class="small text-start text-secondary">
                        Andika Books menyediakan berbagai koleksi buku terlengkap dari berbagai genre dengan harga terjangkau serta metode pengiriman COD di seluruh Indonesia.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="contactModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold text-primary"><i class="bi bi-headset me-2"></i>Contact Admin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="list-group list-group-flush">
                        <a href="https://wa.me/6283198285308" target="_blank" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3">
                            <i class="bi bi-whatsapp text-success fs-3"></i>
                            <div>
                                <strong class="d-block">WhatsApp CS</strong>
                                <small class="text-muted">+62 831-9828-5308</small>
                            </div>
                        </a>
                        <div class="list-group-item d-flex align-items-center gap-3 py-3">
                            <i class="bi bi-envelope-at text-primary fs-3"></i>
                            <div>
                                <strong class="d-block">Email Admin</strong>
                                <small class="text-muted">admin@AndikaBooks.com</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin'): ?>
        <div class="modal fade" id="adminModal" tabindex="-1">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 rounded-4 shadow">
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title fw-bold"><i class="bi bi-speedometer2 me-2 text-warning"></i>Panel Admin Andika Books</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <ul class="nav nav-pills mb-4 gap-2" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active fw-bold" data-bs-toggle="pill" data-bs-target="#adminBooksTab">
                                    <i class="bi bi-journal-album me-1"></i> CRUD Buku
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link fw-bold" data-bs-toggle="pill" data-bs-target="#adminCategoriesTab">
                                    <i class="bi bi-tags me-1"></i> CRUD Kategori
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link fw-bold" data-bs-toggle="pill" data-bs-target="#adminOrdersTab">
                                    <i class="bi bi-receipt me-1"></i> Pesanan User
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="adminBooksTab">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="fw-bold mb-0">Manajemen Koleksi Buku</h6>
                                    <button class="btn btn-primary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#bookFormModal">
                                        <i class="bi bi-plus-lg me-1"></i> Tambah Buku Baru
                                    </button>
                                </div>
                                <div class="table-responsive bg-white rounded-3 border">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr class="small text-muted">
                                                <th>Cover</th>
                                                <th>Judul & Penulis</th>
                                                <th>Kategori</th>
                                                <th>Harga Asli</th>
                                                <th>Diskon</th>
                                                <th>Harga Diskon</th>
                                                <th class="text-end">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($books as $b): ?>
                                                <?php $disc = round($b['original_price'] * (1 - ($b['discount_percent'] / 100))); ?>
                                                <tr>
                                                    <td><img src="<?= htmlspecialchars($b['cover_url']) ?>" width="35" height="45" class="rounded object-fit-cover"></td>
                                                    <td><strong><?= htmlspecialchars($b['title']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($b['author']) ?></small></td>
                                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($b['category_name']) ?></span></td>
                                                    <td>Rp <?= number_format($b['original_price'], 0, ',', '.') ?></td>
                                                    <td><span class="badge bg-danger"><?= $b['discount_percent'] ?>%</span></td>
                                                    <td class="fw-bold text-success">Rp <?= number_format($disc, 0, ',', '.') ?></td>
                                                    <td class="text-end">
                                                        <form action="index.php" method="POST" class="d-inline">
                                                            <input type="hidden" name="action" value="delete_book">
                                                            <input type="hidden" name="book_id" value="<?= $b['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus buku ini?')"><i class="bi bi-trash"></i></button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="adminCategoriesTab">
                                <div class="row g-4">
                                    <div class="col-md-5">
                                        <div class="card border-0 shadow-sm p-3">
                                            <h6 class="fw-bold mb-3">Tambah Kategori Baru</h6>
                                            <form action="index.php" method="POST">
                                                <input type="hidden" name="action" value="add_category">
                                                <div class="mb-3">
                                                    <input type="text" name="name" class="form-control" placeholder="Nama Kategori" required>
                                                </div>
                                                <button type="submit" class="btn btn-primary w-100 fw-bold">Simpan Kategori</button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="col-md-7">
                                        <div class="table-responsive bg-white rounded-3 border">
                                            <table class="table table-hover align-middle mb-0">
                                                <thead class="table-light">
                                                    <tr class="small text-muted">
                                                        <th>ID</th>
                                                        <th>Nama Kategori</th>
                                                        <th class="text-end">Aksi</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($categories as $cat): ?>
                                                        <tr>
                                                            <td><?= $cat['id'] ?></td>
                                                            <td><strong><?= htmlspecialchars($cat['name']) ?></strong></td>
                                                            <td class="text-end">
                                                                <form action="index.php" method="POST" class="d-inline">
                                                                    <input type="hidden" name="action" value="delete_category">
                                                                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus kategori ini?')"><i class="bi bi-trash"></i> Hapus</button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="adminOrdersTab">
                                <h6 class="fw-bold mb-3">Daftar Transaksi Pesanan Masuk</h6>
                                <div class="table-responsive bg-white rounded-3 border">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr class="small text-muted">
                                                <th>Kode Order</th>
                                                <th>Pemesan</th>
                                                <th>Total</th>
                                                <th>Kurir & Bayar</th>
                                                <th>Status</th>
                                                <th class="text-end">Aksi Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($adminOrders as $ord): ?>
                                                <tr>
                                                    <td><strong class="text-primary">#<?= htmlspecialchars($ord['order_code']) ?></strong></td>
                                                    <td><strong><?= htmlspecialchars($ord['user_name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($ord['user_email']) ?></small></td>
                                                    <td class="fw-bold">Rp <?= number_format($ord['total_amount'], 0, ',', '.') ?></td>
                                                    <td><small><?= htmlspecialchars($ord['courier']) ?><br><?= htmlspecialchars($ord['payment_method']) ?></small></td>
                                                    <td><span class="badge bg-primary"><?= $ord['status'] ?></span></td>
                                                    <td class="text-end">
                                                        <form action="index.php" method="POST" class="d-inline">
                                                            <input type="hidden" name="action" value="update_order_status">
                                                            <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                                                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto; display: inline-block;">
                                                                <option value="Diproses" <?= $ord['status'] === 'Diproses' ? 'selected' : '' ?>>Diproses</option>
                                                                <option value="Dikirim" <?= $ord['status'] === 'Dikirim' ? 'selected' : '' ?>>Dikirim</option>
                                                                <option value="Selesai" <?= $ord['status'] === 'Selesai' ? 'selected' : '' ?>>Selesai</option>
                                                                <option value="Dibatalkan" <?= $ord['status'] === 'Dibatalkan' ? 'selected' : '' ?>>Dibatalkan</option>
                                                            </select>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="bookFormModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 rounded-4 shadow">
                    <div class="modal-header border-0">
                        <h5 class="modal-title fw-bold">Tambah Buku Baru</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <form action="index.php" method="POST">
                            <input type="hidden" name="action" value="save_book">
                            <div class="mb-2">
                                <label class="form-label small fw-semibold">Judul Buku</label>
                                <input type="text" name="title" class="form-control" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold">Penulis</label>
                                <input type="text" name="author" class="form-control" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold">Kategori</label>
                                <select name="category_id" class="form-select" required>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Harga Asli (Rp)</label>
                                    <input type="number" name="original_price" class="form-control" placeholder="99000" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Diskon (%)</label>
                                    <input type="number" name="discount_percent" class="form-control" placeholder="20" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">URL Cover Gambar</label>
                                <input type="url" name="cover_url" class="form-control" placeholder="https://..." required>
                            </div>
                            <button type="submit" class="btn btn-brand w-100 py-2">Simpan Buku</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>