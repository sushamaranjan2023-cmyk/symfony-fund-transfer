<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=fund_transfer', 'root', '');
    echo 'PDO OK' . PHP_EOL;
} catch(Exception $e) {
    echo 'PDO ERROR: ' . $e->getMessage() . PHP_EOL;
}
