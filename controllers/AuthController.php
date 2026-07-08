<?php

namespace Controllers;


use Model\UserModel;
use Core\Database;
use Core\JWT;
use Google\Client as GoogleClient;
// ✅ Biarkan murni tanpa alias, atau hapus baris ini kalau di bawah pakai full backslash
use Google\Service\Oauth2;
class AuthController
{
    private $userModel;

    public function __construct()
    {
        $config = require BASE_PATH . 'config.php';
        $db = new Database($config);
        $this->userModel = new UserModel($db);
    }

    // 🔧 Helper internal untuk inisialisasi Google Client standar
    private function getGoogleClient()
    {
        $client = new GoogleClient();
        $client->setClientId($_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID'));
        $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET'));
        $client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI'));

        // Daftarkan scopes akses data profile, email, dan Google Calendar
        $client->addScope("email");
        $client->addScope("profile");
        $client->addScope("https://www.googleapis.com/auth/calendar");

        // Wajib set offline agar Google selalu mengirimkan refresh_token di login pertama
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force'); // Memaksa muncul consent screen agar refresh_token selalu didapat

        return $client;
    }

    // 🔐 STEP 1: Redirect ke Google OAuth menggunakan Library Resmi
    public function loginWithGoogle()
    {
        $client = $this->getGoogleClient();

        // Ambil target return URL dari frontend (Expo Web / Mobile via query param)
        $returnTo = $_GET['return_to'] ?? 'http://localhost:8081';

        // Simpan returnUrl ke dalam parameter state (Base64 Safe String)
        $state = base64_encode(json_encode([
            'return_to' => $returnTo
        ]));
        $client->setState($state);

        // Bikin authorization URL resmi dari Google SDK
        $authUrl = $client->createAuthUrl();

        header("Location: " . $authUrl);
        exit();
    }

    // 🔄 STEP 2: Callback Handler dari Google
    public function handleCallback()
    {
        $code = $_GET['code'] ?? null;
        $state = $_GET['state'] ?? null;

        if (!$code) {
            return response('error', 'Authorization code dari Google tidak ditemukan, brok.', null, 400);
        }

        // Decode kembali parameter state untuk menentukan kemana token harus dikirim balik
        $stateData = json_decode(base64_decode($state), true);
        $returnTo = $stateData['return_to'] ?? 'mobile';

        try {
            $client = $this->getGoogleClient();

            // 🔁 Tukar code menjadi token array via SDK Resmi Google
            $tokenData = $client->fetchAccessTokenWithAuthCode($code);

            if (isset($tokenData['error'])) {
                return response('error', 'Gagal menukarkan code dengan token: ' . $tokenData['error_description'], $tokenData, 500);
            }

            // Daftarkan token aktif ke instance client
            $client->setAccessToken($tokenData);

            // 🔍 Ambil informasi profile user menggunakan Service Oauth2 resmi
            $googleOauth = new Oauth2($client);
            $userInfo = $googleOauth->userinfo->get();

            $googleId = $userInfo->id;
            $email = $userInfo->email;
            $name = $userInfo->name;

            $expiresIn = $tokenData['expires_in'] ?? 3600;
            $expiry = date('Y-m-d H:i:s', time() + $expiresIn);

            $user = $this->userModel->findByGoogleId($googleId);

            if (!$user) {
                $userId = $this->userModel->createUser([
                    'name'          => $name,
                    'email'         => $email,
                    'google_id'     => $googleId,
                    'access_token'  => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'token_expiry'  => $expiry
                ]);
            } else {
                $userId = $user['id'];
                $activeRefreshToken = $tokenData['refresh_token'] ?? $user['refresh_token'] ?? null;

                $this->userModel->updateToken($userId, [
                    'access_token'  => $tokenData['access_token'],
                    'refresh_token' => $activeRefreshToken,
                    'token_expiry'  => $expiry
                ]);
            }

            // 🔐 Generate JWT Token internal untuk otentikasi sesi di Expo
            $jwt = JWT::put([
                'id'    => $userId,
                'email' => $email,
                'name'  => $name
            ]);

            // 🔥 FIX UTAMA: Tentukan alur murni berdasarkan isi parameter 'return_to'
            if ($returnTo === 'mobile') {
                // 📱 JIKA DI HP (WebView Mobile App): Tampilkan halaman HTML script untuk postMessage agar pasti tertangkap
                header("Content-Type: text/html; charset=utf-8");
                $jsonResponse = json_encode([
                    'status' => 'success',
                    'data' => [
                        'token' => $jwt,
                        'user'  => ['id' => $userId, 'name' => $name, 'email' => $email]
                    ]
                ]);

                echo "
                <!DOCTYPE html>
                <html>
                <head><title>Logging In...</title></head>
                <body>
                    <pre id='json-data'>$jsonResponse</pre>
                    <script>
                        // Langsung tembak postMessage seketika halaman termuat
                        setTimeout(function() {
                            if (window.ReactNativeWebView) {
                                window.ReactNativeWebView.postMessage(document.getElementById('json-data').innerText);
                            }
                        }, 200);
                    </script>
                </body>
                </html>
                ";
                exit();
            } else {
                // 🌐 JIKA DI WEB BROWSER: Lemparkan query string token kembali ke URL asal frontend web
                $query = http_build_query([
                    'token' => $jwt,
                    'user'  => json_encode([
                        'id'    => $userId,
                        'name'  => $name,
                        'email' => $email
                    ])
                ]);

                header("Location: $returnTo?$query");
                exit();
            }
        } catch (\Exception $e) {
            return response('error', 'Terjadi kesalahan sistem OAuth: ' . $e->getMessage(), null, 500);
        }
    }

    // 🔁 Fungsi refresh token Google OAuth via Google SDK
    public function refreshToken()
    {
        $user = \Core\Middleware::Userget();

        if (!$user || empty($user['refresh_token'])) {
            return response('error', 'Refresh token tidak ditemukan di database lokal, brok.', null, 401);
        }

        try {
            $client = $this->getGoogleClient();

            // Eksekusi pembaruan token menggunakan refresh token lama dari DB
            $tokenData = $client->fetchAccessTokenWithRefreshToken($user['refresh_token']);

            if (isset($tokenData['error'])) {
                return response('error', 'Gagal merefresh token ke API Google: ' . $tokenData['error_description'], $tokenData, 500);
            }

            $expiresIn = $tokenData['expires_in'] ?? 3600;
            $expiry = date('Y-m-d H:i:s', time() + $expiresIn);

            // Simpan access token baru yang segar ke database lokal lu
            $this->userModel->updateToken($user['id'], [
                'access_token'  => $tokenData['access_token'],
                'refresh_token' => $user['refresh_token'], // Tetap pakai yang lama
                'token_expiry'  => $expiry
            ]);

            return response('success', 'Token Google Berhasil diperbarui via SDK!');
        } catch (\Exception $e) {
            return response('error', 'Gagal eksekusi refresh token: ' . $e->getMessage(), null, 500);
        }
    }

    public function getConfig()
    {
        $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID');

        if (!$clientId) {
            return response('error', 'Google Client ID tidak terbaca di .env backend, brok.', null, 500);
        }

        return response('success', 'Config loaded successfully, brok!', [
            'client_id' => $clientId
        ]);
    }
}
