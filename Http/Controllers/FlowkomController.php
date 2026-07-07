<?php

namespace Modules\Flowkom\Http\Controllers;

use App\Conversation;
use App\Http\Controllers\Controller;
use Modules\Flowkom\Services\Brainflow;
use Modules\Flowkom\Services\Settings;

class FlowkomController extends Controller
{
    /**
     * PROJ-588: Ticket als Brainflow-Draft an Flowkom senden.
     * Liefert { import_url } für window.open — die Seite entsteht erst in
     * der Flowkom-Session des Agents (Owner = eingeloggter Flowkom-User).
     */
    public function brainflowSave($conversationId)
    {
        try {
            if (!Settings::featureOn('brainflow')) {
                return response()->json(['error' => 'Brainflow-Import ist deaktiviert.'], 403);
            }

            $conversation = Conversation::find((int) $conversationId);
            if (!$conversation) {
                return response()->json(['error' => 'Ticket nicht gefunden.'], 404);
            }

            // Zugriff wie in der Ticketansicht (Mandanten-/Postfach-Rechte)
            if (!auth()->user() || !auth()->user()->can('view', $conversation)) {
                return response()->json(['error' => 'Kein Zugriff auf dieses Ticket.'], 403);
            }

            $body = Brainflow::buildPayload($conversation);
            if ($body === null) {
                return response()->json(['error' => 'Ticket enthält keine importierbaren Nachrichten.'], 422);
            }

            $result = Brainflow::postDraft($body);
            if (empty($result['import_url'])) {
                return response()->json(['error' => 'Flowkom lieferte keine Import-URL.'], 502);
            }

            return response()->json(['import_url' => $result['import_url']]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function test()
    {
        $apiUrl = Settings::apiUrl();
        $apiKey = Settings::apiKey();

        if (empty($apiUrl) || empty($apiKey)) {
            return response()->json([
                'success' => false,
                'message' => 'API-URL und API-Key müssen konfiguriert sein.',
            ]);
        }

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $apiUrl . '/api/freescout/lookup?' . http_build_query(['email' => 'connection-test@flowkom.test']),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $apiKey,
                    'Accept: application/json',
                ],
            ]);
            curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if (!empty($curlErr)) {
                return response()->json(['success' => false, 'message' => 'Verbindungsfehler: ' . $curlErr]);
            }
            if ($httpCode === 401) {
                return response()->json(['success' => false, 'message' => 'API-Key ungültig (HTTP 401).']);
            }
            if ($httpCode >= 200 && $httpCode < 500) {
                return response()->json(['success' => true, 'message' => 'Verbindung erfolgreich (HTTP ' . $httpCode . ').']);
            }
            return response()->json(['success' => false, 'message' => 'Unerwarteter Status: HTTP ' . $httpCode]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Fehler: ' . $e->getMessage()]);
        }
    }
}
