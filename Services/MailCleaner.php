<?php

namespace Modules\Flowkom\Services;

/**
 * Extrahiert die eigentliche Kundennachricht aus eBay-/Amazon-Template-Mails.
 *
 * Reine, zustandslose Funktionen ohne Framework-Abhängigkeiten, damit die
 * Logik isoliert gegen historische Mail-Bodies getestet werden kann.
 *
 * Vertrag: clean() liefert entweder den neuen, sauberen HTML-Body oder NULL.
 * NULL bedeutet immer: Original unangetastet lassen (fail-open). Es wird nur
 * ersetzt, wenn die Kundennachricht eindeutig gefunden wurde.
 */
class MailCleaner
{
    /**
     * Preheader-Texte, die KEINE Kundennachricht sind (generische Vorschau).
     */
    const GENERIC_PREHEADERS = [
        'sie haben eine neue nachricht',
        'you have a new message',
        'you\'ve got a new message',
        'hai un nuovo messaggio',
        'vous avez un nouveau message',
        'tienes un mensaje nuevo',
        'je hebt een nieuw bericht',
        'masz nowa wiadomosc',
    ];

    /**
     * Amazon: Zeile, die den Nachrichtenblock einleitet (mehrsprachig).
     */
    const AMAZON_MSG_HEADER = '/^\s*(Nachricht|Message|Messaggio|Mensaje|Bericht|Wiadomo\x{015b}\x{0107}|Mensagem|Meddelande)\s*:\s*$/iu';

    /**
     * Amazon: Zeilen, die das Ende des Nachrichtenblocks markieren.
     */
    const AMAZON_FOOTERS = [
        '/^\s*(Nachricht anzeigen|Fall l\x{00f6}sen|Verd\x{00e4}chtige Aktivit\x{00e4}ten)/iu',
        '/^\s*(View message|Resolve case|Report suspicious)/iu',
        '/^\s*(Visualizza( il)? messaggio|Risolvi( il)? caso|Segnala)/iu',
        '/^\s*(Voir le message|R\x{00e9}soudre|Signaler)/iu',
        '/^\s*(Ver mensaje|Resolver( el)? caso|Informar|Reportar)/iu',
        '/^\s*(Bericht bekijken|Zaak oplossen|Verdachte)/iu',
        '/^\s*(Wy\x{015b}wietl wiadomo\x{015b}\x{0107}|Rozwi\x{0105}\x{017c} spraw\x{0119}|Zg\x{0142}o\x{015b})/iu',
        '/^\s*(Visa meddelande|L\x{00f6}s \x{00e4}rende|Rapportera)/iu',
        '/^\s*(Ver mensagem|Resolver o caso|Denunciar)/iu',
        '/^\s*(Dieser Service|This service|Questo servizio|Ce service|Este servicio|Deze service|Den h\x{00e4}r tj\x{00e4}nsten|Este servi\x{00e7}o|Us\x{0142}uga jest)/iu',
    ];

    /**
     * Haupteinstieg: liefert sauberen HTML-Body oder null (= nicht anfassen).
     *
     * @param string $body HTML-Body der eingehenden Mail
     * @param string $from Absenderadresse (lowercase)
     * @return string|null
     */
    public static function clean($body, $from)
    {
        if (!is_string($body) || $body === '' || !is_string($from)) {
            return null;
        }
        $from = strtolower(trim($from));

        if (preg_match('/@members\.ebay\./', $from)) {
            return self::cleanEbayMember($body);
        }
        if (strpos($from, '@marketplace.amazon.') !== false) {
            return self::cleanAmazonBuyer($body);
        }

        return null;
    }

    // =========================================================
    // eBay-Member-Nachrichten
    // =========================================================

    private static function cleanEbayMember($body)
    {
        $message = self::ebayMessageFromPreheader($body);

        if ($message === null) {
            $message = self::ebayMessageFromContainer($body);
        }

        // Vom Kaeufer mitgeschickte Fotos (alt="Attachment N") muessen
        // erhalten bleiben — auch bei Foto-Nachrichten ganz ohne Text.
        $images = self::ebayBuyerImages($body);

        if (($message === null || trim($message) === '') && !$images) {
            return null;
        }

        $meta = self::ebayMeta($body);

        return self::buildHtml((string) $message, $meta, 'ebay', $images);
    }

