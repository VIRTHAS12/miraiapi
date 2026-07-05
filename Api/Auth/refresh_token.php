<?php

use Controllers\AuthController;

// Proteksi token JWT internal dulu
\Core\Middleware::auth();

$controller = new AuthController();
return $controller->refreshToken();
