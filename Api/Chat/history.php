<?php
// Api/Chat/history.php

use Controllers\ChatController;

// 🔒 1. Nyalakan Gerbang Proteksi Token Sesi
\Core\Middleware::auth();

// 🚀 2. Instansiasi Controller dan panggil fungsinya lurus tanpa tumpukan!
$controller = new ChatController();
return $controller->history();
