<?php

namespace Controllers;

use Model\ChatModel;
use Model\EventModel;
use Core\Database;
use WebSocket\Client;

class ChatController
{
    private $chatModel;
    private $eventModel;

    public function __construct()
    {
        $config = require BASE_PATH . 'config.php';
        $db = new Database($config);

        $this->chatModel = new ChatModel($db);
        $this->eventModel = new EventModel($db);
    }

    // 🤖 KIRIM PESAN KE AI 
    public function sendMessageToAI()
    {
        $user = \Core\Middleware::Userget();
        $input = jsonInput();

        $message = $input['message'] ?? '';
        $attachedEvent = $input['attached_event'] ?? null;

        if (!$message && !$attachedEvent) {
            return response('error', 'Pesan kosong', null, 400);
        }

        // Simpan pesan user asli ke DB lokal
        $savedContent = $message;
        if (empty($message) && $attachedEvent) {
            $savedContent = "[Mengirim Lampiran Jadwal: " . ($attachedEvent['title'] ?? '') . "]";
        }

        // 🔥 OPTIMASI 1: Amankan payload attachment user ke kolom konten secara terstruktur (JSON) jika ada
        $this->chatModel->saveMessage([
            'user_id' => $user['id'],
            'role'    => 'user',
            'content' => $savedContent
        ]);

        $currentDate = date('c');

        // SYSTEM PROMPT ADVANCED
        $systemPrompt = "Waktu saat ini: $currentDate.\n"
            . "Tugas lu adalah mendeteksi apakah user ingin MEMBUAT (create) jadwal baru, MENGUBAH (update) jadwal lama, atau MENGHAPUS (delete) jadwal.\n"
            . "PENTING: Jika ada data dari [CONTEXT ATTACHMENT JADWAL], gunakan data tersebut sebagai acuan target_title utama.\n"
            . "WAJIB kembalikan dalam format JSON murni tanpa markdown, tanpa teks pengantar.\n\n"
            . "Format Jika MEMBUAT Jadwal Baru:\n"
            . "{\"action\": \"create\", \"title\": \"Judul\", \"start\": \"ISO8601\", \"end\": \"ISO8601\"}\n\n"
            . "Format Jika MENGUBAH / UPDATE Jadwal:\n"
            . "{\"action\": \"update\", \"target_title\": \"Nama Jadwal Yang Mau Diubah\", \"title\": \"Judul Baru\", \"start\": \"ISO8601\", \"end\": \"ISO8601\"}\n\n"
            . "Format Jika MENGHAPUS Jadwal:\n"
            . "{\"action\": \"delete\", \"target_title\": \"Nama Jadwal Yang Mau Dihapus\"}\n\n"
            . "Jika tidak ada aksi kalender terdeteksi, kembalikan: {\"status\":\"no_event\"}";

        $finalUserMessage = $message;
        if ($attachedEvent) {
            $finalUserMessage = "[CONTEXT ATTACHMENT JADWAL]\n"
                . "Target Title / Current Title: " . ($attachedEvent['title'] ?? '') . "\n"
                . "Current Start: " . ($attachedEvent['start'] ?? '') . "\n"
                . "Current End: " . ($attachedEvent['end'] ?? '') . "\n"
                . "====================\n"
                . "User Message: " . $message;
        }

        $aiResponse = $this->callOpenClaw($systemPrompt, $finalUserMessage);

        if (isset($aiResponse['error'])) {
            return response('error', 'Koneksi ke OpenClaw putus: ' . $aiResponse['message'], null, 500);
        }

        if (!$aiResponse || !isset($aiResponse['choices'][0]['message']['content'])) {
            return response('error', 'Gagal mendapatkan respon dari OpenClaw Gateway', $aiResponse, 500);
        }

        $rawAiOutput = trim($aiResponse['choices'][0]['message']['content']);

        // REGEX SNIPER & AUTO-FIXER CLOSING BRACKET
        $parsed = null;
        if (preg_match('/\{[\s\S]*/', $rawAiOutput, $matches)) {
            $cleanJsonString = trim($matches[0]);
            if (str_starts_with($cleanJsonString, '{') && !str_ends_with($cleanJsonString, '}')) {
                $cleanJsonString .= '}';
            }
            $parsed = json_decode($cleanJsonString, true);
        }

        if (!$parsed || isset($parsed['status'])) {
            $fallbackMessage = !empty($rawAiOutput) ? $rawAiOutput : 'Tidak ada informasi jadwal terdeteksi dari kalimat lu, brok.';
            $this->chatModel->saveMessage([
                'user_id' => $user['id'],
                'role'    => 'assistant',
                'content' => $fallbackMessage
            ]);
            return response('success', 'Tidak ada event terdeteksi', ['ai_raw' => $fallbackMessage]);
        }

        $calendarController = new \Controllers\CalendarController();
        $targetTitleFromAI = $parsed['target_title'] ?? null;
        if (empty($targetTitleFromAI) && $attachedEvent) {
            $targetTitleFromAI = $attachedEvent['title'] ?? '';
        }

        $db = new Database(require BASE_PATH . 'config.php');

        // 1. ❌ AKSI MENGHAPUS JADWAL (DELETE)
        if (isset($parsed['action']) && $parsed['action'] === 'delete') {
            $targetEvent = null;
            if ($attachedEvent && !empty($attachedEvent['id'])) {
                $targetEvent = $db->query("SELECT * FROM events WHERE user_id = ? AND google_event_id = ? LIMIT 1", [$user['id'], $attachedEvent['id']])->take();
            }
            if (!$targetEvent && !empty($targetTitleFromAI)) {
                $targetEvent = $db->query("SELECT * FROM events WHERE user_id = ? AND LOWER(title) = ? LIMIT 1", [$user['id'], strtolower($targetTitleFromAI)])->take();
            }

            if (!$targetEvent) {
                $errContent = "Jadwal '" . ($targetTitleFromAI ?? 'Unknown') . "' emang gak ada, brok.";
                $this->chatModel->saveMessage([
                    'user_id' => $user['id'], 'role' => 'assistant', 'content' => $errContent
                ]);
                return response('error', $errContent, null, 404);
            }

            $calendarController->deleteEvent($targetEvent['id']);
            $successMessage = "Jadwal \"" . $targetEvent['title'] . "\" berhasil gue hapus dari Google Calendar lu, brok! 🗑️✅";

            $this->chatModel->saveMessage([
                'user_id' => $user['id'], 'role' => 'assistant', 'content' => $successMessage
            ]);

            return response('success', $successMessage, [
                'action' => 'delete',
                'event'  => [
                    'title' => $targetEvent['title'],
                    'start' => date('c', strtotime($targetEvent['start_time'])),
                    'end'   => date('c', strtotime($targetEvent['end_time']))
                ]
            ]);

        // 2. ✏️ AKSI MENGUBAH JADWAL (UPDATE)
        } elseif (isset($parsed['action']) && $parsed['action'] === 'update') {
            $targetEvent = null;
            if ($attachedEvent && !empty($attachedEvent['id'])) {
                $targetEvent = $db->query("SELECT * FROM events WHERE user_id = ? AND google_event_id = ? LIMIT 1", [$user['id'], $attachedEvent['id']])->take();
            }
            if (!$targetEvent && !empty($targetTitleFromAI)) {
                $targetEvent = $db->query("SELECT * FROM events WHERE user_id = ? AND LOWER(title) = ? LIMIT 1", [$user['id'], strtolower($targetTitleFromAI)])->take();
            }

            // Auto-Create Fallback jika data target hilang total
            if (!$targetEvent) {
                $eventResponse = $calendarController->createEventFromAI($user, !empty($parsed['title']) ? $parsed['title'] : ($attachedEvent['title'] ?? 'Presentasi AI'), $parsed['start'], $parsed['end']);
                $timeStartStr = date('H:i', strtotime($parsed['start']));
                $timeEndStr = date('H:i', strtotime($parsed['end']));
                $successMessage = "Jadwal lama gak ketemu, tapi udah gue buatin jadwal baru buat \"" . (!empty($parsed['title']) ? $parsed['title'] : ($attachedEvent['title'] ?? 'Presentasi AI')) . "\" di jam $timeStartStr - $timeEndStr WIB! 📅✅";

                $this->chatModel->saveMessage([
                    'user_id' => $user['id'], 'role' => 'assistant', 'content' => $successMessage
                ]);

                return response('success', $successMessage, [
                    'event' => [
                        'title' => !empty($parsed['title']) ? $parsed['title'] : ($attachedEvent['title'] ?? 'Presentasi AI'),
                        'start' => date('c', strtotime($parsed['start'])),
                        'end'   => date('c', strtotime($parsed['end']))
                    ]
                ]);
            }

            $updateData = [
                'title' => !empty($parsed['title']) ? $parsed['title'] : $targetEvent['title'],
                'start' => $parsed['start'],
                'end'   => $parsed['end']
            ];

            $calendarController->updateEvent($targetEvent['id'], $updateData);

            $timeStartStr = date('H:i', strtotime($parsed['start']));
            $timeEndStr = date('H:i', strtotime($parsed['end']));
            $successMessage = "Berhasil mengupdate kegiatan: \"" . $updateData['title'] . "\" menjadi jam $timeStartStr sampai $timeEndStr WIB! ✅";

            $this->chatModel->saveMessage([
                'user_id' => $user['id'], 'role' => 'assistant', 'content' => $successMessage
            ]);

            return response('success', $successMessage, [
                'event' => [
                    'title' => $updateData['title'],
                    'start' => date('c', strtotime($parsed['start'])),
                    'end'   => date('c', strtotime($parsed['end']))
                ]
            ]);

        // 3. 📅 AKSI BUAT JADWAL BARU (CREATE)
        } else {
            $eventResponse = $calendarController->createEventFromAI($user, $parsed['title'], $parsed['start'], $parsed['end']);

            if ($eventResponse['status'] === 'clash') {
                $clashMessage = "Gak bisa dijadwalkan brok, soalnya jam segitu lu ada jadwal tabrakan dengan kegiatan '" . $eventResponse['raw']['title'] . "'! 🛑";
                $this->chatModel->saveMessage([
                    'user_id' => $user['id'], 'role' => 'assistant', 'content' => $clashMessage
                ]);
                return response('error', $eventResponse['message'], $eventResponse['raw'], 409);
            }

            if ($eventResponse['status'] === 'error') {
                return response('error', $eventResponse['message'], $eventResponse['raw'], 500);
            }

            $successMessage = "Berhasil menjadwalkan kegiatan: " . $parsed['title'] . " ✅";
            $this->chatModel->saveMessage([
                'user_id' => $user['id'], 'role' => 'assistant', 'content' => $successMessage
            ]);

            return response('success', 'Event berhasil dibuat via AI', [
                'event' => [
                    'title' => $parsed['title'],
                    'start' => date('c', strtotime($parsed['start'])),
                    'end'   => date('c', strtotime($parsed['end']))
                ]
            ]);
        }
    }

