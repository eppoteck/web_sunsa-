<?php
// absen_tanpa_rfid.php
// Halaman absensi untuk anak magang tanpa RFID: cukup masukkan NIS (id_card/id_karyawan) atau nama

require_once 'includes/config.php'; // provides $conn (cihuy DB)
require_once 'includes/functions.php';

$invDb = 'Peminjaman_barang_db';
$absDb = 'absensi_db';

// Try connect to peminjaman DB (where karyawan often lives)
// Safe connect helper: connect to MySQL server then select DB only if it exists
function tryConnectDbByName($dbName) {
    $serverConn = @mysqli_connect('localhost', DB_USER, DB_PASS);
    if (!$serverConn) return null;
    // Check INFORMATION_SCHEMA to see if the database exists first
    $esc = mysqli_real_escape_string($serverConn, $dbName);
    $checkSql = "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '" . $esc . "' LIMIT 1";
    $check = @mysqli_query($serverConn, $checkSql);
    if ($check && mysqli_num_rows($check) > 0) {
        // now safe to select the database
        if (@mysqli_select_db($serverConn, $dbName)) {
            return $serverConn;
        }
    }
    mysqli_close($serverConn);
    return null;
}

$invConn = tryConnectDbByName($invDb);

// Try connect to absensi DB (for storing absensi) using safe helper
$absConn = tryConnectDbByName($absDb);
if (!$absConn) {
    $absMissing = true; // used to inform user that absensi_db is not available
} else {
    $absMissing = false;
}

// Fallback connection for karyawan lookup: use $conn from includes/config.php (DB_NAME cihuy)
$defaultConn = $conn;

// Helper: search karyawan by input (id_card, id_karyawan, or nama LIKE)
function searchKaryawan($input, $conns) {
    $qresults = [];
    $v = trim($input);
    if ($v === '') return $qresults;

    foreach ($conns as $c) {
        if (!$c) continue;
        // Skip this connection if it doesn't appear to have the karyawan table
        try {
            $check = @mysqli_query($c, "SHOW TABLES LIKE 'karyawan'");
        } catch (mysqli_sql_exception $e) {
            // connection/database doesn't have required table or cannot be queried — skip
            continue;
        }
        if (! $check || mysqli_num_rows($check) === 0) {
            continue;
        }

        // Safe to query for karyawan
        try {
            $esc = mysqli_real_escape_string($c, $v);
            $num = preg_match('/^\d+$/', $v);
            $conds = [];
            $conds[] = "id_card = '" . $esc . "'";
            if ($num) $conds[] = "id_karyawan = " . intval($v);
            $conds[] = "nama LIKE '%" . $esc . "%'";
            $sql = "SELECT id_karyawan, nama, id_card FROM karyawan WHERE (" . implode(' OR ', $conds) . ") LIMIT 20";
            $res = mysqli_query($c, $sql);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    // add source so we can know where id comes from
                    $row['_db_handle'] = $c;
                    $qresults[] = $row;
                }
            }
        } catch (mysqli_sql_exception $e) {
            // Query failed on this connection (table missing or other DB error) — skip it
            continue;
        }
    }
    return $qresults;
}

// Helper: ensure absensi table exists on a connection
function ensureAbsensiTable($c) {
    if (!$c) return false;
    $create = "CREATE TABLE IF NOT EXISTS `absensi` (
        `id_absensi` INT AUTO_INCREMENT PRIMARY KEY,
        `id_karyawan` INT NOT NULL,
        `tanggal` DATE NOT NULL,
        `jam_masuk` TIME DEFAULT NULL,
        `jam_keluar` TIME DEFAULT NULL,
        UNIQUE KEY unique_absen (id_karyawan, tanggal)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try {
        return mysqli_query($c, $create);
    } catch (mysqli_sql_exception $e) {
        return false;
    }
}

// Helper: ensure izin table exists on a connection
function ensureIzinTable($c) {
    if (!$c) return false;
    $create = "CREATE TABLE IF NOT EXISTS `izin` (
        `id_izin` INT AUTO_INCREMENT PRIMARY KEY,
        `id_karyawan` INT NOT NULL,
        `jenis` ENUM('izin_keluar','permintaan_pulang','langsung_pulang','terlambat') NOT NULL,
        `alasan` TEXT DEFAULT NULL,
        `status` ENUM('pending','approved','rejected','failed') NOT NULL DEFAULT 'pending',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try {
        return mysqli_query($c, $create);
    } catch (mysqli_sql_exception $e) {
        return false;
    }
}