    /**
     * eBay bettet Kaeufer-Fotos als <img alt="Attachment N"> ein —
     * Template-Grafiken (Logos, Produktbild) haben andere alt-Texte.
     */
    private static function ebayBuyerImages($body)
    {
        $images = [];
        if (preg_match_all('/<img\b[^>]*alt="Attachment \d+"[^>]*>/i', $body, $matches)) {
            foreach ($matches[0] as $tag) {
                if (preg_match('/src="([^"]+)"/i', $tag, $src)
                    && preg_match('/^https:\/\/[a-z0-9.-]+\.(ebay|ebaystatic|ebayimg)\.[a-z.]+\//i', html_entity_decode($src[1]))
                ) {
                    $images[] = $src[1];
                }
            }
        }
        return $images;
    }

    /**
     * eBay legt die komplette Kundennachricht als Vorschau in
     * <div class="preheaderMod"> ab (verifiziert: identisch mit dem
     * UserInputtedText-Inhalt). Das funktioniert fuer Erst- UND
     * Folge-Nachrichten-Templates.
     */
    private static function ebayMessageFromPreheader($body)
    {
        if (!preg_match('/class="preheaderMod">(.*?)(?:<div style="display:none;">|<\/div>)/su', $body, $m)) {
            return null;
        }

        $text = self::htmlFragmentToText($m[1]);
        if ($text === '') {
            return null;
        }

        $generic = strtolower(self::asciiFold($text));
        foreach (self::GENERIC_PREHEADERS as $phrase) {
            if ($generic === $phrase) {
                return null;
            }
        }

        return $text;
    }

    /**
     * Fallback: dedizierter Container fuer die Kundennachricht im
     * Erstkontakt-Template.
     */
    private static function ebayMessageFromContainer($body)
    {
        if (!preg_match('/<div id="UserInputtedText">(.*?)<\/div>/su', $body, $m)) {
            return null;
        }
        $text = self::htmlFragmentToText($m[1]);
        return $text === '' ? null : $text;
    }

    private static function ebayMeta($body)
    {
        $text = self::htmlFragmentToText($body);
        $meta = [];

        if (preg_match('/(?:Artikelnr\.|Item number)\s*:?\s*(\d{9,14})/iu', $text, $m)) {
            $meta['Artikelnr.'] = $m[1];
        }
        if (preg_match('/(?:Bestellnummer|Order number)\s*:?\s*(\d{2}-\d{5}-\d{5})/iu', $text, $m)) {
            $meta['Bestellnr.'] = $m[1];
        }
        if (preg_match('/(?:Transaktionsnummer|Transaction number)\s*:?\s*(\d{6,20})/iu', $text, $m)) {
            $meta['Transaktionsnr.'] = $m[1];
        }
        if (preg_match('/(?:Bestellstatus|Order status)\s*:?\s*([^\r\n]{1,40})/iu', $text, $m)) {
            $meta['Status'] = trim($m[1]);
        }

        return $meta;
    }

    // =========================================================
    // Amazon-Kaeufernachrichten
    // =========================================================

    private static function cleanAmazonBuyer($body)
    {
        $lines = self::htmlToLines($body);
        if (!$lines) {
            return null;
        }

        $message = [];
        $capturing = false;
        foreach ($lines as $line) {
            if (!$capturing && preg_match(self::AMAZON_MSG_HEADER, $line)) {
                $capturing = true;
                continue;
            }
            if (!$capturing) {
                continue;
            }
            foreach (self::AMAZON_FOOTERS as $footer) {
                if (preg_match($footer, $line)) {
                    break 2;
                }
            }
            $message[] = $line;
        }

        $message = trim(implode("\n", $message));
        if ($message === '') {
            return null;
        }

        $meta = [];
        $text = implode("\n", $lines);
        if (preg_match('/(\d{3}-\d{7}-\d{7})/', $text, $m)) {
            $meta['Bestellnr.'] = $m[1];
        }
        if (preg_match('/\b(B0[A-Z0-9]{8})\b/', $text, $m)) {
            $meta['ASIN'] = $m[1];
            // Produktname steht im Template direkt nach der ASIN-Zeile.
            foreach ($lines as $i => $line) {
                if (trim($line) === $m[1] && isset($lines[$i + 1]) && mb_strlen($lines[$i + 1]) > 15) {
                    $meta['Artikel'] = mb_substr(trim($lines[$i + 1]), 0, 120);
                    break;
                }
            }
        }

        return self::buildHtml($message, $meta, 'amazon');
    }

