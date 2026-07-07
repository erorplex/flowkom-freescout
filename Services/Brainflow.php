<?php

namespace Modules\Flowkom\Services;

use App\Thread;

/**
 * PROJ-588 (Flowkom-Seite): Ein-Klick "In Brainflow sichern".
 *
 * Der Button POSTet den Ticket-Inhalt server-seitig (Integrations-API-Key) an
 * Flowkoms Draft-Endpoint und oeffnet die zurueckgelieferte Einmal-Import-URL
 * in einem neuen Tab. Die Brainflow-Seite entsteht erst dort, in der
 * Flowkom-Session des Agents — sie gehoert damit dem klickenden User.
 *
 * Sichtbarkeit doppelt gated: Feature-Toggle im Modul UND Brainflow im
 * Flowkom-Workspace aktiv (Capability-Endpoint, gecacht, fail-closed).
 */
class Brainflow
{
    const CAP_CACHE_MINUTES = 10;
    const CAP_NEGATIVE_CACHE_MINUTES = 1;

    /**
     * Ist der Import nutzbar? Fail-closed: jeder Fehler => false, damit ein
     * Flowkom-Ausfall FreeScout weder ausbremst noch kaputte Buttons zeigt.
     */
    public static function available()
    {
        if (!Settings::featureOn('brainflow')) {
            return false;
        }
        $apiUrl = Settings::apiUrl();
        $apiKey = Settings::apiKey();
        if (empty($apiUrl) || empty($apiKey)) {
            return false;
        }

        $cacheKey = 'flowkom.bf_cap.' . md5($apiUrl);
        $cached = \Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        $available = false;
        try {
            $response = self::httpJson('GET', $apiUrl . '/api/freescout/capabilities', $apiKey, null, 10);
            $available = !empty($response['brainflow']);
        } catch (\Throwable $e) {
            \Helper::log(FLOWKOM_MODULE, 'Brainflow-Capability-Check fehlgeschlagen: ' . $e->getMessage());
        }

        \Cache::put(
            $cacheKey,
            $available,
            now()->addMinutes($available ? self::CAP_CACHE_MINUTES : self::CAP_NEGATIVE_CACHE_MINUTES)
        );

        return $available;
    }

    /**
     * Sidebar-Panel mit dem Button registrieren.
     */
    public static function register()
    {
        \Eventy::addAction('conversation.after_customer_sidebar', function ($conversation) {
            try {
                if (!self::available()) {
                    return;
                }
                $mailboxIds = Settings::mailboxIds();
                if ($mailboxIds && !in_array((int) $conversation->mailbox_id, $mailboxIds, true)) {
                    return;
                }
                self::renderPanel($conversation);
            } catch (\Throwable $e) {
                // fail-open: Panel weglassen, Ticketansicht nie stoeren
            }
        }, 35, 1);
    }

