<?php
// config.php

// Pastikan file ini ngebaca data segar dari .env yang udah di-load di index.php
return [
    'host'   => $_ENV['DB_HOST'] ?? 'localhost',
    'port'   => $_ENV['DB_PORT'] ?? '3306',
    'dbname' => $_ENV['DB_NAME'] ?? 'miraiplanner',
    'user'   => $_ENV['DB_USER'] ?? 'root',
    'password' => $_ENV['DB_PASS'] ?? '',
];
