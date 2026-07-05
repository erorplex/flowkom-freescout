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

        // Mail-Pipeline: erst Merger (Prio 20), dann Cleaner (Prio 25) —
        // die Merger matchen auf Betreff/Absender, der Cleaner ersetzt danach
        // den Body. Beides fail-open: jeder Fehler => Mail bleibt unverändert.
        \Eventy::addFilter('fetch_emails.data_to_save', function ($data) {
            try {
                return Mergers::apply($data);
            } catch (\Throwable $e) {
                \Helper::log(FLOWKOM_MODULE, 'Merger-ERROR (kein Merge): ' . $e->getMessage());
                return $data;
            }
        }, 20, 1);

        \Eventy::addFilter('fetch_emails.data_to_save', function ($data) {
            try {
                if (!Settings::featureOn('cleaner')) {
                    return $data;
                }
                if (empty($data['message_from_customer']) || empty($data['from']) || empty($data['body'])) {
                    return $data;
                }
                $clean = MailCleaner::clean($data['body'], $data['from']);
                if ($clean !== null) {
                    $data['body'] = $clean;
                    \Helper::log(FLOWKOM_MODULE, 'Mail bereinigt: ' . mb_substr((string) ($data['subject'] ?? ''), 0, 120));
                }
            } catch (\Throwable $e) {
                \Helper::log(FLOWKOM_MODULE, 'Cleaner-ERROR (Body unverändert): ' . $e->getMessage());
            }
            return $data;
        }, 25, 1);

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
