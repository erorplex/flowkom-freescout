<?php

namespace Modules\Flowkom\Services;

use App\Thread;

/**
 * Saubere Antworten an eBay-Kaeufer.
 *
 * Problem 1 (Optik): FreeScout haengt bei APP_EMAIL_CONV_HISTORY=full die
 * komplette Historie an jede Antwort. Bei eBay-Tickets war das frueher die
 * rohe Template-Mail — eBay wandelt HTML beim Kaeufer in Text um, dadurch
 * erschienen nackte Bild-URLs, Logos und doppelte Meta-Zeilen ("komplett
 * scheisse", O-Ton). Loesung: fuer eBay-Konversationen wird KEIN Verlauf
 * mitgesendet — der Kaeufer sieht nur den Antworttext.
 *
 * Problem 2 (Routing): eBay stellt Verkaeufer-Antworten nur zu, wenn die
 * "Email reference id: [#...#]" aus der Kaeufer-Nachricht in der Antwort
 * enthalten ist. Ohne Verlauf wuerde sie fehlen. Loesung: die Referenz der
 * juengsten Kaeufer-Nachricht wird dezent ans Ende des Antwort-Bodys gesetzt.
 *
 * Sicherheitsnetz: Wird KEINE Referenz gefunden (Altbestand, dessen Body vor
 * v2.0.3 bereinigt wurde), bleibt ALLES beim Standardverhalten (voller
 * Verlauf) — wir machen eine Zustellung nie schlechter als heute.
 */
class EbayReply
{
    const EBAY_MEMBER_PATTERN = '/@members\.ebay\./i';
    const REF_PATTERN = '/\[#\s*([A-Za-z0-9]{16,64})\s*#\]/';

    public static function register()
    {
        // Verlauf nur unterdruecken, wenn die Referenz sicher eingebettet
        // werden kann (sonst Standardverhalten = Verlauf traegt die Referenz).
        \Eventy::addFilter('jobs.send_reply_to_customer.send_previous_messages', function ($send, $last_thread, $threads, $conversation, $customer) {
            try {
                if (self::isEbayConversation($conversation) && self::findReferenceId($conversation) !== null) {
                    return false;
                }
            } catch (\Throwable $e) {
                \Helper::log(FLOWKOM_MODULE, 'EbayReply-ERROR (Verlauf bleibt an): ' . $e->getMessage());
            }
            return $send;
        }, 20, 5);

        // Referenz der juengsten Kaeufer-Nachricht in den Antwort-Body setzen.
        // Laeuft NACH der Verlauf-Entscheidung; bei unterdruecktem Verlauf
        // enthaelt $threads nur noch die Antwort selbst.
        \Eventy::addFilter('email.reply_to_customer.threads', function ($threads, $conversation, $mailbox) {
            try {
                if (!self::isEbayConversation($conversation)) {
                    return $threads;
                }
                $ref = self::findReferenceId($conversation);
                if ($ref === null) {
                    return $threads;
                }
                $reply = $threads->first();
                if (!$reply || (int) $reply->type !== Thread::TYPE_MESSAGE) {
                    return $threads;
                }
                // Schon vorhanden (z. B. manuell eingefuegt oder erneuter Versand)?
                if (preg_match(self::REF_PATTERN, (string) $reply->body)) {
                    return $threads;
                }
                $reply->body .= '<div style="margin-top:14px;color:#aab2ba;font-size:11px;">'
                    . 'Email reference id: [#' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '#]'
                    . '</div>';
            } catch (\Throwable $e) {
                \Helper::log(FLOWKOM_MODULE, 'EbayReply-ERROR (Body unveraendert): ' . $e->getMessage());
            }
            return $threads;
        }, 20, 3);
    }

    private static function isEbayConversation($conversation)
    {
        return !empty($conversation->customer_email)
            && preg_match(self::EBAY_MEMBER_PATTERN, (string) $conversation->customer_email);
    }

    /**
     * Referenz der JUENGSTEN Kaeufer-Nachricht (eBay rotiert sie pro Mail).
     * Post-v2.0.3-Cleaner-Bodies enthalten sie; rohe eBay-Templates ebenso.
     *
     * @return string|null
     */
    private static function findReferenceId($conversation)
    {
        static $cache = [];
        if (array_key_exists($conversation->id, $cache)) {
            return $cache[$conversation->id];
        }

        $ref = null;
        $customerThreads = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_CUSTOMER)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        foreach ($customerThreads as $thread) {
            if (preg_match(self::REF_PATTERN, (string) $thread->body, $m)) {
                $ref = $m[1];
                break;
            }
        }

        return $cache[$conversation->id] = $ref;
    }
}
