<?php

namespace Modules\Flowkom\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Flowkom\Services\ChatView;
use Modules\Flowkom\Services\DisplayFix;
use Modules\Flowkom\Services\MailCleaner;
use Modules\Flowkom\Services\Mergers;
use Modules\Flowkom\Services\QuickLinks;
use Modules\Flowkom\Services\Settings;

define('FLOWKOM_MODULE', 'flowkom');

/**
 * Flowkom Helpdesk Suite — ein Modul, alle Funktionen, einzeln zuschaltbar
 * unter Einstellungen → Flowkom. Marktplatz-Erkennung läuft absenderbasiert
 * und funktioniert daher auch mit einem einzigen Sammel-Postfach (info@).
 */
class FlowkomServiceProvider extends ServiceProvider
{
    protected $defer = false;

    public function boot()
    {
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'flowkom');

        Settings::register();
        $this->registerRoutes();
        $this->registerCsp();

        // Mail-Pipeline: nur der Merger fasst eingehende Mails an (Betreff/
        // Absender-Matching, KEIN Body-Touch). Fail-open.
        \Eventy::addFilter('fetch_emails.data_to_save', function ($data) {
            try {
                return Mergers::apply($data);
            } catch (\Throwable $e) {
                \Helper::log(FLOWKOM_MODULE, 'Merger-ERROR (kein Merge): ' . $e->getMessage());
                return $data;
            }
        }, 20, 1);

        // ANZEIGE-Cleaner: bereinigt Marktplatz-Templates NUR in der Ticket-
        // Ansicht (thread.body_output). Der gespeicherte Body bleibt IMMER das
        // Original. WARUM: eBay stellt Verkaeufer-Antworten nur zu, wenn die
        // zitierte Original-Mail ihre versteckten Marker enthaelt — Live-Beweis
        // 05./06.07.26: ersetzter Body => Bounce von failurenotice@, roher
        // Body => zugestellt. Der fruehere fetch-time-Body-Ersatz ist deshalb
        // dauerhaft entfernt und darf NIE wieder eingebaut werden.
        \Eventy::addFilter('thread.body_output', function ($body, $thread, $conversation, $mailbox) {
            try {
                if (!Settings::featureOn('cleaner')) {
                    return $body;
                }
                if ((int) $thread->type !== \App\Thread::TYPE_CUSTOMER) {
                    return $body;
                }
                // Altbestand-Guard MUSS auf den ROHEN Body schauen: der Marker
                // ist ein HTML-Kommentar, den HTMLPurifier aus dem uebergebenen
                // $body (via getBodyWithFormatedLinks) entfernt — auf $body waere
                // der Guard immer false und der Cleaner liefe ueber alten Output.
                if (strpos((string) $thread->body, 'mmc:cleaned') !== false) {
                    return $body; // v2.0.x-Altbestand: Body wurde beim Abruf ersetzt
                }
                $from = (string) ($thread->from ?: ($conversation->customer_email ?? ''));
                // WICHTIG: den ROHEN gespeicherten Body cleanen — der uebergebene
                // $body kommt aus getBodyWithFormatedLinks(), das die Template-
                // Marker (preheaderMod/UserInputtedText) bereits wegnormalisiert.
                $clean = MailCleaner::clean((string) $thread->body, $from);
                if ($clean !== null) {
                    return $clean;
                }
            } catch (\Throwable $e) {
                \Helper::log(FLOWKOM_MODULE, 'DisplayCleaner-ERROR (Original angezeigt): ' . $e->getMessage());
            }
            return $body;
        }, 20, 4);

        // eBay-Zustell-Garantie (IMMER aktiv, kein Toggle): eBay stellt
        // Verkaeufer-Antworten an xxx@members.ebay.* nur zu, wenn die
        // zitierte Original-Mail mitgesendet wird. FreeScouts Default ist
        // APP_EMAIL_CONV_HISTORY=none — ohne diesen Filter bounct auf einer
        // frisch aufgesetzten Instanz jede eBay-Antwort. Erzwingt den Verlauf
        // nur fuer eBay-Konversationen; alle anderen folgen der Instanz-
        // Einstellung. Ueberstimmt bewusst auch die Pro-Antwort-Auswahl des
        // Agents ("kein Verlauf" wuerde bouncen).
        \Eventy::addFilter('jobs.send_reply_to_customer.send_previous_messages', function ($send, $last_thread, $threads, $conversation, $customer) {
            try {
                if ($send) {
                    return $send;
                }
                if (MailCleaner::isEbayMemberAddress((string) ($conversation->customer_email ?? ''))) {
                    return true;
                }
            } catch (\Throwable $e) {
                \Helper::log(FLOWKOM_MODULE, 'EbayHistory-ERROR (Instanz-Einstellung gilt): ' . $e->getMessage());
            }
            return $send;
        }, 20, 5);

        if (Settings::featureOn('quicklinks')) {
            QuickLinks::register();
        }
        if (Settings::featureOn('chatview')) {
            ChatView::register();
        }
        if (Settings::featureOn('displayfix')) {
            DisplayFix::register();
        }
        if (Settings::featureOn('widget')) {
            $this->registerWidget();
        }
    }

    public function register()
    {
        //
    }

    private function registerRoutes(): void
    {
        \Route::group([
            'middleware' => ['web', 'auth', 'roles'],
            'roles'      => ['admin'],
            'prefix'     => 'flowkom',
            'namespace'  => 'Modules\Flowkom\Http\Controllers',
        ], function () {
            \Route::post('/test', 'FlowkomController@test')->name('flowkom.test');
        });
    }

    /**
     * fetch() zur Flowkom-API erlauben (FreeScouts CSP ist default-src 'self').
     */
    private function registerCsp(): void
    {
        $apiUrl = Settings::apiUrl();
        if (empty($apiUrl)) {
            return;
        }
        $host = parse_url($apiUrl, PHP_URL_HOST);
        if (!$host) {
            return;
        }
        \Eventy::addFilter('csp.custom', function ($csp) use ($host) {
            return $csp . ' connect-src \'self\' ' . $host . ';';
        });
    }

    private function registerWidget(): void
    {
        $apiUrl = Settings::apiUrl();
        $apiKey = Settings::apiKey();
        if (empty($apiUrl) || empty($apiKey)) {
            return;
        }

        \Eventy::addAction('conversation.after_customer_sidebar', function ($conversation) use ($apiUrl, $apiKey) {
            $customer = $conversation->customer;
            $email = '';
            $customerName = '';
            if ($customer) {
                $email = $conversation->customer_email ?? '';
                if (empty($email) && method_exists($customer, 'getMainEmail')) {
                    $email = (string) $customer->getMainEmail();
                }
                $customerName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
            }

            echo view('flowkom::widget', [
                'apiUrl'           => $apiUrl,
                'apiKey'           => $apiKey,
                'customerEmail'    => $email,
                'customerName'     => $customerName,
                'trackingTemplate' => Settings::trackingTemplate(),
                'trackingOn'       => Settings::featureOn('tracking_reply'),
            ])->render();
        }, 20, 1);
    }
}