    // =========================================================
    // HTML-Ausgabe
    // =========================================================

    private static function buildHtml($message, array $meta, $source, array $images = [])
    {
        $html = '<!--mmc:cleaned:' . $source . '-->';
        $html .= '<div class="mmc-message">' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</div>';

        foreach ($images as $src) {
            // $src stammt 1:1 aus dem Quell-HTML (bereits entity-kodiert).
            $html .= '<div class="mmc-image"><img src="' . $src . '" alt="Kundenfoto" style="max-width:340px;border-radius:8px;display:block;margin:8px 0;"></div>';
        }

        if ($meta) {
            $parts = [];
            foreach ($meta as $label => $value) {
                $parts[] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            $html .= '<div class="mmc-meta" style="margin-top:12px;padding-top:8px;border-top:1px solid #e3e8eb;color:#778;font-size:12px;">'
                . implode(' &nbsp;&middot;&nbsp; ', $parts)
                . '</div>';
        }

        return $html;
    }

    // =========================================================
    // Text-Utilities
    // =========================================================

    /**
     * HTML-Fragment zu bereinigtem Text mit erhaltenen Zeilenumbruechen.
     */
    public static function htmlFragmentToText($fragment)
    {
        $fragment = preg_replace('/<style\b[^>]*>.*?<\/style>/siu', ' ', $fragment);
        $fragment = preg_replace('/<script\b[^>]*>.*?<\/script>/siu', ' ', $fragment);
        $fragment = preg_replace('/<!--.*?-->/s', ' ', $fragment);
        $fragment = preg_replace('/<br\s*\/?>/i', "\n", $fragment);
        $fragment = preg_replace('/<\/(p|div|tr|td|table|li|h[1-6])>/i', "\n", $fragment);
        $fragment = strip_tags($fragment);
        $fragment = html_entity_decode($fragment, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $fragment = self::stripInvisible($fragment);

        // Zeilenweise trimmen, Mehrfach-Leerzeilen eindampfen.
        $lines = preg_split('/\r\n|\r|\n/', $fragment);
        $out = [];
        $blank = 0;
        foreach ($lines as $line) {
            $line = trim(preg_replace('/[ \t]+/u', ' ', $line));
            if ($line === '') {
                $blank++;
                if ($blank > 1) {
                    continue;
                }
            } else {
                $blank = 0;
            }
            $out[] = $line;
        }

        return trim(implode("\n", $out));
    }

    /**
     * Kompletter HTML-Body zu nicht-leeren Textzeilen.
     */
    public static function htmlToLines($body)
    {
        $text = self::htmlFragmentToText($body);
        $lines = [];
        foreach (preg_split('/\n/', $text) as $line) {
            if (trim($line) !== '') {
                $lines[] = trim($line);
            }
        }
        return $lines;
    }

    /**
     * Unsichtbare Fuellzeichen entfernen (eBay-Preheader-Padding u. ae.).
     */
    public static function stripInvisible($text)
    {
        return preg_replace('/[\x{034F}\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}\x{00AD}]/u', '', $text);
    }

    /**
     * Grobe ASCII-Faltung fuer Phrasen-Vergleiche.
     */
    private static function asciiFold($text)
    {
        $text = str_replace(
            ['ä','ö','ü','ß','Ä','Ö','Ü','á','à','é','è','í','ì','ó','ò','ú','ù','ą','ę','ś','ć','ż','ź','ł','ń'],
            ['a','o','u','ss','a','o','u','a','a','e','e','i','i','o','o','u','u','a','e','s','c','z','z','l','n'],
            $text
        );
        return preg_replace('/[^a-z0-9\' ]/i', '', $text);
    }
}
