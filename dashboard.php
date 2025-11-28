<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Set default values if session variables are not set
$username = $_SESSION['username'] ?? $_SESSION['email'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
$email = $_SESSION['email'] ?? '';

// Ambil total user dari database
$totalUsers = 0;
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM users");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $totalUsers = $row['total'];
}

// Ambil total kegiatan dari database
$totalKegiatan = 0;
$resultKegiatan = mysqli_query($conn, "SELECT COUNT(*) as total FROM jadwal");
if ($resultKegiatan) {
    $rowKegiatan = mysqli_fetch_assoc($resultKegiatan);
    $totalKegiatan = $rowKegiatan['total'];
}

// Get inventory data from peminjaman_barang_db
$invDbName = 'peminjaman_barang_db';
$inventoryError = '';
$inventoryItems = [];

// Connect to inventory database
$invConn = mysqli_connect('localhost', 'root', '', $invDbName);
if (!$invConn) {
    $inventoryError = 'Gagal koneksi ke database inventory: ' . mysqli_connect_error();
} else {
    // Get items that are either out of stock (habis/hilang) or currently borrowed
    $query = "SELECT 
        b.*, 
        COALESCE(p.borrowed_count, 0) as borrowed_count
    FROM barang b 
    LEFT JOIN (
        SELECT id_barang, COUNT(*) as borrowed_count 
        FROM peminjaman 
        WHERE status = 'dipinjam' 
        GROUP BY id_barang
    ) p ON b.id_barang = p.id_barang
    WHERE 
        b.flag_status IN ('habis', 'hilang')
        OR b.jumlah_tersedia = 0
        OR p.borrowed_count > 0
    ORDER BY 
        CASE 
            WHEN b.flag_status IN ('habis', 'hilang') THEN 1
            WHEN b.jumlah_tersedia = 0 THEN 2
            ELSE 3
        END,
        p.borrowed_count DESC";
    
    $result = mysqli_query($invConn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $inventoryItems[] = $row;
        }
    } else {
        $inventoryError = 'Gagal mengambil data inventory: ' . mysqli_error($invConn);
    }
}