    // 📖 LOGIC HISTORY - CLEAN DATA MURNI DARI BACKEND
    public function history()
    {
        $user = \Core\Middleware::Userget();
        $chatLogs = $this->chatModel->getChatHistory($user['id'], 20);
        $userEvents = $this->eventModel->getUserEvents($user['id']);

        // Indexing data lokal di memori untuk mempercepat loading history
        $eventMap = [];
        foreach ($userEvents as $evt) {
            $eventMap[strtolower($evt['title'])] = [
                'id'    => $evt['google_event_id'],
                'title' => $evt['title'],
                'start' => date('c', strtotime($evt['start_time'])),
                'end'   => date('c', strtotime($evt['end_time']))
            ];
        }

        $formattedData = [];

        foreach ($chatLogs as $chat) {
            $eventData = null;

            // 🧠 SINKRONISASI MATRIKS FRONTEND: Tarik judul murni menggunakan Regex minimalis yang aman
            if ($chat['role'] === 'assistant') {
                if (preg_match('/kegiatan:\s*\"?(.+?)\"?\s*(?:✅|menjadi|di|$)/u', $chat['content'], $matches)) {
                    $titleKey = strtolower(trim($matches[1]));
                    if (isset($eventMap[$titleKey])) {
                        $eventData = $eventMap[$titleKey];
                    }
                }
            } elseif ($chat['role'] === 'user' && str_starts_with($chat['content'], '[Mengirim Lampiran Jadwal:')) {
                if (preg_match('/\[Mengirim Lampiran Jadwal:\s*(.+?)\]/u', $chat['content'], $matches)) {
                    $titleKey = strtolower(trim($matches[1]));
                    if (isset($eventMap[$titleKey])) {
                        $eventData = $eventMap[$titleKey];
                    }
                }
            }

            $formattedData[] = [
                'role'       => $chat['role'],
                'content'    => $chat['content'],
                'created_at' => date('c', strtotime($chat['created_at'])),
                'event_data' => $eventData // Melempar data objek utuh, biar frontend lu bebas menyeleksinya!
            ];
        }

        return response('success', 'Riwayat chat asisten berhasil dimuat.', $formattedData);
    }

