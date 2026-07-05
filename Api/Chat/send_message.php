<?php

use Controllers\ChatController;

// 🔒 1. Validasi Token JWT internal via Middleware
\Core\Middleware::auth();

// 🚀 2. Inisialisasi ChatController dan eksekusi pengiriman pesan ke AI
$controller = new ChatController();
return $controller->sendMessageToAI();
