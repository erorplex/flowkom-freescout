<?php

namespace Modules\Flowkom\Services;

/**
 * Marktplatz-Deeplinks in der Ticket-Sidebar. Alle URLs kanonisch und
 * tokenfrei aus Metadaten gebaut (tokenisierte Links aus Original-Mails
 * wuerden in zitierte Antworten leaken und laufen ab).
 */
class QuickLinks
{
    public static function register()
    {
        \Eventy::addAction('conversation.after_customer_sidebar', function ($conversation) {
            try {
                $links = self::build($conversation);
            } catch (\Throwable $e) {
                return;
            }
            if (!$links) {
                return;
            }

            $isEbay = $links['source'] === 'ebay';
            echo '<div class="flowkom-quicklinks" style="margin-top:10px;border:1px solid #d6dce2;border-radius:4px;overflow:hidden;font-size:12px;">';
            echo '<div style="padding:7px 10px;background:' . ($isEbay ? '#3665f3' : '#232f3e') . ';color:#fff;font-weight:600;font-size:11px;letter-spacing:.5px;">'
                . ($isEbay ? 'eBay' : 'Amazon') . ' QUICKLINKS</div>';
            echo '<div style="padding:8px 10px;background:#fff;display:flex;flex-direction:column;gap:5px;">';
            foreach ($links['items'] as $item) {
                echo '<a href="' . htmlspecialchars($item[1], ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" '
                    . 'style="display:block;text-align:center;padding:5px 0;border-radius:3px;font-size:11px;text-decoration:none;color:#fff;font-weight:500;background:'
                    . ($isEbay ? '#3665f3' : '#e47911') . ';">'
                    . htmlspecialchars($item[0], ENT_QUOTES) . '</a>';
            }
            echo '</div></div>';
        }, 30, 1);
    }

    private static function build($conversation)
    {
        if (empty($conversation) || empty($conversation->customer_email)) {
            return null;
        }
        $from = strtolower($conversation->customer_email);
        $subject = (string) $conversation->subject;

        $thread = $conversation->threads()
            ->where('type', \App\Thread::TYPE_CUSTOMER)
            ->orderBy('created_at', 'desc')
            ->first();
        $haystack = $subject . "\n" . ($thread ? (string) $thread->body : '');

        if (preg_match('/@members\.ebay\./', $from)) {
            $ebayDomain = Settings::ebayDomain();
            $items = [];

            $item = null;
            if (preg_match('/(?:Artikelnr\.|Item number)\s*:?\s*(\d{9,14})/iu', $haystack, $m)) {
                $item = $m[1];
            } elseif (preg_match('/#(\d{12,14})\b/', $subject, $m)) {
                $item = $m[1];
            }

            $order = null;
            if (preg_match('/\b(\d{2}-\d{5}-\d{5})\b/', $haystack, $m)) {
                $order = $m[1];
            }

            $buyer = null;
            if (preg_match('/(?:Betreff:\s*)?(?:AW:\s*)*([A-Za-z0-9._\-*]{3,64})\s+(?:hat eine Nachricht gesendet|sent a message)/u', $subject, $m)) {
                $buyer = $m[1];
            } elseif ($conversation->customer
                && preg_match('/^eBay\s*-\s*(.+)$/i', trim($conversation->customer->first_name . ' ' . $conversation->customer->last_name), $m)
            ) {
                $buyer = trim($m[1]);
            }

            if ($order) {
                $items[] = ['Bestellung im Seller Hub', 'https://www.' . $ebayDomain . '/sh/ord/details?orderid=' . rawurlencode($order)];
            }
            if ($item && $buyer) {
                $items[] = ['Konversation mit ' . $buyer, 'https://www.' . $ebayDomain . '/ulk/messages/reply?M2MContact&item=' . rawurlencode($item) . '&requested=' . rawurlencode($buyer) . '&redirect=0'];
            }
            if ($item) {
                $items[] = ['Artikelseite', 'https://www.' . $ebayDomain . '/itm/' . rawurlencode($item)];
            }

            return $items ? ['source' => 'ebay', 'items' => $items] : null;
        }

        if (strpos($from, '@marketplace.amazon.') !== false) {
            $scDomain = Settings::scDomain();
            $items = [];
            if (preg_match('/\b(\d{3}-\d{7}-\d{7})\b/', $haystack, $m)) {
                $items[] = ['Bestellung in Seller Central', 'https://' . $scDomain . '/orders-v3/order/' . rawurlencode($m[1])];
                $items[] = ['Messaging-Postfach', 'https://' . $scDomain . '/messaging/inbox'];
            }
            return $items ? ['source' => 'amazon', 'items' => $items] : null;
        }

        return null;
    }
}