// Helper: record attendance (masuk/keluar) on chosen connection
function recordAttendance($c, $id_karyawan, $clientTime = null) {
    if (!$c) return ['success'=>false,'message'=>'Tidak ada koneksi database untuk mencatat absensi'];
    ensureAbsensiTable($c);
    
    // Parse client time if provided, otherwise use server time
    if ($clientTime) {
        try {
            $timeData = json_decode($clientTime, true);
            $today = $timeData['date'];
            $nowTime = $timeData['time'];
        } catch (Exception $e) {
            $today = date('Y-m-d');
            $nowTime = date('H:i:s');
        }
    } else {
        $today = date('Y-m-d');
        $nowTime = date('H:i:s');
    }

    $id = intval($id_karyawan);
    $sql = "SELECT * FROM absensi WHERE id_karyawan = " . $id . " AND tanggal = '" . $today . "' LIMIT 1";
    try {
        $res = mysqli_query($c, $sql);
    } catch (mysqli_sql_exception $e) {
        return ['success'=>false,'message'=>'Gagal membaca data absensi: ' . $e->getMessage()];
    }
    if ($res && mysqli_num_rows($res) > 0) {
        $row = mysqli_fetch_assoc($res);
        if (empty($row['jam_masuk'])) {
            // edge case: record exists but no jam_masuk
            $upd = "UPDATE absensi SET jam_masuk = '" . $nowTime . "' WHERE id_absensi = " . intval($row['id_absensi']);
            try {
                if (mysqli_query($c, $upd)) return ['success'=>true,'message'=>'Jam masuk dicatat: ' . $nowTime];
            } catch (mysqli_sql_exception $e) {
                return ['success'=>false,'message'=>'Gagal mencatat jam masuk: ' . $e->getMessage()];
            }
            return ['success'=>false,'message'=>'Gagal mencatat jam masuk.'];
        }
        if (empty($row['jam_keluar'])) {
            $upd = "UPDATE absensi SET jam_keluar = '" . $nowTime . "' WHERE id_absensi = " . intval($row['id_absensi']);
            try {
                if (mysqli_query($c, $upd)) return ['success'=>true,'message'=>'Jam keluar dicatat: ' . $nowTime];
            } catch (mysqli_sql_exception $e) {
                return ['success'=>false,'message'=>'Gagal mencatat jam keluar: ' . $e->getMessage()];
            }
            return ['success'=>false,'message'=>'Gagal mencatat jam keluar.'];
        }
        return ['success'=>false,'message'=>'Absensi untuk hari ini sudah lengkap (masuk & keluar tercatat).'];
    } else {
        // insert jam_masuk
        $ins = "INSERT INTO absensi (id_karyawan, tanggal, jam_masuk) VALUES (" . $id . ", '" . $today . "', '" . $nowTime . "')";
        try {
            if (mysqli_query($c, $ins)) return ['success'=>true,'message'=>'Jam masuk dicatat: ' . $nowTime];
        } catch (mysqli_sql_exception $e) {
            return ['success'=>false,'message'=>'Gagal mencatat absensi: ' . $e->getMessage()];
        }
        return ['success'=>false,'message'=>'Gagal mencatat absensi.'];
    }
}

// Determine which DB connection to use for karyawan lookup and absensi storage
// Prefer to lookup karyawan only in peminjaman_barang_db for simplicity
$warningMsg = null;
$searchConns = [];
if ($invConn) {
    $searchConns[] = $invConn;
} else {
    // fallback: use default DB but warn the user that peminjaman DB is unavailable
    if ($defaultConn) {
        $searchConns[] = $defaultConn;
        $warningMsg = 'Peringatan: koneksi ke database <code>peminjaman_barang_db</code> gagal. Pencarian karyawan menggunakan database utama.';
    }
}
// absensi storage still prefers absensi_db when available; keep $absConn for target selection
// (we don't add $absConn to search list)

// Fetch all interns and their attendance for today
function getAllInternsAttendance($searchConns, $absConn) {
    $interns = [];
    $today = date('Y-m-d');
    
    // Get all interns from peminjaman_barang_db first
    foreach ($searchConns as $conn) {
        if (!$conn) continue;
        try {
            $check = @mysqli_query($conn, "SHOW TABLES LIKE 'karyawan'");
            if (!$check || mysqli_num_rows($check) === 0) continue;
            
            $sql = "SELECT id_karyawan, nama, id_card, divisi, jabatan FROM karyawan ORDER BY nama";
            $result = mysqli_query($conn, $sql);
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $row['attendance'] = null;
                    $row['izin'] = null;
                    $interns[] = $row;
                }
            }
        } catch (mysqli_sql_exception $e) {
            continue;
        }
    }
    
    // Get attendance data from absensi_db if available, otherwise try each conn
    $targetConn = $absConn;
    if (!$targetConn && !empty($searchConns)) {
        $targetConn = $searchConns[0];
    }
    
    if ($targetConn) {
        foreach ($interns as &$intern) {
            // Get attendance
            $sql = "SELECT jam_masuk, jam_keluar FROM absensi 
                   WHERE id_karyawan = " . intval($intern['id_karyawan']) . " 
                   AND tanggal = '" . $today . "' LIMIT 1";
            try {
                $result = mysqli_query($targetConn, $sql);
                if ($result && mysqli_num_rows($result) > 0) {
                    $intern['attendance'] = mysqli_fetch_assoc($result);
                }
            } catch (mysqli_sql_exception $e) {
                // Skip if query fails
            }
            
            // Get izin if exists
                $sql = "SELECT jenis, status, alasan, created_at FROM izin 
                   WHERE id_karyawan = " . intval($intern['id_karyawan']) . " 
                   AND DATE(created_at) = '" . $today . "'
                   ORDER BY created_at DESC LIMIT 1";
            try {
                $result = mysqli_query($targetConn, $sql);
                if ($result && mysqli_num_rows($result) > 0) {
                    $intern['izin'] = mysqli_fetch_assoc($result);
                }
            } catch (mysqli_sql_exception $e) {
                // Skip if query fails
            }
        }
    }
    
    return $interns;
}

