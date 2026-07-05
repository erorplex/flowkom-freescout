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
    const EBAY_MEMBER_PATTERN = '/@members\.ebay\./i';
    const EBAY_ARTICLE_PATTERN = '/#(\d{12,14})/';
    const AMAZON_ORDER_PATTERN = '/(\d{3}-\d{7}-\d{7})/';

    const AMAZON_SENDER_PATTERNS = [
        '@marketplace.amazon.',
        '@amazon.de', '@amazon.com', '@amazon.co.uk', '@amazon.fr',
        '@amazon.it', '@amazon.es', '@amazon.nl', '@amazon.pl',
        '@amazon.se', '@amazon.com.be', '@amazon.ca', '@amazon.com.br',
    ];

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

        $conversation = Conversation::where('mailbox_id', $mailbox->id)
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
        foreach (self::AMAZON_SENDER_PATTERNS as $pattern) {
            if (strpos($from, $pattern) !== false) {
                return true;
            }
        }
        return false;
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