// No attendance related code needed
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Modern Auth System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #f97316;
            --dark: #1e293b;
            --light: #f8fafc;
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: #f1f5f9;
            color: var(--dark);
        }
        
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: 250px;
            background: white;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            padding: 20px 0;
            transition: all 0.3s;
            z-index: 100;
        }
        
        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-header img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
            object-fit: cover;
        }
        
        .sidebar-header h3 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .sidebar-header p {
            font-size: 13px;
            color: #64748b;
        }
        
        .sidebar-menu {
            padding: 20px 0;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #64748b;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .sidebar-menu a i {
            margin-right: 10px;
            width: 20px;
        }
        
        .sidebar-menu a:hover, 
        .sidebar-menu a.active {
            color: var(--primary);
            background: rgba(99, 102, 241, 0.1);
        }
        
        .sidebar-menu a:hover::before, 
        .sidebar-menu a.active::before {
            content: '';
            position: absolute;
            left: 0;
            width: 3px;
            height: 30px;
            background: var(--primary);
        }
        
        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            margin-bottom: 20px;
        }
        
        .header h2 {
            font-size: 24px;
            font-weight: 600;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
        }
        
        .user-profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
            object-fit: cover;
        }
        
        .user-profile .dropdown {
            position: relative;
        }
        
        .user-profile .dropdown-toggle {
            display: flex;
            align-items: center;
            cursor: pointer;
        }
        
        .user-profile .dropdown-menu {
            position: absolute;
            right: 0;
            top: 50px;
            background: white;
            width: 200px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            padding: 10px 0;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
            z-index: 100;
        }
        
        .user-profile .dropdown-menu.show {
            opacity: 1;
            visibility: visible;
            top: 45px;
        }
        
        .user-profile .dropdown-menu a {
            display: block;
            padding: 8px 15px;
            color: #64748b;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .user-profile .dropdown-menu a:hover {
            color: var(--primary);
            background: rgba(99, 102, 241, 0.1);
        }
        
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
        }
        
        .card-icon.blue {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
        }
        
        .card-icon.green {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .card-icon.orange {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .card-icon.red {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error);
        }
        
        .card h3 {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 5px;
        }
        
        .card h2 {
            font-size: 24px;
            font-weight: 600;
        }
        
        .card-footer {
            display: flex;
            align-items: center;
            margin-top: 10px;
            font-size: 13px;
        }
        
        .card-footer i {
            margin-right: 5px;
        }
        
        .card-footer.positive {
            color: var(--success);
        }
        
        .card-footer.negative {
            color: var(--error);
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        @media (min-width: 768px) {
            .content-grid {
                grid-template-columns: 2fr 1fr;
            }
        }
        
        .profile-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .profile-header {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .profile-header img {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            margin-bottom: 10px;
            border: 3px solid rgba(99, 102, 241, 0.2);
            object-fit: cover;
        }
        
        .profile-header h2 {
            font-size: 20px;
            margin-bottom: 5px;
        }
        
        .profile-header p {
            color: #64748b;
            font-size: 14px;
        }
        
        .profile-badge {
            display: inline-block;
            padding: 3px 10px;
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .profile-details {
            margin-top: 20px;
        }
        
        .profile-detail {
            display: flex;
            margin-bottom: 10px;
        }
        
        .profile-detail i {
            width: 20px;
            margin-right: 10px;
            color: var(--primary);
        }
        
        .admin-panel {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-top: 20px;
        }
        
        .admin-panel h3 {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        .admin-feature {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .admin-feature i {
            width: 30px;
            height: 30px;
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
        }
        
        .admin-feature:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
        <div style="display: flex; align-items: center;">
            <img src="<?php echo htmlspecialchars($_SESSION['avatar'] ?? 'https://placehold.co/100x100'); ?>" alt="User Avatar">
                <div>
                    <h3><?php echo htmlspecialchars($username); ?></h3>
                    <p><?php echo htmlspecialchars($role); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Sidebar Menu -->
        <div class="sidebar-menu">
            <a href="#" class="active">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="account.php">
                <i class="fas fa-user-circle"></i>
                <span>Informasi Akun</span>
            </a>
            <?php if (isAdmin()): ?>
                <a href="admin_panel.php">
                    <i class="fas fa-users-cog"></i>
                    <span>Admin Panel</span>
                </a>
                <a href="jadual_kegiatan.php">
                    <i class="fas fa-chart-line"></i>
                    <span>Jadual</span>
                </a>
                <a href="peminjaman.php">
                    <i class="fas fa-box-open"></i>
                    <span>Peminjaman Barang</span>
                </a>
                <a href="add_magang.php">
                    <i class="fas fa-user-plus"></i>
                    <span>Tambah Anak Magang</span>
                </a>
            <?php endif; ?>
            <a href="absen_tanpa_rfid.php">
                <i class="fas fa-user-check"></i>
                <span>Absensi (Tanpa RFID)</span>
            </a>
            <a href="logout.php">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <h2>Dashboard</h2>
            
            <div class="user-profile">
                <img src="<?php echo htmlspecialchars($_SESSION['avatar'] ?? 'https://placehold.co/100x100'); ?>" alt="User Profile Picture">
                <div class="dropdown">
                    <div class="dropdown-toggle" onclick="toggleDropdown()">
                        <span><?php echo htmlspecialchars($username); ?></span>
                        <i class="fas fa-chevron-down" style="margin-left: 5px;"></i>
                    </div>
                    <!-- Dropdown Menu -->
                    <div class="dropdown-menu" id="dropdownMenu">
                        <a href="jadual_kegiatan.php"><i class="fas fa-chart-line"></i><sp>jadual</sp></a>
                        <a href="absen_tanpa_rfid.php"><i class="fas fa-user-check"></i> Absensi (Tanpa RFID)</a>
                        <?php if (isAdmin()): ?>
                            <a href="peminjaman.php"><i class="fas fa-box-open"></i> Peminjaman Barang</a>
                        <?php endif; ?>
                        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Cards -->
        <div class="cards">
            <div class="card">
                <div class="card-icon blue">
                    <i class="fas fa-users"></i>
                </div>
                <h3>Total Users</h3>
                <h2><?php echo number_format($totalUsers); ?></h2>
                <div class="card-footer positive">
                    <i class="fas fa-arrow-up"></i> 12% from last month
                </div>
            </div>
            
            <div class="card">
                <div class="card-icon orange">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <h3>Kegiatan</h3>
                <h2><?php echo number_format($totalKegiatan); ?></h2>
                <div class="card-footer positive">
                    <i class="fas fa-arrow-up"></i> Data real-time
                </div>
            </div>
        </div>
        
        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Main Content -->
            <div class="card">
                <h3>Menu Utama</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px;">
                    <a href="account.php" style="text-decoration: none;">
                        <div class="admin-feature" style="background: rgba(99, 102, 241, 0.1); padding: 20px; border-radius: 8px;">
                            <i class="fas fa-user-circle" style="font-size: 24px; color: var(--primary); margin-bottom: 10px;"></i>
                            <div>
                                <h4 style="color: var(--dark);">Informasi Akun</h4>
                                <p style="color: #64748b;">Lihat dan edit profil Anda</p>
                            </div>
                        </div>
                    </a>
                    
                    <?php if (isAdmin()): ?>
                    <a href="inventory.php" style="text-decoration: none;">
                        <div class="admin-feature" style="background: rgba(99, 102, 241, 0.1); padding: 20px; border-radius: 8px;">
                            <i class="fas fa-box" style="font-size: 24px; color: var(--primary); margin-bottom: 10px;"></i>
                            <div>
                                <h4 style="color: var(--dark);">Inventory</h4>
                                <p style="color: #64748b;">Kelola barang dan peminjaman</p>
                            </div>
                        </div>
                    </a>
                    <?php endif; ?>
                    
                    <a href="jadual_kegiatan.php" style="text-decoration: none;">
                        <div class="admin-feature" style="background: rgba(99, 102, 241, 0.1); padding: 20px; border-radius: 8px;">
                            <i class="fas fa-calendar-alt" style="font-size: 24px; color: var(--primary); margin-bottom: 10px;"></i>
                            <div>
                                <h4 style="color: var(--dark);">Jadual Kegiatan</h4>
                                <p style="color: #64748b;">Lihat dan kelola jadual</p>
                            </div>
                        </div>
                    </a>
                    <a href="absen_tanpa_rfid.php" style="text-decoration: none;">
                        <div class="admin-feature" style="background: linear-gradient(90deg,#10b9811a,#4f46e51a); padding: 20px; border-radius: 8px;">
                            <i class="fas fa-user-check" style="font-size: 24px; color: #10b981; margin-bottom: 10px;"></i>
                            <div>
                                <h4 style="color: var(--dark);">Absensi (Tanpa RFID)</h4>
                                <p style="color: #64748b;">Form cepat bagi anak magang untuk absen menggunakan NIS atau nama</p>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
            
            <!-- Side Content -->
            <div>
                <!-- Inventory Status Section -->
                <div class="card" style="margin-bottom:20px;">
                    <h3>Status Barang (Habis/Dipinjam)</h3>
                    <?php if ($inventoryError): ?>
                        <p style="color:#ef4444"><?php echo htmlspecialchars($inventoryError); ?></p>
                    <?php endif; ?>

                    <?php if (empty($inventoryItems)): ?>
                        <p>Semua barang tersedia dan dalam stok.</p>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table style="width:100%; border-collapse:collapse;">
                                <thead>
                                    <tr>
                                        <th style="padding:8px;border-bottom:1px solid #e2e8f0; text-align:left;">Nama Barang</th>
                                        <th style="padding:8px;border-bottom:1px solid #e2e8f0; text-align:left;">Kode</th>
                                        <th style="padding:8px;border-bottom:1px solid #e2e8f0; text-align:left;">Status</th>
                                        <th style="padding:8px;border-bottom:1px solid #e2e8f0; text-align:left;">Tersedia</th>
                                        <th style="padding:8px;border-bottom:1px solid #e2e8f0; text-align:left;">Dipinjam</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventoryItems as $item): ?>
                                        <tr>
                                            <td style="padding:8px;border-bottom:1px solid #e2e8f0;">
                                                <?php echo htmlspecialchars($item['nama_barang']); ?>
                                            </td>
                                            <td style="padding:8px;border-bottom:1px solid #e2e8f0;">
                                                <?php echo htmlspecialchars($item['kode_barang'] ?? '-'); ?>
                                            </td>
                                            <td style="padding:8px;border-bottom:1px solid #e2e8f0;">
                                                <?php if ($item['flag_status']): ?>
                                                    <span style="display:inline-block;padding:2px 8px;background:#ef4444;color:white;border-radius:12px;font-size:12px;">
                                                        <?php echo strtoupper(htmlspecialchars($item['flag_status'])); ?>
                                                    </span>
                                                <?php elseif ($item['borrowed_count'] > 0): ?>
                                                    <span style="display:inline-block;padding:2px 8px;background:#f59e0b;color:white;border-radius:12px;font-size:12px;">
                                                        DIPINJAM
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:8px;border-bottom:1px solid #e2e8f0;">
                                                <?php echo htmlspecialchars($item['jumlah_tersedia']); ?>/<?php echo htmlspecialchars($item['jumlah_total']); ?>
                                            </td>
                                            <td style="padding:8px;border-bottom:1px solid #e2e8f0;">
                                                <?php echo htmlspecialchars($item['borrowed_count']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (isAdmin()): ?>
                    <div class="admin-panel">
                        <h3>Admin Tools</h3>

                        <div class="admin-feature">
                            <i class="fas fa-box"></i>
                            <div>
                                <h4>Inventory</h4>
                                <p><a href="inventory.php" style="color:var(--primary);text-decoration:none;">Manage inventory &amp; borrowings</a></p>
                            </div>
                        </div>

                        <div class="admin-feature">
                            <i class="fas fa-users"></i>
                            <div>
                                <h4>Manage Users</h4>
                                <p>Add, edit or remove system users</p>
                            </div>
                        </div>

                        <div class="admin-feature">
                            <i class="fas fa-shield-alt"></i>
                            <div>
                                <h4>Permissions</h4>
                                <p>Configure user roles and permissions</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="profile-card" style="margin-top: 20px;">
                    <h3>Quick Actions</h3>
                    
                    <div class="admin-feature">
                        <i class="fas fa-user-plus"></i>
                        <div>
                            <h4>Add New User</h4>
                            <p>Create a new system account</p>
                        </div>
                    </div>
                    
                    <div class="admin-feature">
                        <i class="fas fa-file-alt"></i>
                        <div>
                            <h4>Generate Report</h4>
                            <p>Create a system activity report</p>
                        </div>
                    </div>
                    
                    <div class="admin-feature">
                        <i class="fas fa-bell"></i>
                        <div>
                            <h4>Notifications</h4>
                            <p>View and manage notifications</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function toggleDropdown() {
            document.getElementById('dropdownMenu').classList.toggle('show');
        }
        
        // Close dropdown when clicking outside
        window.onclick = function(event) {
            if (!event.target.matches('.dropdown-toggle') && !event.target.closest('.dropdown-toggle')) {
                var dropdowns = document.getElementsByClassName("dropdown-menu");
                for (var i = 0; i < dropdowns.length; i++) {
                    var openDropdown = dropdowns[i];
                    if (openDropdown.classList.contains('show')) {
                        openDropdown.classList.remove('show');
                    }
                }
            }
        }
    </script>
</body>
</html>
