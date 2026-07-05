<?php

namespace Modules\Flowkom\Services;

use App\Mailbox;

/**
 * Zentrale Options-Zugriffe + FreeScout-Settings-Seite (Einstellungen → Flowkom).
 *
 * Liest mit Fallback auf die Options der frueheren Einzelmodule, damit eine
 * bestehende Installation ohne Neukonfiguration migriert.
 */
class Settings
{
    const FEATURES = [
        'widget'       => 'Flowkom-Widget (Kundendaten & Bestellungen in der Sidebar)',
        'quicklinks'   => 'Marktplatz-QuickLinks (Seller Hub / Seller Central)',
        'cleaner'      => 'Mail-Cleaner (eBay-/Amazon-Template-Müll entfernen)',
        'ebay_clean_reply' => 'Saubere eBay-Antworten (ohne zitierten Verlauf, Referenz-ID automatisch eingebettet)',
        'merge_ebay'   => 'eBay Ticket-Merger (gleicher Käufer + Artikel)',
        'merge_amazon' => 'Amazon Ticket-Merger (gleiche Bestellnummer)',
        'chatview'     => 'Chat-Ansicht (Bubble-Ansicht pro Ticket umschaltbar)',
        'tracking_reply' => 'Ein-Klick-Tracking-Antwort im Widget',
        'displayfix'   => 'E-Mail-Darstellungs-Fix (breite HTML-Mails, Tracking-Pixel)',
    ];

    const DEFAULT_TRACKING_TEMPLATE = "Guten Tag,\n\nvielen Dank für Ihre Nachricht. Ihre Bestellung wurde mit {carrier} versendet.\n\nSendungsnummer: {tracking_number}\nSendungsverfolgung: {tracking_url}\n\nSollte die Sendung nicht innerhalb der nächsten Werktage ankommen, melden Sie sich gerne erneut bei uns.";

    public static function featureOn($key)
    {
        return (bool) \Option::get('flowkom.feature_' . $key, true);
    }

    public static function apiUrl()
    {
        return rtrim((string) \Option::get('flowkom.api_url', \Option::get('flowkomconnector.api_url', '')), '/');
    }

    public static function apiKey()
    {
        return (string) \Option::get('flowkom.api_key', \Option::get('flowkomconnector.api_key', ''));
    }

    public static function ebayDomain()
    {
        return (string) \Option::get('flowkom.ebay_domain', 'ebay.de');
    }

    public static function scDomain()
    {
        return (string) \Option::get('flowkom.sc_domain', 'sellercentral.amazon.de');
    }

    public static function trackingTemplate()
    {
        $tpl = (string) \Option::get('flowkom.tracking_reply_template', '');
        return $tpl !== '' ? $tpl : self::DEFAULT_TRACKING_TEMPLATE;
    }

    /**
     * Postfach-Einschränkung für Merger/Cleaner. Leer = alle Postfächer.
     */
    public static function mailboxIds()
    {
        $ids = \Option::get('flowkom.mailbox_ids', '[]');
        if (is_array($ids)) {
            return array_map('intval', $ids);
        }
        $decoded = json_decode((string) $ids, true);
        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    // =========================================================
    // Settings-Seite (FreeScout-Standard-Hooks)
    // =========================================================

    public static function register()
    {
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections['flowkom'] = [
                'title' => 'Flowkom',
                'icon'  => 'transfer',
                'order' => 350,
            ];
            return $sections;
        }, 35);

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section != 'flowkom') {
                return $settings;
            }
            foreach (array_keys(self::FEATURES) as $key) {
                $settings['flowkom.feature_' . $key] = self::featureOn($key);
            }
            $settings['flowkom.api_url'] = self::apiUrl();
            $settings['flowkom.api_key'] = self::apiKey();
            $settings['flowkom.ebay_domain'] = self::ebayDomain();
            $settings['flowkom.sc_domain'] = self::scDomain();
            $settings['flowkom.merge_max_age_days'] = (int) \Option::get('flowkom.merge_max_age_days', 60);
            $settings['flowkom.mailbox_ids'] = self::mailboxIds();
            $settings['flowkom.tracking_reply_template'] = self::trackingTemplate();
            return $settings;
        }, 20, 2);

        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section != 'flowkom') {
                return $params;
            }
            $mailbox_options = [];
            foreach (Mailbox::get() as $mailbox) {
                $mailbox_options[$mailbox->id] = $mailbox->name . ' (' . $mailbox->email . ')';
            }
            $params['template_vars'] = [
                'mailbox_options' => $mailbox_options,
                'features'        => self::FEATURES,
            ];
            // WICHTIG: Jeder Feature-Toggle braucht default=true, sonst loescht
            // FreeScouts Save-Loop die Option beim Abhaken (Checkbox nicht im
            // POST -> Option::remove()) und featureOn() faellt auf den Code-
            // Default true zurueck -> Toggle laesst sich nicht ausschalten.
            $settingsParams = [];
            foreach (array_keys(self::FEATURES) as $key) {
                $settingsParams['flowkom.feature_' . $key] = ['default' => true];
            }
            // API-Key als safe_password: ein rein aus '*' bestehender Wert
            // (unveraendertes Feld) wird vom Save-Loop uebersprungen -> der
            // gespeicherte Key geht beim Speichern nicht verloren.
            $settingsParams['flowkom.api_key'] = ['safe_password' => true];
            $params['settings'] = $settingsParams;
            return $params;
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section == 'flowkom' ? 'flowkom::settings' : $view;
        }, 20, 2);

        \Eventy::addFilter('settings.before_save', function ($request, $section, $settings) {
            if ($section != 'flowkom') {
                return $request;
            }

            // Feature-Toggles werden vom FreeScout-Save-Loop selbst gespeichert
            // (section_params default=true sorgt fuer korrektes false beim
            // Abhaken). Hier NICHT anfassen — der alte dot-notation-Zugriff
            // (`settings.flowkom\.feature_x`) las immer null.

            \Option::set('flowkom.api_url', rtrim(trim((string) $request->input('settings.flowkom\.api_url', '')), '/'));
            $apiKey = trim((string) $request->input('settings.flowkom\.api_key', ''));
            if ($apiKey !== '') {
                \Option::set('flowkom.api_key', $apiKey);
            }

            \Option::set('flowkom.ebay_domain', trim((string) $request->input('settings.flowkom\.ebay_domain', 'ebay.de')) ?: 'ebay.de');
            \Option::set('flowkom.sc_domain', trim((string) $request->input('settings.flowkom\.sc_domain', 'sellercentral.amazon.de')) ?: 'sellercentral.amazon.de');

            $maxAge = (int) $request->input('settings.flowkom\.merge_max_age_days', 60);
            \Option::set('flowkom.merge_max_age_days', $maxAge > 0 ? $maxAge : 60);

            $mailboxIds = $request->input('settings.flowkom\.mailbox_ids', []);
            \Option::set('flowkom.mailbox_ids', json_encode(is_array($mailboxIds) ? $mailboxIds : []));

            \Option::set('flowkom.tracking_reply_template', (string) $request->input('settings.flowkom\.tracking_reply_template', ''));

            \Session::flash('flash_success_floating', 'Flowkom-Einstellungen gespeichert.');
            return $request;
        }, 20, 3);
    }
}
