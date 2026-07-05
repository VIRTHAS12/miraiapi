<?php

use Controllers\AuthController;

// 🟢 TIDAK PAKE MIDDLEWARE AUTH karena kodenya dikirim langsung oleh Google

$controller = new AuthController();
return $controller->handleCallback();
