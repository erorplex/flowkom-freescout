<?php

namespace Modules\Flowkom\Services;

/**
 * Darstellungs-Fixes fuer HTML-Mails aller Absender (breite Newsletter,
 * MJML-Layouts, Tracking-Pixel). Portiert aus dem frueheren
 * EmailDisplayFix-Modul.
 */
class DisplayFix
{
    public static function register()
    {
        \Eventy::addAction('layout.head', function () {
            echo view('flowkom::displayfix-css')->render();
        });

        // HTMLPurifier: align-Attribut auf <table> erlauben
        $currentAllowed = config('purifier.settings.default.HTML.Allowed');
        if ($currentAllowed) {
            config(['purifier.settings.default.HTML.Allowed' => str_replace(
                'table[style|border|bgcolor|cellspacing|cellpadding|border|width|class]',
                'table[style|border|bgcolor|cellspacing|cellpadding|border|width|class|align]',
                $currentAllowed
            )]);
        }

        // HTMLPurifier: zusaetzliche CSS-Properties erlauben
        $currentCss = config('purifier.settings.default.CSS.AllowedProperties');
        if ($currentCss) {
            config(['purifier.settings.default.CSS.AllowedProperties' => $currentCss
                . ',line-height,vertical-align,border-spacing,border-collapse,min-width,min-height,box-sizing,border-width,border-style,table-layout,text-indent,list-style,list-style-type']);
        }
    }
}