    private function callOpenClaw($systemPrompt, $userMessage) {
        // ... Logika WebSocket Client OpenClaw lu tetap dipertahankan murni ...
        $apiUrl = $_ENV['OPENCLAW_API_URL'];
        try {
            $client = new \WebSocket\Client($apiUrl, [
                'timeout' => 60,
                'headers' => ['Origin' => 'http://127.0.0.1:18789'],
                'context' => stream_context_create([
                    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
                ])
            ]);
            $handshake = $client->receive();
            $connectPayload = ["type" => "req", "id" => uniqid(), "method" => "connect", "params" => ["minProtocol" => 4, "maxProtocol" => 4, "client" => ["id" => "openclaw-control-ui", "version" => "control-ui", "platform" => "Linux x86_64", "mode" => "webchat"], "role" => "operator", "scopes" => ["operator.admin", "operator.read", "operator.write", "operator.approvals", "operator.pairing"], "auth" => ["password" => "kurokaze"]]];
            $client->text(json_encode($connectPayload));
            $auth = $client->receive();
            $authDecoded = json_decode($auth, true);
            if (!($authDecoded['ok'] ?? false)) { $client->close(); return ['error' => true, 'message' => 'Auth Client Gagal']; }
            $requestId = uniqid();
            $fullMessageString = !empty($systemPrompt) ? "[System Instruction: " . $systemPrompt . "]\nUser Message: " . $userMessage : $userMessage;
            $chatPayload = ["type" => "req", "id" => $requestId, "method" => "chat.send", "params" => ["sessionKey" => "agent:main:main", "idempotencyKey" => uniqid('idemp_', true), "message" => (string) $fullMessageString]];
            $client->text(json_encode($chatPayload, JSON_UNESCAPED_SLASHES));
            $aiTextResponse = "";
            while (true) {
                $msg = $client->receive(); if (!$msg) break;
                $decoded = json_decode($msg, true);
                if (isset($decoded['event']) && ($decoded['event'] === 'health' || $decoded['event'] === 'tick')) continue;
                if (isset($decoded['type']) && $decoded['type'] === 'event') {
                    if ($decoded['event'] === 'chat' || $decoded['event'] === 'agent') {
                        $chunkText = $decoded['payload']['deltaText'] ?? $decoded['payload']['data']['text'] ?? $decoded['payload']['data']['delta'] ?? '';
                        if (!empty($chunkText)) $aiTextResponse .= $chunkText;
                        if (isset($decoded['payload']['state']) && $decoded['payload']['state'] === 'error') { $client->close(); return ['error' => true, 'message' => $decoded['payload']['errorMessage'] ?? 'LLM Agent Error']; }
                        if (isset($decoded['payload']['state']) && $decoded['payload']['state'] === 'final') {
                            $finalText = $decoded['payload']['message']['content'][0]['text'] ?? $decoded['payload']['message']['content'][0] ?? '';
                            if (!empty($finalText) && is_string($finalText)) $aiTextResponse = $finalText;
                            break;
                        }
                        if (isset($decoded['payload']['done']) && $decoded['payload']['done'] === true) break;
                    }
                }
                if (isset($decoded['id']) && $decoded['id'] === $requestId) {
                    if (isset($decoded['ok']) && $decoded['ok'] === false) { $client->close(); return ['error' => true, 'message' => $decoded['errorMessage'] ?? 'Request chat.send rejected']; }
                    $finalText = $decoded['payload']['message']['content'][0]['text'] ?? '';
                    if (!empty($finalText)) { $aiTextResponse = $finalText; break; }
                    if (($decoded['ok'] ?? false) && !empty($aiTextResponse)) break;
                }
            }
            $client->close();
            return ['choices' => [['message' => ['role' => 'assistant', 'content' => trim($aiTextResponse)]]]];
        } catch (\Throwable $e) { return ['error' => true, 'message' => $e->getMessage()]; }
    }
}
