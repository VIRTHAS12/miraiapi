<?php

use Controllers\AuthController;

// 🟢 TIDAK PAKE MIDDLEWARE AUTH karena ini akses publik awal

$controller = new AuthController();
return $controller->loginWithGoogle();
