<?php

use Controllers\CalendarController;

\Core\Middleware::auth();

$controller = new CalendarController();
return $controller->createEvent();
