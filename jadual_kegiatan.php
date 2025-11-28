<?php
session_start();
require_once 'jadual/config.php';
require_once 'includes/functions.php';

// Cek apakah user sudah login
if (!isLoggedIn()) {
    header('Location: index.php');
    exit();
}

// Debug session
// error_log('Session data: ' . print_r($_SESSION, true));

// Ambil role user dari session
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Jadwal Kegiatan</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            margin: 0;
            padding: 0;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e1e5eb;
        }

        h1 {
            color: #2c3e50;
            margin: 0;
            font-weight: 600;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-primary {
            background-color: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background-color: #2980b9;
        }

        .btn-danger {
            background-color: #e74c3c;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c0392b;
        }

        .btn-back {
            background-color: #7f8c8d;
            color: white;
            margin-bottom: 20px;
        }

        .btn-back:hover {
            background-color: #95a5a6;
        }

        .form-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        input,
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: inherit;
        }

        textarea {
            min-height: 100px;
        }

        .table-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e1e5eb;
        }

        th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        .action-btn {
            padding: 5px 10px;
            margin-right: 5px;
            border-radius: 3px;
            font-size: 14px;
        }

        .success-msg {
            background-color: #2ecc71;
            color: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: none;
        }

        .error-msg {
            background-color: #e74c3c;
            color: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: none;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <button class="btn btn-back" onclick="window.history.back()">Kembali</button>
            <h1>Pengaturan Jadwal Kegiatan</h1>
        </div>

        <div id="successMsg" class="success-msg"></div>
        <div id="errorMsg" class="error-msg"></div>

        <?php if ($isAdmin): ?>
        <div class="form-container">
            <h2>Tambah/Edit Jadwal</h2>
            <form id="scheduleForm">
                <input type="hidden" id="id" name="id">
                <div class="form-group">
                    <label for="nama_kegiatan">Nama Kegiatan</label>
                    <input type="text" id="nama_kegiatan" name="nama_kegiatan" required>
                </div>
                <div class="form-group">
                    <label for="tanggal">Tanggal</label>
                    <input type="date" id="tanggal" name="tanggal" required>
                </div>
                <div class="form-group">
                    <label for="waktu">Waktu</label>
                    <input type="time" id="waktu" name="waktu" required>
                </div>
                <div class="form-group">
                    <label for="deskripsi">Deskripsi</label>
                    <textarea id="deskripsi" name="deskripsi"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Simpan</button>
                <button type="button" class="btn btn-danger" onclick="resetForm()">Reset</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="table-container">
            <h2>Daftar Jadwal</h2>
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama Kegiatan</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Deskripsi</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="scheduleTable">
                    <?php
                    $query = "SELECT * FROM jadwal ORDER BY tanggal, waktu";
                    $result = mysqli_query($conn, $query);

                    if (mysqli_num_rows($result) > 0) {
                        $no = 1;
                        while ($row = mysqli_fetch_assoc($result)) {
                            echo "<tr>";
                            echo "<td>" . $no++ . "</td>";
                            echo "<td>" . htmlspecialchars($row['nama_kegiatan']) . "</td>";
                            echo "<td>" . $row['tanggal'] . "</td>";
                            echo "<td>" . substr($row['waktu'], 0, 5) . "</td>";
                            echo "<td>" . htmlspecialchars($row['deskripsi']) . "</td>";
                            // Status selesai/tidak
                            echo "<td>";
                            if (isset($row['status']) && $row['status'] == 'selesai') {
                                echo "<span style='color:green;font-weight:bold;'>Selesai</span>";
                            } else {
                                if ($isAdmin) {
                                    echo "<button class='btn btn-primary action-btn' onclick='markDone(" . $row['id'] . ")'>Tandai Selesai</button>";
                                } else {
                                    echo "<span style='color:orange;font-weight:bold;'>Belum Selesai</span>";
                                }
                            }
                            echo "</td>";
                            echo "<td>";
                            if ($isAdmin) {
                                echo "<button class='btn btn-primary action-btn' onclick='editSchedule(" . $row['id'] . ")'>Edit</button>";
                                echo "<button class='btn btn-danger action-btn' onclick='deleteSchedule(" . $row['id'] . ")'>Hapus</button>";
                            } else {
                                echo "<span class='text-muted'>-</span>";
                            }
                            echo "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='7' style='text-align: center;'>Tidak ada jadwal yang ditemukan</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
            <!-- Tombol Export Excel (hanya untuk admin) -->
            <?php if ($isAdmin): ?>
            <button class="btn btn-primary" style="margin-top:20px;" onclick="exportExcel()">Export ke Excel</button>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function showMessage(type, message) {
            if (type === 'success') {
                const msgElement = document.getElementById('successMsg');
                msgElement.style.display = 'block';
                msgElement.textContent = message;
                setTimeout(() => {
                    msgElement.style.display = 'none';
                }, 3000);
            } else {
                const msgElement = document.getElementById('errorMsg');
                msgElement.style.display = 'block';
                msgElement.textContent = message;
                setTimeout(() => {
                    msgElement.style.display = 'none';
                }, 3000);
            }
        }

        function resetForm() {
            document.getElementById('scheduleForm').reset();
            document.getElementById('id').value = '';
        }

        function editSchedule(id) {
            fetch('jadual/ajax.php?action=get&id=' + id)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('id').value = data.id;
                    document.getElementById('nama_kegiatan').value = data.nama_kegiatan;
                    document.getElementById('tanggal').value = data.tanggal;
                    document.getElementById('waktu').value = data.waktu;
                    document.getElementById('deskripsi').value = data.deskripsi;
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                })
                .catch(error => {
                    showMessage('error', 'Gagal mengambil data jadwal');
                });
        }

        function deleteSchedule(id) {
            if (confirm('Apakah Anda yakin ingin menghapus jadwal ini?')) {
                const formData = new FormData();
                formData.append('id', id);
                fetch('jadual/ajax.php?action=delete', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showMessage('success', data.message);
                            setTimeout(() => {
                                location.reload();
                            }, 500);
                        } else {
                            showMessage('error', data.message);
                        }
                    })
                    .catch(error => {
                        showMessage('error', 'Gagal menghapus jadwal');
                    });
            }
        }

        function markDone(id) {
            fetch('jadual/ajax.php?action=done', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'id=' + encodeURIComponent(id)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage('success', data.message);
                        setTimeout(() => {
                            location.reload();
                        }, 500);
                    } else {
                        showMessage('error', data.message);
                    }
                })
                .catch(() => showMessage('error', 'Gagal menandai selesai'));
        }

        function exportExcel() {
            window.location.href = 'jadual/export_excel.php';
        }

        document.getElementById('scheduleForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const action = formData.get('id') ? 'update' : 'add';
            fetch('jadual/ajax.php?action=' + action, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage('success', data.message);
                        setTimeout(() => {
                            location.reload();
                        }, 500);
                    } else {
                        showMessage('error', data.message);
                    }
                })
                .catch(error => {
                    showMessage('error', 'Terjadi kesalahan saat menyimpan data');
                });
        });

        // Set today's date as default
        const tanggalInput = document.getElementById('tanggal');
        if (tanggalInput) {
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            const dd = String(today.getDate()).padStart(2, '0');
            tanggalInput.value = `${yyyy}-${mm}-${dd}`;
        }
    </script>
</body>

</html>