<?php

use Controllers\CalendarController;

\Core\Middleware::auth();

$id = $_GET['id'] ?? null;

if (!$id) {
    return response('error', '⚠️ ID jadwal lokal wajib dikirim, brok!', null, 400);
}

$controller = new CalendarController();
return $controller->updateEvent($id);
