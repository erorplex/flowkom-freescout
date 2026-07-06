<?php

namespace Modules\Flowkom\Services;

use App\Conversation;

/**
 * Ticket-Merger für eBay- und Amazon-Marktplatz-Mails.
 *
 * Erkennung läuft rein über den ABSENDER (members.ebay.*, marketplace.amazon.*),
 * nicht über das Postfach — funktioniert damit auch, wenn ein Mandant alle
 * Marktplätze über ein einziges Postfach (z. B. info@) laufen lässt. Optional
 * kann der Merge auf bestimmte Postfächer eingeschränkt werden.
 *
 * Merge-Regeln (bewusst streng, ein Fehl-Merge ist schlimmer als keiner):
 * - eBay:   NUR wenn Käufer-Alias UND Artikelnummer übereinstimmen.
 * - Amazon: NUR wenn Bestellnummer übereinstimmt (die ist käufer-eindeutig).
 */
class Mergers
{
    // Absender verankert an der eBay-/Amazon-Basis-Domain am Zeilenende —
    // verhindert Spoofing (x@members.ebay.evil.com bzw. foo@amazon.de.evil.com),
    // das ein unverankerter substr/preg durchgelassen haette.
    const EBAY_MEMBER_PATTERN = '/@members\.ebay\.[a-z]{2,3}(\.[a-z]{2})?$/i';
    const AMAZON_SENDER_PATTERN = '/@([a-z0-9-]+\.)*amazon\.[a-z]{2,3}(\.[a-z]{2})?$/i';
    const EBAY_ARTICLE_PATTERN = '/#(\d{12,14})/';
    const AMAZON_ORDER_PATTERN = '/(\d{3}-\d{7}-\d{7})/';

    /**
     * fetch_emails.data_to_save-Filter. Gibt $data zurück, ggf. mit prev_thread.
     */
    public static function apply($data)
    {
        if (!empty($data['prev_thread'])) {
            return $data;
        }
        if (empty($data['message_from_customer']) || empty($data['from']) || !is_string($data['from'])) {
            return $data;
        }
        $mailbox = $data['mailbox'] ?? null;
        if (empty($mailbox) || !self::mailboxAllowed((int) $mailbox->id)) {
            return $data;
        }

        $from = strtolower(trim($data['from']));
        $subject = (string) ($data['subject'] ?? '');
        $maxAgeDays = (int) \Option::get('flowkom.merge_max_age_days', 60);
        if ($maxAgeDays < 1) {
            $maxAgeDays = 60; // leeres/0-Feld darf den Merger nicht still abschalten
        }

        if (Settings::featureOn('merge_ebay') && preg_match(self::EBAY_MEMBER_PATTERN, $from)) {
            return self::mergeEbay($data, $from, $subject, $mailbox, $maxAgeDays);
        }

        if (Settings::featureOn('merge_amazon') && self::isAmazonSender($from)) {
            return self::mergeAmazon($data, $from, $subject, $mailbox, $maxAgeDays);
        }

        return $data;
    }

    private static function mergeEbay($data, $from, $subject, $mailbox, $maxAgeDays)
    {
        if (!preg_match(self::EBAY_ARTICLE_PATTERN, $subject, $m)) {
            return $data;
        }
        $article = $m[1];

        $conversation = Conversation::where('mailbox_id', $mailbox->id)
            ->where('customer_email', $from)
            ->where('subject', 'LIKE', '%#' . $article . '%')
            ->where('state', Conversation::STATE_PUBLISHED)
            ->where('created_at', '>=', now()->subDays($maxAgeDays))
            ->orderBy('created_at', 'desc')
            ->first();

        if ($conversation) {
            $thread = $conversation->threads()->orderBy('created_at', 'desc')->first();
            if ($thread) {
                $data['prev_thread'] = $thread;
                \Helper::log(FLOWKOM_MODULE, sprintf(
                    'eBay-Merge in Conversation #%d (Käufer %s, Artikel %s)',
                    $thread->conversation_id, $from, $article
                ));
            }
        }

        return $data;
    }

    private static function mergeAmazon($data, $from, $subject, $mailbox, $maxAgeDays)
    {
        if (!preg_match(self::AMAZON_ORDER_PATTERN, $subject, $m)) {
            return $data;
        }
        $order = $m[1];

        // customer_email MUSS matchen (wie beim eBay-Merger): sonst koennte
        // ein Fremdabsender, der eine gueltige Bestellnummer im Betreff nennt,
        // seinen Thread ins Ticket des echten Kaeufers injizieren — FreeScout
        // wechselt den Conversation-Kunden dann auf den Absender (Hijack).
        $conversation = Conversation::where('mailbox_id', $mailbox->id)
            ->where('customer_email', $from)
            ->where('subject', 'LIKE', '%' . $order . '%')
            ->where('state', Conversation::STATE_PUBLISHED)
            ->where('created_at', '>=', now()->subDays($maxAgeDays))
            ->orderBy('created_at', 'desc')
            ->first();

        if ($conversation) {
            $thread = $conversation->threads()->orderBy('created_at', 'desc')->first();
            if ($thread) {
                $data['prev_thread'] = $thread;
                \Helper::log(FLOWKOM_MODULE, sprintf(
                    'Amazon-Merge in Conversation #%d (Bestellung %s)',
                    $thread->conversation_id, $order
                ));
            }
        }

        return $data;
    }

    public static function isAmazonSender($from)
    {
        return (bool) preg_match(self::AMAZON_SENDER_PATTERN, (string) $from);
    }

    /**
     * Leere Liste = alle Postfächer (Default, info@-Szenario).
     */
    private static function mailboxAllowed($mailboxId)
    {
        $ids = Settings::mailboxIds();
        return !$ids || in_array($mailboxId, $ids, true);
    }
}
