<?php

namespace Modules\Flowkom\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Flowkom\Services\Settings;

class FlowkomController extends Controller
{
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