// Handle POST
$message = null;
$results = [];
$attendanceLog = [];
$allInterns = getAllInternsAttendance($searchConns, $absConn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = trim($_POST['nis_or_name'] ?? '');
    $selectedId = intval($_POST['selected_id'] ?? 0);
    $clientTime = $_POST['client_time'] ?? null;
    $action = $_POST['action'] ?? '';

       if ($action === 'submit_izin') {
           // Determine target DB for izin (prefer absensi_db, otherwise inventory DB, otherwise default)
           $targetConn = $absConn ?: ($invConn ?: $defaultConn);

           $karyawanId = intval($_POST['id_karyawan']);
           $jenisIzin = isset($_POST['jenis_izin']) ? mysqli_real_escape_string($targetConn, $_POST['jenis_izin']) : '';
           $alasan = isset($_POST['alasan']) ? mysqli_real_escape_string($targetConn, $_POST['alasan']) : '';

           // Parse client time into full datetime if provided
           $timeData = json_decode($clientTime, true);
           $datePart = $timeData['date'] ?? date('Y-m-d');
           $timePart = $timeData['time'] ?? date('H:i:s');
           $created_at = $datePart . ' ' . $timePart;

           // Ensure izin table exists on the chosen connection
           if (!ensureIzinTable($targetConn)) {
               $message = ['type'=>'error','text'=>'Tabel izin tidak tersedia dan gagal dibuat pada database target.'];
           } else {
               // Insert izin record. Set status to 'pending' by default.
               $sql = "INSERT INTO izin (id_karyawan, jenis, alasan, status, created_at) 
                       VALUES ($karyawanId, '$jenisIzin', '$alasan', 'pending', '$created_at')";

               if (mysqli_query($targetConn, $sql)) {
                   $message = ['type'=>'success','text'=>'Izin berhasil dicatat'];
               } else {
                   $message = ['type'=>'error','text'=>'Gagal mencatat izin: ' . mysqli_error($targetConn)];
               }
           }

           // After handling izin, reload intern list to reflect any changes
           $allInterns = getAllInternsAttendance($searchConns, $absConn);
       }

    // If user clicked to select from multiple matches
    if ($selectedId > 0) {
        // find which DB the karyawan exists in by searching across conns
        $found = null;
        foreach ($searchConns as $sc) {
            $q = "SELECT id_karyawan, nama, id_card FROM karyawan WHERE id_karyawan = " . intval($selectedId) . " LIMIT 1";
            $r = @mysqli_query($sc, $q);
            if ($r && mysqli_num_rows($r)>0) { $found = mysqli_fetch_assoc($r); $found['_db_handle']=$sc; break; }
        }
        if (!$found) {
            $message = ['type'=>'error','text'=>'Karyawan tidak ditemukan'];
        } else {
            // choose absensi DB if available, otherwise use the same DB where karyawan found, otherwise default
            $targetConn = $absConn ?: ($found['_db_handle'] ?: $defaultConn);
            $res = recordAttendance($targetConn, $found['id_karyawan'], $clientTime);
            $message = ['type'=> $res['success'] ? 'success' : 'error', 'text'=> $res['message']];
            // fetch recent attendance for display
            $q2 = "SELECT tanggal, jam_masuk, jam_keluar FROM absensi WHERE id_karyawan = " . intval($found['id_karyawan']) . " ORDER BY tanggal DESC LIMIT 7";
            $r2 = @mysqli_query($targetConn, $q2);
            if ($r2) { while ($rr = mysqli_fetch_assoc($r2)) $attendanceLog[] = $rr; }
        }
    } else {
        // Search by provided input
        if ($input === '') {
            $message = ['type'=>'error','text'=>'Masukkan NIS atau nama terlebih dahulu.'];
        } else {
            $results = searchKaryawan($input, $searchConns);
            if (count($results) === 1) {
                $found = $results[0];
                // choose absensi DB if available
                $targetConn = $absConn ?: ($found['_db_handle'] ?: $defaultConn);
                $res = recordAttendance($targetConn, $found['id_karyawan']);
                $message = ['type'=> $res['success'] ? 'success' : 'error', 'text'=> $res['message']];
                // fetch recent attendance
                $q2 = "SELECT tanggal, jam_masuk, jam_keluar FROM absensi WHERE id_karyawan = " . intval($found['id_karyawan']) . " ORDER BY tanggal DESC LIMIT 7";
                $r2 = @mysqli_query($targetConn, $q2);
                if ($r2) { while ($rr = mysqli_fetch_assoc($r2)) $attendanceLog[] = $rr; }
            } elseif (count($results) > 1) {
                $message = ['type'=>'info','text'=>'Beberapa hasil ditemukan, pilih satu.'];
            } else {
                $message = ['type'=>'error','text'=>'Karyawan tidak ditemukan.'];
            }
        }
    }
}

