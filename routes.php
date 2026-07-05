<?php

return function ($router) {
    $router->get('/api/auth/google', 'Api/Auth/login_google.php'); 
    $router->get('/api/auth/google/callback', 'Api/Auth/callback.php');
    $router->post('/api/auth/refresh-token', 'Api/Auth/refresh_token.php'); // Buat perpanjang token
    $router->get('/api/auth/config', 'Api/Auth/config.php');

    $router->post('/api/chat/send', 'Api/Chat/send_message.php');
    $router->get('/api/chat/history', 'Api/Chat/history.php'); // Ambil riwayat percakapan user

    $router->get('/api/calendar/events', 'Api/Calendar/list.php');         // Ambil semua jadwal user
    $router->post('/api/calendar/event', 'Api/Calendar/create.php');       // Tambah jadwal via form manual Expo
    $router->put('/api/calendar/event', 'Api/Calendar/update.php');        // Update jadwal (Menerima ?id=X)
    $router->delete('/api/calendar/event', 'Api/Calendar/delete.php');     // Hapus jadwal (Menerima ?id=X)
};
