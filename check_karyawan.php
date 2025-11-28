<?php
// Koneksi ke database
$conn = mysqli_connect('localhost', 'root', '', 'peminjaman_barang_db');
if (!$conn) {
    die("Koneksi gagal: " . mysqli_connect_error());
}

echo "<h2>Data Tabel Karyawan:</h2>";

// Cek struktur tabel
echo "<h3>Struktur Tabel:</h3>";
$structure = mysqli_query($conn, "DESCRIBE karyawan");
if ($structure) {
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($field = mysqli_fetch_assoc($structure)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($field['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($field['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($field['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($field['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($field['Default']) . "</td>";
        echo "<td>" . htmlspecialchars($field['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "Error mengecek struktur tabel: " . mysqli_error($conn);
}

// Cek isi data
echo "<h3>Isi Data:</h3>";
$data = mysqli_query($conn, "SELECT * FROM karyawan");
if ($data) {
    if (mysqli_num_rows($data) > 0) {
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Nama</th><th>ID Card</th><th>UID Kartu</th><th>Divisi</th><th>Jabatan</th><th>Created At</th></tr>";
        while ($row = mysqli_fetch_assoc($data)) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['id_karyawan']) . "</td>";
            echo "<td>" . htmlspecialchars($row['nama']) . "</td>";
            echo "<td>" . htmlspecialchars($row['id_card']) . "</td>";
            echo "<td>" . htmlspecialchars($row['uid_kartu']) . "</td>";
            echo "<td>" . htmlspecialchars($row['divisi']) . "</td>";
            echo "<td>" . htmlspecialchars($row['jabatan']) . "</td>";
            echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "Tabel karyawan kosong.";
        
        // Tambah data sampel jika tabel kosong
        echo "<h3>Menambah Data Sampel:</h3>";
        $insert = "INSERT INTO karyawan (nama, id_card, uid_kartu, divisi, jabatan) VALUES 
            ('John Doe', 'EMP001', '12345', 'IT', 'Staff'),
            ('Jane Smith', 'EMP002', '67890', 'HR', 'Manager'),
            ('Bob Johnson', 'EMP003', '11223', 'Finance', 'Analyst')";
        
        if (mysqli_query($conn, $insert)) {
            echo "Data sampel berhasil ditambahkan.<br>";
            echo "Silakan refresh halaman untuk melihat data.";
        } else {
            echo "Error menambah data sampel: " . mysqli_error($conn);
        }
    }
} else {
    echo "Error mengambil data: " . mysqli_error($conn);
}

mysqli_close($conn);
?>