<?php
session_start();
require_once 'includes/config.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Get current admin data
$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

// Connect to peminjaman_barang_db
$invDbName = 'peminjaman_barang_db';
$invConn = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
if (!$invConn) {
    $_SESSION['error'] = 'Gagal koneksi ke server database untuk inventory.';
    header('Location: dashboard.php');
    exit;
}

// Create database if not exists and select it
mysqli_query($invConn, "CREATE DATABASE IF NOT EXISTS `" . mysqli_real_escape_string($invConn, $invDbName) . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
mysqli_select_db($invConn, $invDbName);

// Create karyawan table if not exists
$createKaryawan = "CREATE TABLE IF NOT EXISTS `karyawan` (
    `id_karyawan` INT AUTO_INCREMENT PRIMARY KEY,
    `nama` VARCHAR(100) NOT NULL,
    `id_card` VARCHAR(50) UNIQUE,
    `divisi` VARCHAR(50),
    `jabatan` VARCHAR(50),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('aktif','tidak_aktif') DEFAULT 'aktif'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($invConn, $createKaryawan);

// Add optional columns if they don't exist
$alterQueries = [
    "ALTER TABLE `karyawan` ADD COLUMN IF NOT EXISTS `email` VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE `karyawan` ADD COLUMN IF NOT EXISTS `no_telp` VARCHAR(20) DEFAULT NULL",
    "ALTER TABLE `karyawan` ADD COLUMN IF NOT EXISTS `alamat` TEXT DEFAULT NULL"
];

foreach ($alterQueries as $alterQuery) {
    mysqli_query($invConn, $alterQuery);
}
mysqli_query($invConn, $createKaryawan);

// Handle add/edit karyawan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_karyawan') {
            $nama = mysqli_real_escape_string($invConn, $_POST['nama']);
            $id_card = mysqli_real_escape_string($invConn, $_POST['id_card']);
            $divisi = mysqli_real_escape_string($invConn, $_POST['divisi']);
            $jabatan = mysqli_real_escape_string($invConn, $_POST['jabatan']);
            
            // Handle optional fields
            $email = isset($_POST['email']) ? mysqli_real_escape_string($invConn, $_POST['email']) : '';
            $no_telp = isset($_POST['no_telp']) ? mysqli_real_escape_string($invConn, $_POST['no_telp']) : '';
            $alamat = isset($_POST['alamat']) ? mysqli_real_escape_string($invConn, $_POST['alamat']) : '';
            
            // Check if the karyawan table has the optional columns
            $checkColumns = mysqli_query($invConn, "DESCRIBE karyawan");
            $columns = [];
            while ($col = mysqli_fetch_assoc($checkColumns)) {
                $columns[] = $col['Field'];
            }
            
            // Build the query dynamically based on available columns
            $fields = ['nama', 'id_card', 'divisi', 'jabatan'];
            $values = ["'$nama'", "'$id_card'", "'$divisi'", "'$jabatan'"];
            
            if (in_array('email', $columns)) {
                $fields[] = 'email';
                $values[] = "'$email'";
            }
            if (in_array('no_telp', $columns)) {
                $fields[] = 'no_telp';
                $values[] = "'$no_telp'";
            }
            if (in_array('alamat', $columns)) {
                $fields[] = 'alamat';
                $values[] = "'$alamat'";
            }
            
            $query = "INSERT INTO karyawan (" . implode(", ", $fields) . ") 
                     VALUES (" . implode(", ", $values) . ")";
            
            if (mysqli_query($invConn, $query)) {
                $_SESSION['message'] = "Karyawan berhasil ditambahkan";
            } else {
                $_SESSION['error'] = "Gagal menambahkan karyawan: " . mysqli_error($invConn);
            }
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } elseif ($_POST['action'] === 'delete_karyawan') {
            $id = intval($_POST['id_karyawan']);
            $query = "DELETE FROM karyawan WHERE id_karyawan = $id";
            
            if (mysqli_query($invConn, $query)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => mysqli_error($invConn)]);
            }
            exit;
        }
    }
}

// Get karyawan list
$karyawan = [];
$result = mysqli_query($invConn, "SELECT * FROM karyawan ORDER BY created_at DESC");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $karyawan[] = $row;
    }
}

// Get all users for management
$users_query = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $users_query->fetch_all(MYSQLI_ASSOC);

