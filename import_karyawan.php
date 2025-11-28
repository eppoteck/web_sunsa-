<?php
// Database connection
$conn = mysqli_connect('localhost', 'root', '', 'peminjaman_barang_db');
if (!$conn) {
    die('Koneksi gagal: ' . mysqli_connect_error());
}

// Check if karyawan table exists and has data
$check = mysqli_query($conn, "SELECT COUNT(*) as count FROM karyawan");
$result = mysqli_fetch_assoc($check);

if ($result['count'] == 0) {
    // Insert sample employee data
    $insert = "INSERT INTO karyawan (nama, id_card, uid_kartu, divisi, jabatan) VALUES 
    ('John Doe', 'EMP001', '12345', 'IT', 'Staff'),
    ('Jane Smith', 'EMP002', '67890', 'HR', 'Manager'),
    ('Bob Johnson', 'EMP003', '11223', 'Finance', 'Analyst')";
    
    if (mysqli_query($conn, $insert)) {
        echo "Sample data berhasil ditambahkan ke tabel karyawan.\n";
    } else {
        echo "Error: " . mysqli_error($conn) . "\n";
    }
} else {
    echo "Tabel karyawan sudah berisi data. Total record: " . $result['count'] . "\n";
}

// Display current data
$data = mysqli_query($conn, "SELECT * FROM karyawan");
echo "\nData karyawan saat ini:\n";
while ($row = mysqli_fetch_assoc($data)) {
    echo "ID: " . $row['id_karyawan'] . 
         " | Nama: " . $row['nama'] . 
         " | ID Card: " . $row['id_card'] . 
         " | UID Kartu: " . $row['uid_kartu'] . "\n";
}

mysqli_close($conn);
?>