    private static function renderPanel($conversation)
    {
        $saveUrl = url('/flowkom/brainflow/save/' . (int) $conversation->id);
        echo '<div class="flowkom-brainflow" style="margin-top:10px;border:1px solid #d6dce2;border-radius:4px;overflow:hidden;font-size:12px;">';
        echo '<div style="padding:7px 10px;background:#6d28d9;color:#fff;font-weight:600;font-size:11px;letter-spacing:.5px;">BRAINFLOW</div>';
        echo '<div style="padding:8px 10px;background:#fff;">';
        echo '<button type="button" id="flowkom-bf-save" data-url="' . htmlspecialchars($saveUrl, ENT_QUOTES) . '" '
            . 'style="display:block;width:100%;text-align:center;padding:6px 0;border:0;border-radius:3px;font-size:11px;color:#fff;font-weight:500;background:#6d28d9;cursor:pointer;">'
            . 'In Brainflow sichern</button>';
        echo '<div id="flowkom-bf-msg" style="display:none;margin-top:6px;color:#c0392b;"></div>';
        echo '</div></div>';
        echo '<script ' . \Helper::cspNonceAttr() . '>
(function() {
    var btn = document.getElementById("flowkom-bf-save");
    if (!btn || btn.dataset.bound) return;
    btn.dataset.bound = "1";
    btn.addEventListener("click", function() {
        var msg = document.getElementById("flowkom-bf-msg");
        msg.style.display = "none";
        btn.disabled = true;
        var original = btn.textContent;
        btn.textContent = "Wird gespeichert…";
        var tokenEl = document.querySelector("meta[name=csrf-token]") || document.querySelector("input[name=_token]");
        var token = tokenEl ? (tokenEl.content || tokenEl.value) : "";
        fetch(btn.dataset.url, {
            method: "POST",
            headers: { "X-CSRF-TOKEN": token, "Accept": "application/json" }
        })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, d: d }; }); })
        .then(function(res) {
            if (res.ok && res.d && res.d.import_url) {
                window.open(res.d.import_url, "_blank", "noopener");
            } else {
                msg.textContent = (res.d && res.d.error) ? res.d.error : "Speichern fehlgeschlagen — bitte erneut versuchen.";
                msg.style.display = "block";
            }
        })
        .catch(function() {
            msg.textContent = "Speichern fehlgeschlagen — bitte erneut versuchen.";
            msg.style.display = "block";
        })
        .finally(function() {
            btn.disabled = false;
            btn.textContent = original;
        });
    });
})();
</script>';
    }

    /**
     * Ticket → Draft-Payload (Vertrag: Flowkom draftPayloadSchema, PROJ-588).
     */
    public static function buildPayload($conversation)
    {
        // Interne Notizen (type=note) optional mitnehmen.
        $threadTypes = [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE];
        if (Settings::brainflowIncludeNotes()) {
            $threadTypes[] = Thread::TYPE_NOTE;
        }
        $threads = $conversation
            ->getThreads(null, null, $threadTypes)
            ->sortBy('created_at')
            ->values();

        $attachmentsMode = Settings::brainflowAttachmentsMode();
        $messages = [];
        $attachments = [];

        foreach ($threads as $thread) {
            $type = (int) $thread->type;
            $isCustomer = $type === Thread::TYPE_CUSTOMER;
            $isNote = $type === Thread::TYPE_NOTE;
            $body = (string) $thread->body;

            if ($isCustomer) {
                // Anzeige-Bereinigung wiederverwenden (fail-open: null => roh)
                try {
                    $from = (string) ($thread->from ?: ($conversation->customer_email ?? ''));
                    $clean = MailCleaner::clean($body, $from);
                    if ($clean !== null) {
                        $body = $clean;
                    }
                } catch (\Throwable $e) {
                    // roher Body bleibt
                }
                $author = trim((string) optional($thread->customer_cached ?? $thread->customer)->getFullName(true))
                    ?: (string) ($conversation->customer_email ?? 'Kunde');
            } else {
                // Agent-Antwort ODER interne Notiz: beide vom Agent, roher Body
                $author = trim((string) optional($thread->created_by_user_cached ?? $thread->created_by_user)->getFullName())
                    ?: 'Agent';
            }

            $messageIndex = count($messages);
            $messages[] = [
                'author'      => mb_substr($author, 0, 490),
                'author_type' => $isCustomer ? 'customer' : ($isNote ? 'note' : 'agent'),
                'date'        => (string) $thread->created_at,
                'html'        => mb_substr($body, 0, 195000),
            ];

            if ($attachmentsMode !== 'none' && $thread->has_attachments) {
                foreach ($thread->attachments as $attachment) {
                    if (count($attachments) >= 50) {
                        break;
                    }
                    $url = (string) $attachment->url();
                    if (strpos($url, 'http') !== 0) {
                        $url = rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/');
                    }
                    $attachments[] = [
                        'name'          => mb_substr((string) $attachment->file_name, 0, 490) ?: 'anhang',
                        'url'           => $url,
                        'mime'          => (string) $attachment->mime_type,
                        'size'          => (int) $attachment->size,
                        'message_index' => $messageIndex,
                    ];
                }
            }
        }

        // Ohne Nachrichten kein Import (Schema verlangt min. 1)
        if (!$messages) {
            return null;
        }

        return [
            'payload' => [
                'subject'             => mb_substr((string) $conversation->subject, 0, 490) ?: ('Ticket #' . $conversation->number),
                'ticket_number'       => (string) $conversation->number,
                'ticket_url'          => $conversation->url(),
                'customer_name'       => mb_substr(trim((string) optional($conversation->customer)->getFullName(true)), 0, 490) ?: null,
                'customer_email'      => mb_substr((string) $conversation->customer_email, 0, 490) ?: null,
                'mailbox_name'        => mb_substr((string) optional($conversation->mailbox)->name, 0, 490) ?: null,
                'target_parent_title' => Settings::brainflowParentTitle() ?: null,
                'property_fields'     => Settings::brainflowPropertyFields(),
                'channel'             => self::detectChannel($conversation),
                'messages'            => $messages,
                'attachments'         => $attachments,
            ],
            'attachments_mode' => $attachmentsMode,
        ];
    }

    /**
     * Marktplatz-Kanal aus der Kundenadresse (für die Kanal-Property).
     */
    private static function detectChannel($conversation)
    {
        $email = strtolower((string) $conversation->customer_email);
        if (preg_match('/@members\.ebay\./', $email)) {
            return 'ebay';
        }
        if (preg_match('/@marketplace\.amazon\./', $email)) {
            return 'amazon';
        }
        return 'email';
    }

    /**
     * Draft an Flowkom senden. Liefert ['import_url' => ...] oder wirft.
     */
    public static function postDraft(array $body)
    {
        $apiUrl = Settings::apiUrl();
        $apiKey = Settings::apiKey();
        if (empty($apiUrl) || empty($apiKey)) {
            throw new \RuntimeException('API-URL und API-Key müssen konfiguriert sein.');
        }
        return self::httpJson('POST', $apiUrl . '/api/freescout/brainflow-draft', $apiKey, $body, 15);
    }

    /**
     * Kleiner JSON-HTTP-Client (curl, wie der Verbindungstest).
     * Wirft bei Transportfehlern; HTTP-Fehler liefern das dekodierte
     * Fehler-JSON via Exception-Message weiter.
     */
    private static function httpJson($method, $url, $apiKey, $body, $timeout)
    {
        $ch = curl_init();
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
        ];
        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
        ];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if (!empty($curlErr)) {
            throw new \RuntimeException('Flowkom nicht erreichbar: ' . $curlErr);
        }
        $decoded = json_decode((string) $raw, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $message = is_array($decoded) && !empty($decoded['error'])
                ? $decoded['error']
                : ('Flowkom antwortete mit HTTP ' . $httpCode);
            throw new \RuntimeException($message);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('Unerwartete Antwort von Flowkom.');
        }
        return $decoded;
    }
}
