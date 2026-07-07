<form class="form-horizontal margin-top margin-bottom" method="POST" action="">
    {{ csrf_field() }}

    <h4 class="margin-bottom">{{ __('Verbindung zu Flowkom') }}</h4>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('API-URL') }}</label>
        <div class="col-sm-6">
            <input type="url" class="form-control input-sized-lg" name="settings[flowkom.api_url]" value="{{ $settings['flowkom.api_url'] ?? '' }}" placeholder="https://app.flowkom.de">
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('API-Key') }}</label>
        <div class="col-sm-6">
            <input type="password" class="form-control input-sized-lg" name="settings[flowkom.api_key]" value="{{ ($settings['flowkom.api_key'] ?? '') ? '********' : '' }}" placeholder="{{ __('API-Key eingeben') }}" autocomplete="new-password">
            <p class="form-help">{{ __('Leer lassen, um den gespeicherten Key zu behalten.') }} <a href="#" id="flowkom-test-btn">{{ __('Verbindung testen') }}</a> <span id="flowkom-test-result"></span></p>
        </div>
    </div>

    <hr>
    <h4 class="margin-bottom">{{ __('Funktionen') }}</h4>

    @foreach ($features as $key => $label)
        <div class="form-group">
            <label class="col-sm-2 control-label" for="flowkom-f-{{ $key }}"></label>
            <div class="col-sm-8">
                <div class="controls">
                    <div class="onoffswitch-wrap" style="display:inline-block;vertical-align:middle;">
                        <div class="onoffswitch">
                            <input type="checkbox" class="onoffswitch-checkbox" id="flowkom-f-{{ $key }}" name="settings[flowkom.feature_{{ $key }}]" value="1" @if ($settings['flowkom.feature_'.$key] ?? true) checked @endif>
                            <label class="onoffswitch-label" for="flowkom-f-{{ $key }}"></label>
                        </div>
                    </div>
                    <span style="margin-left:10px;vertical-align:middle;">{{ __($label) }}</span>
                </div>
            </div>
        </div>
    @endforeach

    <hr>
    <h4 class="margin-bottom">{{ __('Marktplätze') }}</h4>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('eBay-Domain') }}</label>
        <div class="col-sm-6">
            <input type="text" class="form-control input-sized" name="settings[flowkom.ebay_domain]" value="{{ $settings['flowkom.ebay_domain'] ?? 'ebay.de' }}">
            <p class="form-help">{{ __('Für QuickLinks, z. B. ebay.de oder ebay.com') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Seller-Central-Domain') }}</label>
        <div class="col-sm-6">
            <input type="text" class="form-control input-sized" name="settings[flowkom.sc_domain]" value="{{ $settings['flowkom.sc_domain'] ?? 'sellercentral.amazon.de' }}">
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Merge: max. Alter (Tage)') }}</label>
        <div class="col-sm-6">
            <input type="number" class="form-control input-sized" name="settings[flowkom.merge_max_age_days]" value="{{ $settings['flowkom.merge_max_age_days'] ?? 60 }}" min="1" max="365">
            <p class="form-help">{{ __('Wie alt darf ein bestehendes Ticket maximal sein, um noch zusammengeführt zu werden.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Postfächer') }}</label>
        <div class="col-sm-6">
            @foreach ($mailbox_options as $id => $label)
                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="settings[flowkom.mailbox_ids][]" value="{{ $id }}" @if (in_array($id, $settings['flowkom.mailbox_ids'] ?? [])) checked @endif>
                        {{ $label }}
                    </label>
                </div>
            @endforeach
            <p class="form-help">{{ __('Auf welche Postfächer Merger & Cleaner wirken. NICHTS auswählen = alle Postfächer (empfohlen, funktioniert auch mit einem Sammel-Postfach wie info@ — Marktplatz-Mails werden am Absender erkannt).') }}</p>
        </div>
    </div>

    <hr>
    <h4 class="margin-bottom">{{ __('Brainflow-Import') }}</h4>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Zielseite für Importe') }}</label>
        <div class="col-sm-6">
            <input type="text" class="form-control input-sized" name="settings[flowkom.brainflow_parent_title]" value="{{ $settings['flowkom.brainflow_parent_title'] ?? 'Helpdesk-Importe' }}" placeholder="Helpdesk-Importe">
            <p class="form-help">{{ __('Sammelseite in Brainflow, unter der gespeicherte Tickets landen (wird pro Nutzer automatisch angelegt). Leer lassen = oberste Ebene.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Anhänge übernehmen') }}</label>
        <div class="col-sm-6">
            <select class="form-control input-sized" name="settings[flowkom.brainflow_attachments]">
                <option value="none" @if (($settings['flowkom.brainflow_attachments'] ?? 'none') == 'none') selected @endif>{{ __('Nur Ticket-Link (keine Dateien kopieren)') }}</option>
                <option value="images" @if (($settings['flowkom.brainflow_attachments'] ?? 'none') == 'images') selected @endif>{{ __('Bilder übernehmen') }}</option>
                <option value="all" @if (($settings['flowkom.brainflow_attachments'] ?? 'none') == 'all') selected @endif>{{ __('Alle Dateien übernehmen') }}</option>
            </select>
            <p class="form-help">{{ __('Ob Ticket-Anhänge in die Brainflow-Seite kopiert werden. Der Link zum FreeScout-Ticket ist immer enthalten.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Eigenschaften in der Sammlung') }}</label>
        <div class="col-sm-6">
            @foreach ($brainflow_prop_fields as $propKey => $propLabel)
                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="settings[flowkom.brainflow_prop_{{ $propKey }}]" value="1" @if ($settings['flowkom.brainflow_prop_'.$propKey] ?? true) checked @endif>
                        {{ __($propLabel) }}
                    </label>
                </div>
            @endforeach
            <p class="form-help">{{ __('Wenn eine Zielseite gesetzt ist, wird sie automatisch zu einer Sammlung (Tabelle). Diese Felder werden dann als Spalten pro Ticket befüllt. Ohne Zielseite (oberste Ebene) ist diese Einstellung ohne Wirkung.') }}</p>
        </div>
    </div>

    <hr>
    <h4 class="margin-bottom">{{ __('Tracking-Antwort') }}</h4>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Vorlage') }}</label>
        <div class="col-sm-6">
            <textarea class="form-control" name="settings[flowkom.tracking_reply_template]" rows="8">{{ $settings['flowkom.tracking_reply_template'] ?? '' }}</textarea>
            <p class="form-help">{{ __('Platzhalter:') }} <code>&#123;carrier&#125;</code> <code>&#123;tracking_number&#125;</code> <code>&#123;tracking_url&#125;</code> <code>&#123;order_number&#125;</code></p>
        </div>
    </div>

    <div class="form-group margin-top">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('Speichern') }}</button>
        </div>
    </div>
</form>

<script {!! \Helper::cspNonceAttr() !!}>
(function() {
    var btn = document.getElementById('flowkom-test-btn');
    var out = document.getElementById('flowkom-test-result');
    if (!btn) return;
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        out.textContent = '…';
        fetch('{{ route('flowkom.test') }}', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('input[name=_token]').value, 'Accept': 'application/json' }
        })
        .then(function(r) { return r.json(); })
        .then(function(d) { out.textContent = (d.success ? '✅ ' : '❌ ') + d.message; })
        .catch(function() { out.textContent = '❌ Test fehlgeschlagen'; });
    });
})();
</script>