// Simple HTML UI below
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Absensi Anak Magang</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body{font-family:Segoe UI, Tahoma, sans-serif;background:#f3f4f6;color:#111;margin:0;padding:24px}
        .card{background:white;border-radius:10px;padding:20px;max-width:1200px;margin:12px auto;box-shadow:0 6px 20px rgba(2,6,23,0.06)}
        .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
        button.btn{background:#4f46e5;color:white;padding:10px 14px;border-radius:8px;border:none;cursor:pointer}
        button.btn:hover{background:#4338ca}
        button.btn:disabled{background:#9ca3af;cursor:not-allowed}
        .back-btn{text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border-radius:8px;background:#eef2ff;color:#1e3a8a}
        
        .msg{padding:12px;border-radius:8px;margin-bottom:12px}
        .msg.success{background:#dcfce7;color:#065f46}
        .msg.error{background:#fee2e2;color:#7f1d1d}
        .msg.info{background:#eff6ff;color:#1e3a8a}
        
        /* Attendance table styles */
        .attendance-table{width:100%;border-collapse:collapse;background:white;border-radius:10px;overflow:hidden;font-size:14px}
        .attendance-table th{background:#f8fafc;font-weight:600;color:#475569;position:sticky;top:0;padding:12px}
        .attendance-table td{padding:12px;border-bottom:1px solid #eef2f6;vertical-align:middle}
        .status-badge{display:inline-block;padding:4px 8px;border-radius:4px;font-size:12px}
        .status-present{background:#dcfce7;color:#065f46}
        .status-absent{background:#fee2e2;color:#7f1d1d}
        .status-late{background:#fef9c3;color:#854d0e}
        .status-izin{background:#eff6ff;color:#1e3a8a}
        .attendance-filters{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
        .filter-btn{padding:6px 12px;border-radius:6px;border:1px solid #e5e7eb;background:white;cursor:pointer;font-size:13px}
        .filter-btn:hover{background:#f8fafc}
        .filter-btn.active{background:#4f46e5;color:white;border-color:#4f46e5}
        .divisi-badge{font-size:12px;color:#64748b;background:#f1f5f9;padding:2px 6px;border-radius:4px}
        .time-cell{font-family:monospace;font-size:13px;color:#475569}
        .table-wrapper{margin-top:16px;border:1px solid #eef2f6;border-radius:10px;overflow:hidden}
        .date-display{color:#64748b;font-size:14px;margin:0 0 16px 0}
        .header-title{margin:0;font-size:24px;color:#1e293b}
    /* Modal styles */
        .fixed{position:fixed}
        .inset-0{top:0;right:0;bottom:0;left:0}
        .bg-opacity-50{--tw-bg-opacity:0.5}
    .modal-overlay{background:rgba(0,0,0,0.45);backdrop-filter:blur(2px)}
     /* When modal is open, hide other card children so only modal is visible.
         Exclude both izin modals so either one can be shown. */
     .card.modal-hide-others > *:not(#izinModal):not(#izinViewModal){display:none}
     #izinModal, #izinViewModal{z-index:1100}
        .hidden{display:none}
        .flex{display:flex}
        .items-center{align-items:center}
        .justify-center{justify-content:center}
        .gap-2{gap:0.5rem}
        .p-6{padding:1.5rem}
        .mb-4{margin-bottom:1rem}
        .mb-2{margin-bottom:0.5rem}
        .w-full{width:100%}
        .max-w-md{max-width:28rem}
        .mx-4{margin-left:1rem;margin-right:1rem}
        .rounded-lg{border-radius:0.5rem}
        .shadow-xl{box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 10px 10px -5px rgba(0,0,0,0.04)}
        .text-gray-400{color:#9ca3af}
        .text-gray-600{color:#4b5563}
        .text-gray-700{color:#374151}
        .text-gray-900{color:#111827}
        .hover\:text-gray-600:hover{color:#4b5563}
        .text-sm{font-size:0.875rem}
        .text-lg{font-size:1.125rem}
        .font-medium{font-weight:500}
        .font-semibold{font-weight:600}
        .bg-gray-100{background-color:#f3f4f6}
        .hover\:bg-gray-200:hover{background-color:#e5e7eb}
        .bg-indigo-600{background-color:#4f46e5}
        .hover\:bg-indigo-700:hover{background-color:#4338ca}
        .focus\:ring-indigo-500:focus{--tw-ring-color:#6366f1}
        .focus\:border-indigo-500:focus{border-color:#6366f1}
        .flex{display:flex}
        .items-center{align-items:center}
        .gap-2{gap:0.5rem}
        .type-badge{font-size:11px;padding:2px 6px;border-radius:12px;display:inline-block}
        .bg-red-100{background-color:#fee2e2}
        .bg-blue-100{background-color:#dbeafe}
        .text-red-800{color:#991b1b}
        .text-blue-800{color:#1e40af}
    /* Izin form table styles (compact & tidy) */
    .modal-content{max-width:420px;width:auto}
    .modal-body{padding:12px}
    .izin-table{width:100%;border-collapse:collapse;margin-top:6px;font-size:13px}
    .izin-table td{padding:6px 6px;vertical-align:top;border-bottom:1px solid #f1f5f9}
    .izin-table td.label{width:34%;font-weight:600;color:#374151;padding-right:8px;font-size:13px}
    .izin-datetime{font-family:monospace;color:#374151;padding:6px;border:1px solid #e6eef7;border-radius:6px;background:#fbfdff;font-size:13px}
    .izin-select, .izin-textarea{font-size:13px}
    .modal-footer{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
    .btn-small{padding:6px 10px;font-size:13px;border-radius:6px}
    .modal-primary-btn{background:#2563eb;color:white;border:none;padding:6px 10px;border-radius:6px}
    .modal-primary-btn:hover{background:#1e40af}
    .modal-cancel-btn{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;padding:6px 10px;border-radius:6px}
    @media (max-width:420px){ .modal-content{max-width:92%} .izin-table td.label{width:40%} }
    </style>
</head>
<body>
    <div class="card">
           <!-- Izin Modal -->
           <div id="izinModal" style="display:none" class="fixed inset-0 modal-overlay hidden flex items-center justify-center" aria-hidden="true">
               <div class="bg-white rounded-lg shadow-xl modal-body modal-content mx-4" style="border:1px solid #e6eef7">
                   <div class="flex justify-between items-center mb-4">
                       <h3 class="text-lg font-semibold text-gray-900">Form Izin</h3>
                       <button type="button" class="izin-close-btn text-gray-400 hover:text-gray-600" aria-label="Tutup">
                           <i class="fas fa-times"></i>
                       </button>
                   </div>
                   <!-- Izin View Modal (read-only) -->
                   <div id="izinViewModal" style="display:none" class="fixed inset-0 modal-overlay hidden flex items-center justify-center" aria-hidden="true">
                       <div class="bg-white rounded-lg shadow-xl modal-body modal-content mx-4" style="border:1px solid #e6eef7">
                           <div class="flex justify-between items-center mb-3">
                               <h3 class="text-lg font-semibold text-gray-900">Detail Izin</h3>
                               <button type="button" class="izinview-close-btn text-gray-400 hover:text-gray-600" aria-label="Tutup">
                                   <i class="fas fa-times"></i>
                               </button>
                           </div>
                           <div style="font-size:14px">
                               <table class="izin-table">
                                   <tr><td class="label">Nama</td><td id="viewIzinNama" class="text-gray-900 font-medium"></td></tr>
                                   <tr><td class="label">ID</td><td id="viewIzinId" class="text-gray-700"></td></tr>
                                   <tr><td class="label">Jenis</td><td id="viewIzinJenis" class="text-gray-700"></td></tr>
                                   <tr><td class="label">Alasan</td><td><textarea id="viewIzinAlasan" rows="3" class="w-full rounded-md border border-gray-300 p-2" readonly></textarea></td></tr>
                                   <tr><td class="label">Tanggal</td><td id="viewIzinCreated" class="text-gray-700"></td></tr>
                               </table>
                               <div class="modal-footer" style="margin-top:8px">
                                   <button type="button" class="izinview-close-btn modal-cancel-btn btn-small">Tutup</button>
                               </div>
                           </div>
                       </div>
                   </div>

                   <form id="izinForm" method="POST">
                       <input type="hidden" name="action" value="submit_izin">
                       <input type="hidden" name="id_karyawan" id="izinKaryawanId">
                       <input type="hidden" name="client_time" class="client-time-input">

                       <table class="izin-table">
                           <tr>
                               <td class="label">Nama Karyawan</td>
                               <td><div id="izinKaryawanNama" class="text-gray-900 font-medium"></div></td>
                           </tr>
                           <tr>
                               <td class="label">ID Karyawan</td>
                               <td><div id="izinKaryawanIdDisplay" class="text-gray-700"></div></td>
                           </tr>
                           <tr>
                               <td class="label">Jenis Izin</td>
                               <td>
                                   <select name="jenis_izin" class="w-full rounded-md border border-gray-300 p-2 focus:ring-indigo-500 focus:border-indigo-500 izin-select" required>
                                       <option value="izin_keluar">Izin Keluar</option>
                                       <option value="permintaan_pulang">Permintaan Pulang</option>
                                       <option value="langsung_pulang">Langsung Pulang</option>
                                       <option value="terlambat">Terlambat</option>
                                   </select>
                               </td>
                           </tr>
                           <tr>
                               <td class="label">Alasan</td>
                               <td>
                                   <textarea name="alasan" rows="2" class="w-full rounded-md border border-gray-300 p-2 focus:ring-indigo-500 focus:border-indigo-500 izin-textarea" required></textarea>
                               </td>
                           </tr>
                           <tr>
                               <td class="label">Tanggal / Waktu</td>
                               <td>
                                   <input type="text" id="izinDatetimeDisplay" class="izin-datetime w-full" readonly aria-readonly="true" />
                               </td>
                           </tr>
                       </table>

                       <div class="modal-footer" style="margin-top:6px">
                           <button type="button" class="izin-close-btn modal-cancel-btn btn-small">
                               Batal
                           </button>
                           <button type="submit" class="modal-primary-btn btn-small">
                               Simpan
                           </button>
                       </div>
                   </form>
               </div>
           </div>
        <div class="header">
            <div>
                <h1 class="header-title">Absensi Anak Magang</h1>
                <p class="date-display"><?php echo date('l, d F Y'); ?></p>
            </div>
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Dashboard
            </a>
        </div>

        <?php if ($message): ?>
            <div class="msg <?php echo htmlspecialchars($message['type']); ?>"><?php echo htmlspecialchars($message['text']); ?></div>
        <?php endif; ?>
        <div class="attendance-filters">
            <div class="flex gap-2 mb-2">
                <button class="filter-btn active" data-filter="all" data-type="status">Semua</button>
                <button class="filter-btn" data-filter="present" data-type="status">Hadir</button>
                <button class="filter-btn" data-filter="absent" data-type="status">Belum Hadir</button>
                <button class="filter-btn" data-filter="late" data-type="status">Terlambat</button>
                <button class="filter-btn" data-filter="izin" data-type="status">Izin</button>
            </div>
            <div class="flex gap-2">
                <button class="filter-btn" data-filter="intern" data-type="employee">Anak Magang</button>
                <button class="filter-btn" data-filter="employee" data-type="employee">Karyawan Tetap</button>
            </div>
        </div>

        <div class="table-wrapper">
                <table class="attendance-table">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>ID</th>
                            <th>Divisi</th>
                            <th>Jam Masuk</th>
                            <th>Jam Keluar</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allInterns as $intern): ?>
                            <?php
                            $status = 'absent';
                            $statusText = 'Belum Hadir';
                            
                            if ($intern['attendance']) {
                                if ($intern['attendance']['jam_masuk']) {
                                    // Check if late (after 08:00)
                                    $masuk = strtotime($intern['attendance']['jam_masuk']);
                                    $batasWaktu = strtotime('08:00:00');
                                    if ($masuk > $batasWaktu) {
                                        $status = 'late';
                                        $statusText = 'Terlambat';
                                    } else {
                                        $status = 'present';
                                        $statusText = 'Hadir';
                                    }
                                }
                            }
                            
                            if ($intern['izin']) {
                                $status = 'izin';
                                $statusText = ucfirst(str_replace('_', ' ', $intern['izin']['jenis']));
                            }
                            ?>
                            <tr class="attendance-row" data-status="<?php echo $status; ?>">
                                <td>
                                    <div class="flex items-center gap-2">
                                        <?php echo htmlspecialchars($intern['nama']); ?>
                                        <?php
                                        $isIntern = stripos($intern['jabatan'], 'magang') !== false || stripos($intern['jabatan'], 'intern') !== false;
                                        $typeClass = $isIntern ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800';
                                        $typeText = $isIntern ? 'Anak Magang' : 'Karyawan';
                                        ?>
                                        <span class="type-badge <?php echo $typeClass; ?>">
                                            <?php echo $typeText; ?>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($intern['id_card']); ?></td>
                                <td>
                                    <?php if ($intern['divisi']): ?>
                                        <span class="divisi-badge"><?php echo htmlspecialchars($intern['divisi']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="time-cell"><?php echo $intern['attendance']['jam_masuk'] ?? '-'; ?></td>
                                <td class="time-cell"><?php echo $intern['attendance']['jam_keluar'] ?? '-'; ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $status; ?>">
                                        <?php echo htmlspecialchars($statusText); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="flex gap-2">
                                        <form method="POST" style="display:inline-flex" class="attendance-form">
                                            <input type="hidden" name="selected_id" value="<?php echo intval($intern['id_karyawan']); ?>">
                                            <input type="hidden" name="client_time" class="client-time-input">
                                            <button class="btn" type="submit" style="font-size:12px;padding:6px 10px" 
                                                    <?php echo ($intern['attendance'] && $intern['attendance']['jam_masuk'] && $intern['attendance']['jam_keluar']) ? 'disabled' : ''; ?>>
                                                <?php if (!$intern['attendance'] || !$intern['attendance']['jam_masuk']): ?>
                                                    Catat Masuk
                                                <?php elseif (!$intern['attendance']['jam_keluar']): ?>
                                                    Catat Keluar
                                                <?php else: ?>
                                                    Sudah Lengkap
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                            <!-- Izin functionality moved to Admin page; buttons removed here -->
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            // Advanced filtering for attendance table
            let currentStatusFilter = 'all';
            let currentEmployeeFilter = null;

            function updateVisibility() {
                document.querySelectorAll('.attendance-row').forEach(row => {
                    const statusMatch = currentStatusFilter === 'all' || row.dataset.status === currentStatusFilter;
                    const isIntern = row.querySelector('.text-red-800') !== null;
                    const employeeMatch = !currentEmployeeFilter || 
                        (currentEmployeeFilter === 'intern' && isIntern) || 
                        (currentEmployeeFilter === 'employee' && !isIntern);
                    
                    row.style.display = statusMatch && employeeMatch ? '' : 'none';
                });
            }

            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const filterType = btn.dataset.type;
                    const filter = btn.dataset.filter;
                    
                    // Update active state for the correct group
                    document.querySelectorAll(`.filter-btn[data-type="${filterType}"]`).forEach(b => {
                        b.classList.remove('active');
                    });
                    btn.classList.add('active');
                    
                    // Update current filters
                    if (filterType === 'status') {
                        currentStatusFilter = filter;
                    } else if (filterType === 'employee') {
                        currentEmployeeFilter = filter === currentEmployeeFilter ? null : filter;
                        // Toggle active state for employee type filters
                        if (!currentEmployeeFilter) {
                            btn.classList.remove('active');
                        }
                    }
                    
                    updateVisibility();
                });
            });

            // Count and display statistics
            function updateStats() {
                const rows = document.querySelectorAll('.attendance-row');
                let totalVisible = 0;
                let internCount = 0;
                let employeeCount = 0;

                rows.forEach(row => {
                    if (row.style.display !== 'none') {
                        totalVisible++;
                        if (row.querySelector('.text-red-800')) {
                            internCount++;
                        } else {
                            employeeCount++;
                        }
                    }
                });

                // Update stats if you have elements to display them
                // You can add elements to show these counts if needed
            }

            // Initial visibility update
            updateVisibility();

            // Handle client time for attendance and modal wiring after DOM ready
            document.addEventListener('DOMContentLoaded', function() {
                // Update time display
                function updateTimeDisplay() {
                    const now = new Date();
                    const timeString = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                    const dateString = now.toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
                    document.querySelector('.date-display').textContent = dateString + ' - ' + timeString;
                }

                // Update time every second
                setInterval(updateTimeDisplay, 1000);
                updateTimeDisplay();

                // Handle form submissions
                document.querySelectorAll('.attendance-form').forEach(form => {
                    form.addEventListener('submit', function(e) {
                        const now = new Date();
                        const clientTime = {
                            date: now.toISOString().split('T')[0],
                            time: now.toTimeString().split(' ')[0],
                            timezone: now.getTimezoneOffset()
                        };
                        this.querySelector('.client-time-input').value = JSON.stringify(clientTime);
                    });
                });

                // Modal handling: bind buttons and form behavior
                const izinModal = document.getElementById('izinModal');
                const izinForm = document.getElementById('izinForm');
                const izinIdInput = document.getElementById('izinKaryawanId');
                const izinNamaDisplay = document.getElementById('izinKaryawanNama');

                function openIzinModal(karyawanId, nama) {
                    if (!izinModal) return;
                    izinIdInput.value = karyawanId;
                    izinNamaDisplay.textContent = nama;
                    // show ID in the display row
                    const idDisp = document.getElementById('izinKaryawanIdDisplay');
                    if (idDisp) idDisp.textContent = karyawanId;
                    // set current datetime display
                    const dt = new Date();
                    const pad = n => n.toString().padStart(2,'0');
                    const dateStr = dt.getFullYear() + '-' + pad(dt.getMonth()+1) + '-' + pad(dt.getDate());
                    const timeStr = pad(dt.getHours()) + ':' + pad(dt.getMinutes()) + ':' + pad(dt.getSeconds());
                    const dtInput = document.getElementById('izinDatetimeDisplay');
                    if (dtInput) dtInput.value = dateStr + ' ' + timeStr;
                    // also update hidden client_time so server gets precise timestamp if user submits quickly
                    const hiddenClient = izinForm.querySelector('.client-time-input');
                    if (hiddenClient) hiddenClient.value = JSON.stringify({date:dateStr,time:timeStr,timezone:dt.getTimezoneOffset()});
                    // focus first input
                    setTimeout(() => {
                        const sel = izinForm.querySelector('select[name="jenis_izin"]');
                        if (sel) sel.focus();
                    }, 60);
                    // hide other page content inside card so only modal shows
                    const card = document.querySelector('.card');
                    if (card) card.classList.add('modal-hide-others');
                    // make visible
                    izinModal.classList.remove('hidden');
                    izinModal.setAttribute('aria-hidden', 'false');
                    izinModal.style.display = 'flex';
                }

                function closeIzinModal() {
                    if (!izinModal) return;
                    // hide modal
                    izinModal.classList.add('hidden');
                    izinModal.setAttribute('aria-hidden', 'true');
                    izinModal.style.display = 'none';
                    // restore page content
                    const card = document.querySelector('.card');
                    if (card) card.classList.remove('modal-hide-others');
                    if (izinForm) izinForm.reset();
                    // clear display rows (ID and datetime)
                    const idDisp = document.getElementById('izinKaryawanIdDisplay'); if (idDisp) idDisp.textContent = '';
                    const dtInput = document.getElementById('izinDatetimeDisplay'); if (dtInput) dtInput.value = '';
                }

                // Bind close buttons inside modal
                document.querySelectorAll('.izin-close-btn').forEach(b => b.addEventListener('click', closeIzinModal));

                // Ensure modal is hidden on load (defensive)
                if (izinModal) {
                    izinModal.classList.add('hidden');
                    izinModal.style.display = 'none';
                    izinModal.setAttribute('aria-hidden', 'true');
                }

                // Attach click handlers to all izin buttons
                document.querySelectorAll('.btn-izin').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const id = this.getAttribute('data-karyawan-id');
                        const nama = this.getAttribute('data-karyawan-nama');
                        openIzinModal(id, nama);
                    });
                });

                    // Attach click handlers for viewing izin details
                    const izinViewModal = document.getElementById('izinViewModal');
                    const viewNama = document.getElementById('viewIzinNama');
                    const viewId = document.getElementById('viewIzinId');
                    const viewJenis = document.getElementById('viewIzinJenis');
                    const viewAlasan = document.getElementById('viewIzinAlasan');
                    const viewCreated = document.getElementById('viewIzinCreated');

                    function openIzinView(data) {
                        if (!izinViewModal) return;
                        // populate
                        if (viewNama) viewNama.textContent = data.nama || '';
                        if (viewId) viewId.textContent = data.id || '';
                        if (viewJenis) viewJenis.textContent = (data.jenis || '').replace(/_/g,' ');
                        if (viewAlasan) viewAlasan.value = data.alasan || '';
                        if (viewCreated) viewCreated.textContent = data.created || '';
                        // hide other content
                        const card = document.querySelector('.card'); if (card) card.classList.add('modal-hide-others');
                        // show
                        izinViewModal.classList.remove('hidden'); izinViewModal.setAttribute('aria-hidden','false'); izinViewModal.style.display = 'flex';
                    }

                    function closeIzinView() {
                        if (!izinViewModal) return;
                        izinViewModal.classList.add('hidden'); izinViewModal.setAttribute('aria-hidden','true'); izinViewModal.style.display = 'none';
                        const card = document.querySelector('.card'); if (card) card.classList.remove('modal-hide-others');
                        if (viewAlasan) viewAlasan.value = '';
                    }

                    document.querySelectorAll('.btn-view-izin').forEach(b => {
                        b.addEventListener('click', function() {
                            // try to read embedded JSON data first (reliable for multiline alasan)
                            const row = this.closest('tr');
                            let izinData = null;
                            if (row) {
                                const hidden = row.querySelector('.izin-json');
                                if (hidden && hidden.value) {
                                    try {
                                        izinData = JSON.parse(hidden.value);
                                    } catch (e) {
                                        // fall back to attributes
                                        izinData = null;
                                    }
                                }
                            }

                            if (!izinData) {
                                // fallback to data attributes
                                const jenis = this.getAttribute('data-izin-jenis') || '';
                                const alasan = this.getAttribute('data-izin-alasan') || '';
                                const created = this.getAttribute('data-izin-created') || '';
                                const namaAttr = this.getAttribute('data-karyawan-nama');
                                const idAttr = this.getAttribute('data-karyawan-id');
                                let nama = namaAttr || '';
                                let id = idAttr || '';
                                if ((!nama || !id) && row) {
                                    const first = row.querySelector('td:first-child');
                                    const second = row.querySelector('td:nth-child(2)');
                                    if (!nama && first) nama = first.innerText.trim();
                                    if (!id && second) id = second.innerText.trim();
                                }
                                izinData = {nama:nama, id:id, jenis:jenis, alasan:alasan, created:created};
                            }

                            openIzinView({nama:izinData.nama || '', id:izinData.id || '', jenis:izinData.jenis || '', alasan:izinData.alasan || '', created:izinData.created || ''});
                        });
                    });

                    // bind close buttons for view modal
                    document.querySelectorAll('.izinview-close-btn').forEach(b => b.addEventListener('click', closeIzinView));

                    // hide view modal when clicking outside
                    if (izinViewModal) {
                        izinViewModal.addEventListener('click', function(e){ if (e.target === this) closeIzinView(); });
                    }

                // Handle izin form submit: set client time then submit
                if (izinForm) {
                    izinForm.addEventListener('submit', function(e) {
                        // allow normal submission after setting client_time
                        const now = new Date();
                        const clientTime = {
                            date: now.toISOString().split('T')[0],
                            time: now.toTimeString().split(' ')[0],
                            timezone: now.getTimezoneOffset()
                        };
                        const input = this.querySelector('.client-time-input');
                        if (input) input.value = JSON.stringify(clientTime);
                        // proceed with submission (no preventDefault)
                    });
                }

                // Close modal when clicking outside
                if (izinModal) {
                    izinModal.addEventListener('click', function(e) {
                        if (e.target === this) closeIzinModal();
                    });
                }
            });
        </script>

        <div id="clockDisplay" class="fixed bottom-4 right-4 bg-white p-4 rounded-lg shadow-lg text-xl font-mono"></div>



        <p class="small muted" style="margin-top:14px">Catatan: Sistem akan menyimpan data di database <code>absensi_db</code> jika tersedia. Jika tidak, sistem mencoba menyimpan di database tempat karyawan ditemukan atau di DB utama.</p>
    </div>
</body>
</html>
