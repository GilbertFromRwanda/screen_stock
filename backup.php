<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (!in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'])) {
    $_SESSION['flash_error'] = "Admin access only.";
    redirect('dashboard.php');
}

$db_name  = DB_NAME;
$filename = 'backup_' . $db_name . '_' . date('Y-m-d_H-i-s') . '.sql';

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "-- Database Backup: $db_name\n";
echo "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

$tables_q = mysqli_query($conn, "SHOW TABLES");
while ($tbl_row = mysqli_fetch_array($tables_q)) {
    $table = $tbl_row[0];

    $create_q   = mysqli_query($conn, "SHOW CREATE TABLE `$table`");
    $create_row = mysqli_fetch_assoc($create_q);
    echo "DROP TABLE IF EXISTS `$table`;\n";
    echo $create_row['Create Table'] . ";\n\n";

    $rows_q = mysqli_query($conn, "SELECT * FROM `$table`");
    if (mysqli_num_rows($rows_q) > 0) {
        $fields = mysqli_fetch_fields($rows_q);
        $cols   = array_map(fn($f) => "`{$f->name}`", $fields);
        $prefix = "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES\n";
        $batch  = [];

        while ($row = mysqli_fetch_row($rows_q)) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $v) . "'", $row);
            $batch[] = '(' . implode(', ', $vals) . ')';
            if (count($batch) >= 500) {
                echo $prefix . implode(",\n", $batch) . ";\n";
                $batch = [];
            }
        }
        if ($batch) echo $prefix . implode(",\n", $batch) . ";\n";
        echo "\n";
    }
}

echo "SET FOREIGN_KEY_CHECKS = 1;\n";