// Get total kegiatan
$totalKegiatan = 0;
$kegiatan_query = $conn->query("SELECT COUNT(*) as total FROM jadwal");
if ($kegiatan_query) {
    $totalKegiatan = $kegiatan_query->fetch_assoc()['total'];
}

// (Optional) Get active sessions if you have a way to track them
$totalSessions = 0;
// Contoh: $sessions_query = $conn->query("SELECT COUNT(*) as total FROM sessions WHERE active=1");
// if ($sessions_query) { $totalSessions = $sessions_query->fetch_assoc()['total']; }
// Untuk demo, gunakan jumlah user
$totalSessions = count($users);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle delete user
    if (isset($_POST['delete_user_id'])) {
        $delete_id = intval($_POST['delete_user_id']);
        // Jangan izinkan admin menghapus dirinya sendiri
        if ($delete_id !== $_SESSION['user_id']) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $delete_id);
            $success = $stmt->execute();
            echo json_encode(['success' => $success]);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Tidak bisa menghapus akun sendiri!']);
            exit;
        }
    }
    
    // Handle update role
    if (isset($_POST['update_role'])) {
        $user_id = intval($_POST['user_id']);
        $new_role = $_POST['role'] === 'admin' ? 'admin' : 'user';
        
        // Jangan izinkan admin mengubah rolenya sendiri
        if ($user_id !== $_SESSION['user_id']) {
            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->bind_param("si", $new_role, $user_id);
            $success = $stmt->execute();
            if ($success) {
                $_SESSION['message'] = "Role user berhasil diubah menjadi " . ucfirst($new_role);
            } else {
                $_SESSION['error'] = "Gagal mengubah role user";
            }
        } else {
            $_SESSION['error'] = "Tidak bisa mengubah role sendiri!";
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel | Modern Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --primary-light: #818cf8;
            --primary-dark: #4f46e5;
            --secondary: #f97316;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e293b;
            --light: #f8fafc;
            --sidebar-width: 260px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
            color: var(--dark);
        }

        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
            z-index: 100;
        }

        .sidebar-collapsed {
            left: calc(-1 * var(--sidebar-width));
        }

        .main-content {
            margin-left: var(--sidebar-width);
            transition: all 0.3s;
        }

        .main-content-expanded {
            margin-left: 0;
        }

        .nav-link.active {
            background-color: rgba(99, 102, 241, 0.1);
            color: var(--primary);
            border-left: 3px solid var(--primary);
        }

        .nav-link:hover:not(.active) {
            background-color: rgba(99, 102, 241, 0.05);
        }

        .card-stat {
            transition: transform 0.3s;
        }

        .card-stat:hover {
            transform: translateY(-5px);
        }

        .badge-admin {
            background-color: rgba(99, 102, 241, 0.1);
            color: var(--primary);
        }

        .badge-user {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        @media (max-width: 768px) {
            .sidebar {
                left: calc(-1 * var(--sidebar-width));
            }

            .sidebar.active {
                left: 0;
            }

            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>

<body class="bg-gray-50">
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="p-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-primary flex items-center">
                    <i class="fas fa-cubes mr-2"></i> Admin<span class="text-primary-dark">Pro</span>
                </h1>
                <button id="sidebarToggleMobile" class="md:hidden text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <!-- Admin Profile -->
        <div class="p-4 border-b border-gray-200">
            <div class="flex items-center">
                <img src="https://placehold.co/100x100" alt="Admin Profile" class="w-10 h-10 rounded-full mr-3">
                <div>
                    <h3 class="font-medium"><?php echo htmlspecialchars($admin['username']); ?></h3>
                    <p class="text-xs text-gray-500">Administrator</p>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <div class="p-4">
            <ul class="space-y-2">
                <li>
                    <a href="admin.php" class="flex items-center px-3 py-2 rounded nav-link active">
                        <i class="fas fa-chart-pie mr-3 text-primary"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                        <li>
                            <a href="admin_izin.php" class="flex items-center px-3 py-2 rounded text-gray-700 hover:bg-gray-50">
                                <i class="fas fa-clipboard-list mr-3"></i>
                                <span>Manajemen Izin</span>
                            </a>
                        </li>
                <li class="pt-4 border-t border-gray-200">
                    <a href="dashboard.php" class="flex items-center px-3 py-2 rounded text-blue-500 hover:bg-blue-50">
                        <i class="fas fa-home mr-3"></i>
                        <span>Dashboard Utama</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation -->
        <header class="bg-white shadow-sm z-10">
            <?php if (isset($_SESSION['message'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline"><?php echo $_SESSION['message']; ?></span>
                </div>
                <?php unset($_SESSION['message']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline"><?php echo $_SESSION['error']; ?></span>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
            
            <div class="flex items-center justify-between p-4">
                <div class="flex items-center">
                    <button id="sidebarToggle" class="mr-4 text-gray-500">
                        <i class="fas fa-align-left"></i>
                    </button>
                    <h1 class="text-xl font-semibold text-gray-800">Dashboard Overview</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <button class="text-gray-500 hover:text-gray-700">
                            <i class="fas fa-bell"></i>
                            <span class="absolute top-0 right-0 w-2 h-2 bg-red-500 rounded-full"></span>
                        </button>
                    </div>
                    <div class="relative">
                        <img src="https://placehold.co/100x100" alt="Admin Profile" class="w-8 h-8 rounded-full cursor-pointer" id="profileDropdownBtn">
                        <div class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-20" id="profileDropdown">
                            <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Profile</a>
                            <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Settings</a>
                            <a href="logout.php" class="block px-4 py-2 text-sm text-red-500 hover:bg-gray-100">Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="p-6 max-w-7xl mx-auto">
            <!-- Page Header -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Dashboard Admin</h1>
                <p class="text-gray-600">Kelola pengguna dan karyawan sistem</p>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow-sm p-6 card-stat border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Users</p>
                            <h3 class="text-2xl font-bold mt-1 text-gray-900"><?php echo count($users); ?></h3>
                        </div>
                        <div class="bg-indigo-100 p-3 rounded-full">
                            <i class="fas fa-users text-indigo-600"></i>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center text-sm">
                        <span class="text-green-600 font-medium flex items-center">
                            <i class="fas fa-arrow-up mr-1"></i> 12.5%
                        </span>
                        <span class="text-gray-500 ml-2">dari bulan lalu</span>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm p-6 card-stat border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Karyawan</p>
                            <h3 class="text-2xl font-bold mt-1 text-gray-900"><?php echo count($karyawan); ?></h3>
                        </div>
                        <div class="bg-emerald-100 p-3 rounded-full">
                            <i class="fas fa-id-card text-emerald-600"></i>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center text-sm">
                        <span class="text-gray-500">Data aktif saat ini</span>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm p-6 card-stat border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Kegiatan</p>
                            <h3 class="text-2xl font-bold mt-1 text-gray-900"><?php echo $totalKegiatan; ?></h3>
                        </div>
                        <div class="bg-amber-100 p-3 rounded-full">
                            <i class="fas fa-calendar-alt text-amber-600"></i>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center text-sm">
                        <span class="text-gray-500">Kegiatan terdaftar</span>
                    </div>
                </div>
            </div>

            <!-- Karyawan Management Section -->
            <div class="mb-8">
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h2 class="text-xl font-bold text-gray-900">Manajemen Karyawan</h2>
                                <p class="text-gray-600 mt-1 text-sm">Kelola data karyawan untuk sistem peminjaman</p>
                            </div>
                            <button onclick="document.getElementById('addKaryawanModal').classList.remove('hidden')" 
                                class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition duration-150 flex items-center">
                                <i class="fas fa-plus mr-2"></i>Tambah Karyawan (Detail)
                            </button>
                        </div>
                        
                        <!-- Quick Add Form -->
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Tambah Karyawan Cepat</h3>
                            <form method="POST" action="" class="flex flex-wrap gap-4 items-end" id="quickAddForm" autocomplete="off">
                                <input type="hidden" name="action" value="add_karyawan">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">ID Card*</label>
                                    <input type="text" name="id_card" required placeholder="ID-001" autocomplete="off"
                                        class="w-36 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 bg-white">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Nama*</label>
                                    <input type="text" name="nama" required placeholder="Nama Lengkap" autocomplete="off"
                                        class="w-64 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 bg-white">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Divisi*</label>
                                    <input type="text" name="divisi" required placeholder="IT/HR/etc" autocomplete="off"
                                        class="w-40 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 bg-white">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Jabatan*</label>
                                    <input type="text" name="jabatan" required placeholder="Staff/Manager" autocomplete="off"
                                        class="w-40 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 bg-white">
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 transition duration-150 text-sm flex items-center">
                                        <i class="fas fa-plus-circle mr-1"></i> Tambah Cepat
                                    </button>
                                    <button type="reset" class="bg-gray-200 text-gray-600 px-4 py-2 rounded-md hover:bg-gray-300 transition duration-150 text-sm flex items-center">
                                        <i class="fas fa-undo mr-1"></i> Reset
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="mb-4 flex justify-between items-center">
                        <div class="flex space-x-2">
                            <button type="button" class="filter-btn active" data-filter="all">
                                Semua
                            </button>
                            <button type="button" class="filter-btn" data-filter="karyawan">
                                Karyawan Tetap
                            </button>
                            <button type="button" class="filter-btn" data-filter="intern">
                                Anak Magang
                            </button>
                        </div>
                        <div class="text-sm text-gray-500">
                            Total: <span id="total-count">0</span> • 
                            Karyawan: <span id="karyawan-count">0</span> • 
                            Magang: <span id="intern-count">0</span>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr class="bg-gray-50">
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">ID Card</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Nama</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Divisi</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Jabatan</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Kontak</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($karyawan as $k): 
                                    $isIntern = stripos($k['jabatan'], 'magang') !== false || stripos($k['jabatan'], 'intern') !== false;
                                ?>
                                    <tr class="<?php echo $isIntern ? 'bg-red-50' : ''; ?>">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($k['id_card']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($k['nama']); ?>
                                                    <span class="<?php echo $isIntern ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800'; ?> text-xs px-2 py-1 rounded-full ml-2">
                                                        <?php echo $isIntern ? 'Anak Magang' : 'Karyawan'; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($k['divisi']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($k['jabatan']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($k['email'] ?? '-'); ?><br>
                                                <?php echo htmlspecialchars($k['no_telp'] ?? '-'); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <div class="flex space-x-3">
                                                <button onclick="editKaryawan(<?php echo htmlspecialchars(json_encode($k)); ?>)" 
                                                    class="text-indigo-600 hover:text-indigo-900 transition-colors duration-150">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="deleteKaryawan(<?php echo $k['id_karyawan']; ?>)" 
                                                    class="text-red-600 hover:text-red-900 transition-colors duration-150">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Add Karyawan Modal -->
            <div id="addKaryawanModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
                <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-medium">Tambah Karyawan Baru</h3>
                        <button onclick="document.getElementById('addKaryawanModal').classList.add('hidden')" class="text-gray-500 hover:text-gray-700">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="add_karyawan">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">ID Card</label>
                                <input type="text" name="id_card" required class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Nama Lengkap</label>
                                <input type="text" name="nama" required class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Divisi</label>
                                <input type="text" name="divisi" required class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Jabatan</label>
                                <input type="text" name="jabatan" required class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Email</label>
                                <input type="email" name="email" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">No. Telepon</label>
                                <input type="tel" name="no_telp" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Alamat</label>
                                <textarea name="alamat" rows="3" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"></textarea>
                            </div>
                        </div>
                        <div class="mt-6 flex justify-end space-x-3">
                            <button type="button" onclick="document.getElementById('addKaryawanModal').classList.add('hidden')" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300 transition duration-150">
                                Batal
                            </button>
                            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 transition duration-150">
                                Simpan
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Recent Users and Activity -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Recent Users Table -->
                <div class="lg:col-span-2 bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-4 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-800">Recent Users</h2>
                            <a href="users.php" class="text-sm text-primary hover:underline">View All</a>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach (array_slice($users, 0, 5) as $user): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10">
                                                    <img src="https://placehold.co/100x100" alt="User Avatar" class="h-10 w-10 rounded-full">
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['username']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $user['role'] === 'admin' ? 'badge-admin' : 'badge-user'; ?>">
                                                <?php echo htmlspecialchars(ucfirst($user['role'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <div class="flex space-x-2">
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <input type="hidden" name="role" value="<?php echo $user['role'] === 'admin' ? 'user' : 'admin'; ?>">
                                                    <button type="submit" name="update_role" class="text-blue-500 hover:text-blue-700">
                                                        <?php if ($user['role'] === 'admin'): ?>
                                                            <i class="fas fa-user" title="Make User"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-user-shield" title="Make Admin"></i>
                                                        <?php endif; ?>
                                                    </button>
                                                </form>
                                                <a href="#" class="text-red-500 hover:text-red-700" onclick="deleteUser(<?php echo $user['id']; ?>, this); return false;">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <!-- Recent Activity dihapus -->
            </div>
        </main>
    </div>

    <script>
        // Employee type filtering
        document.addEventListener('DOMContentLoaded', function() {
            const filterButtons = document.querySelectorAll('.filter-btn');
            const rows = document.querySelectorAll('tbody tr');
            
            function updateCounts() {
                const total = rows.length;
                let internCount = 0;
                let karyawanCount = 0;
                
                rows.forEach(row => {
                    if (row.querySelector('.text-red-800')) {
                        internCount++;
                    } else {
                        karyawanCount++;
                    }
                });
                
                document.getElementById('total-count').textContent = total;
                document.getElementById('karyawan-count').textContent = karyawanCount;
                document.getElementById('intern-count').textContent = internCount;
            }
            
            filterButtons.forEach(button => {
                button.addEventListener('click', () => {
                    // Remove active class from all buttons
                    filterButtons.forEach(btn => btn.classList.remove('active'));
                    button.classList.add('active');
                    
                    const filter = button.dataset.filter;
                    
                    rows.forEach(row => {
                        if (filter === 'all') {
                            row.style.display = '';
                        } else if (filter === 'intern') {
                            if (row.querySelector('.text-red-800')) {
                                row.style.display = '';
                            } else {
                                row.style.display = 'none';
                            }
                        } else if (filter === 'karyawan') {
                            if (row.querySelector('.text-blue-800')) {
                                row.style.display = '';
                            } else {
                                row.style.display = 'none';
                            }
                        }
                    });
                });
            });
            
            // Initial count update
            updateCounts();
        });

        // Toggle sidebar
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('sidebar-collapsed');
            document.querySelector('.main-content').classList.toggle('main-content-expanded');
        });

        // Toggle mobile sidebar
        document.getElementById('sidebarToggleMobile').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Profile dropdown
        document.getElementById('profileDropdownBtn').addEventListener('click', function() {
            document.getElementById('profileDropdown').classList.toggle('hidden');
        });

        // Close dropdown when clicking outside
        window.addEventListener('click', function(e) {
            if (!e.target.matches('#profileDropdownBtn') && !e.target.closest('#profileDropdownBtn')) {
                var dropdown = document.getElementById('profileDropdown');
                if (!dropdown.classList.contains('hidden')) {
                    dropdown.classList.add('hidden');
                }
            }
        });

        function deleteUser(userId, el) {
            if (confirm('Yakin ingin menghapus user ini?')) {
                fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'delete_user_id=' + encodeURIComponent(userId)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Hapus baris user dari tabel
                            let row = el.closest('tr');
                            row.parentNode.removeChild(row);
                        } else {
                            alert(data.message || 'Gagal menghapus user.');
                        }
                    })
                    .catch(() => alert('Gagal menghapus user.'));
            }
        }

        function deleteKaryawan(id) {
            if (confirm('Yakin ingin menghapus karyawan ini?')) {
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'action=delete_karyawan&id_karyawan=' + encodeURIComponent(id)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'Gagal menghapus karyawan.');
                    }
                })
                .catch(() => alert('Gagal menghapus karyawan.'));
            }
        }

        function editKaryawan(karyawan) {
            // Populate form fields
            const modal = document.getElementById('addKaryawanModal');
            const form = modal.querySelector('form');
            form.id_card.value = karyawan.id_card;
            form.nama.value = karyawan.nama;
            form.divisi.value = karyawan.divisi;
            form.jabatan.value = karyawan.jabatan;
            form.email.value = karyawan.email;
            form.no_telp.value = karyawan.no_telp;
            form.alamat.value = karyawan.alamat;
            
            // Change form action to edit
            form.action.value = 'edit_karyawan';
            form.insertAdjacentHTML('beforeend', `<input type="hidden" name="id_karyawan" value="${karyawan.id_karyawan}">`);
            
            // Show modal
            modal.classList.remove('hidden');
        }

        // Fungsi untuk auto-increment ID Card
        document.addEventListener('DOMContentLoaded', function() {
            const quickAddForm = document.getElementById('quickAddForm');
            const idCardInput = quickAddForm.querySelector('input[name="id_card"]');
            
            // Set ID Card otomatis saat form di-reset
            function setAutoId() {
                const now = new Date();
                const year = now.getFullYear().toString().substr(-2);
                const month = (now.getMonth() + 1).toString().padStart(2, '0');
                const randomNum = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
                idCardInput.value = `ID${year}${month}-${randomNum}`;
            }

            quickAddForm.addEventListener('reset', function(e) {
                setTimeout(setAutoId, 0);
            });

            // Set ID Card otomatis saat halaman dimuat
            setAutoId();
        });
    </script>
</body>

</html>