<style {!! \Helper::cspNonceAttr() !!}>
#flowkom-widget{margin-top:10px;border:1px solid #d6dce2;border-radius:4px;overflow:hidden;font-size:12px}
#flowkom-widget .fk-hd{padding:7px 10px;background:linear-gradient(135deg,#1e40af,#3b82f6);display:flex;align-items:center;justify-content:space-between}
#flowkom-widget .fk-hd span{color:#fff;font-weight:600;font-size:11px;letter-spacing:.5px}
#flowkom-widget .fk-hd button{background:rgba(255,255,255,.2);border:0;border-radius:3px;color:#fff;font-size:11px;padding:2px 8px;cursor:pointer}
#flowkom-widget .fk-sec{padding:8px 10px;background:#fff;border-top:1px solid #e5e8ed;color:#333}
#flowkom-widget .fk-lbl{font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
#flowkom-widget .fk-hl{border-left:3px solid #3b82f6;background:#eff6ff}
#flowkom-widget .fk-badge{display:inline-block;font-size:10px;padding:1px 6px;border-radius:10px;font-weight:600;background:#dcfce7;color:#166534}
#flowkom-widget .fk-links{display:flex;gap:4px;margin-top:6px}
#flowkom-widget .fk-links a{flex:1;display:block;text-align:center;padding:4px 0;border-radius:3px;font-size:10px;text-decoration:none;color:#fff;font-weight:500}
#flowkom-widget .fk-lnk-f{background:#1e40af}
#flowkom-widget .fk-lnk-m{background:#ff9900}
#flowkom-widget .fk-trk a{font-size:11px;color:#0068c8;text-decoration:none;word-break:break-all}
#flowkom-widget .fk-err{padding:12px 10px;text-align:center;color:#888;font-size:12px}
</style>

<div id="flowkom-widget">
    <div class="fk-hd">
        <span>FLOWKOM</span>
        <button id="fk-refresh" type="button">&orarr;</button>
    </div>
    <div class="fk-sec" id="fk-content">Lade Daten...</div>
</div>

<script {!! \Helper::cspNonceAttr() !!}>
(function(){
    var API_URL = {!! json_encode($apiUrl) !!};
    var API_KEY = {!! json_encode($apiKey) !!};
    var EMAIL   = {!! json_encode($customerEmail) !!};
    var CUSTOMER_NAME = {!! json_encode($customerName ?? '') !!};
    var TRACKING_TPL  = {!! json_encode($trackingTemplate ?? '') !!};
    var TRACKING_ON   = {!! json_encode(!empty($trackingOn)) !!};
    var w = document.getElementById('flowkom-widget');
    var content = document.getElementById('fk-content');

    document.getElementById('fk-refresh').addEventListener('click', loadData);
    loadData();

    function loadData() {
        content.textContent = 'Lade Daten...';
        content.className = 'fk-sec';

        var orderNum = null;
        // Subject NUR aus spezifischen Betreff-Elementen lesen.
        // NICHT document.title oder h1/h2 verwenden — die enthalten FreeScouts
        // Konversationsnummer (#60358), die fälschlich das Shopify-Pattern matcht.
        var subjParts = [];
        var subjEls = document.querySelectorAll('.conv-subject, .conv-subj, .conversation-subject, #conv-subject, .thread-title');
        for (var s = 0; s < subjEls.length; s++) {
            var t = (subjEls[s].textContent || '').trim();
            if (t) subjParts.push(t);
        }
        var subj = subjParts.join(' ');
        var patterns = [
            /(\d{3}-\d{7}-\d{7})/,               // Amazon
            /(\d{2}-\d{5}-\d{5})/,               // eBay
            /Auftrags-Nr\.?\s+([a-z0-9]{8,})/i,  // Otto
            /#(\d{4,})/                          // Shopify/numerisch
        ];
        for (var p = 0; p < patterns.length; p++) {
            var m = subj.match(patterns[p]);
            if (m) { orderNum = m[1]; break; }
        }

        // Extract eBay username from customer name (pattern: "eBay - username")
        var ebayUser = null;
        if (CUSTOMER_NAME) {
            var ebayMatch = CUSTOMER_NAME.match(/^eBay\s*-\s*(.+)$/i);
            if (ebayMatch) ebayUser = ebayMatch[1].trim();
        }

        var params = [];
        if (EMAIL) params.push('email=' + encodeURIComponent(EMAIL));
        if (orderNum) params.push('order_number=' + encodeURIComponent(orderNum));
        if (ebayUser) params.push('ebay_username=' + encodeURIComponent(ebayUser));
        if (!params.length) { showMsg('Keine E-Mail gefunden.'); return; }

        fetch(API_URL + '/api/freescout/lookup?' + params.join('&'), {
            headers: { 'Authorization': 'Bearer ' + API_KEY }
        })
        .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(renderData)
        .catch(function(err) { showMsg('Fehler: ' + err.message); });
    }

    function showMsg(msg) {
        clearW();
        var d = mk('div','fk-err'); d.textContent = msg; w.appendChild(d);
    }

    function clearW() {
        while (w.children.length > 1) w.removeChild(w.lastChild);
    }

    function mk(tag, cls) { var e = document.createElement(tag); if (cls) e.className = cls; return e; }
    function sl(s) { return {open:'Offen',in_progress:'In Bearbeitung',shipped:'Versendet',completed:'Abgeschlossen',cancelled:'Storniert',pending:'Ausstehend',picked:'Gepickt'}[s]||s; }
    function cl(c) { return {amazon:'Amazon',shopify:'Shopify',ebay:'eBay',kaufland:'Kaufland',manual:'Manuell'}[c]||c; }
    function fd(d) { return d ? new Date(d).toLocaleDateString('de-DE') : ''; }

    function renderData(data) {
        clearW();
        if (!data.customer && !(data.orders||[]).length) { showMsg('Kein Kunde in Flowkom gefunden.'); return; }

        if (data.customer) {
            var cs = mk('div','fk-sec');
            var lb = mk('div','fk-lbl'); lb.textContent = 'Kunde'; cs.appendChild(lb);
            var nm = mk('div'); nm.style.fontWeight = '600'; nm.textContent = data.customer.name||''; cs.appendChild(nm);
            if (data.customer.address) {
                var a = data.customer.address, ad = mk('div');
                ad.style.cssText = 'color:#555;font-size:11px;line-height:1.4';
                ad.textContent = [a.street,[a.zip,a.city].filter(Boolean).join(' '),a.country].filter(Boolean).join(', ');
                cs.appendChild(ad);
            }
            if (data.customer.phone) { var ph = mk('div'); ph.style.cssText='color:#555;font-size:11px'; ph.textContent = data.customer.phone; cs.appendChild(ph); }
            var tc = mk('div'); tc.style.cssText='margin-top:3px;font-size:10px;color:#888'; tc.textContent = (data.customer.total_orders||0)+' Bestellungen'; cs.appendChild(tc);
            w.appendChild(cs);
        }

        var orders = data.orders||[], hl = data.highlighted_order, main = null, rest = [];
        for (var i = 0; i < orders.length; i++) {
            if (hl && orders[i].order_number === hl && !main) main = orders[i]; else rest.push(orders[i]);
        }
        if (!main && orders.length) { main = orders[0]; rest = orders.slice(1); }
        if (main) w.appendChild(renderOrder(main));
        if (rest.length) {
            var ms = mk('div','fk-sec'), det = document.createElement('details'), sum = document.createElement('summary');
            sum.style.cssText='cursor:pointer;font-size:11px;color:#555;font-weight:500';
            sum.textContent = 'Weitere ('+rest.length+')'; det.appendChild(sum);
            for (var r=0;r<rest.length;r++) det.appendChild(renderCompact(rest[r]));
            ms.appendChild(det); w.appendChild(ms);
        }
    }

    function renderOrder(o) {
        var s = mk('div','fk-sec fk-hl');
        var hr = mk('div'); hr.style.cssText='display:flex;justify-content:space-between;align-items:center;margin-bottom:3px';
        var lb = mk('div','fk-lbl'); lb.textContent='Aktuelle Bestellung'; hr.appendChild(lb);
        var bg = mk('span','fk-badge'); bg.textContent=sl(o.status); hr.appendChild(bg); s.appendChild(hr);
        var nm = mk('div'); nm.style.cssText='font-size:12px;font-weight:600;color:#1e40af'; nm.textContent=o.order_number||''; s.appendChild(nm);
        var mt = mk('div'); mt.style.cssText='font-size:10px;color:#888;margin-bottom:5px'; mt.textContent=cl(o.channel)+' \u00B7 '+fd(o.created_at); s.appendChild(mt);
        (o.items||[]).forEach(function(it){ var d=mk('div'); d.style.cssText='font-size:11px;padding:1px 0'; d.textContent=it.quantity+'\u00D7 '+(it.name||''); s.appendChild(d); });
        (o.shipments||[]).forEach(function(sh){
            var tr=mk('div','fk-trk'); tr.style.marginTop='3px';
            var c=mk('span'); c.style.fontWeight='600'; c.textContent=(sh.carrier||'')+': '; tr.appendChild(c);
            if(sh.tracking_url){var a=document.createElement('a');a.setAttribute('href',sh.tracking_url);a.setAttribute('target','_blank');a.textContent=sh.tracking_number;tr.appendChild(a);}
            else{var tn=mk('span');tn.textContent=sh.tracking_number||'';tr.appendChild(tn);}
            s.appendChild(tr);
        });
        if (TRACKING_ON && (o.shipments||[]).length && o.shipments[0].tracking_number) {
            var tb=mk('button','fk-track-reply'); tb.type='button';
            tb.style.cssText='margin-top:6px;width:100%;padding:5px 0;border:0;border-radius:3px;background:#16a34a;color:#fff;font-size:11px;font-weight:600;cursor:pointer';
            tb.textContent='\u2709 Antwort mit Tracking einf\u00fcgen';
            tb.addEventListener('click', function(){ fkInsertTracking(o); });
            s.appendChild(tb);
        }
        var lk=mk('div','fk-links');
        if(o.flowkom_url){var a1=document.createElement('a');a1.className='fk-lnk-f';a1.setAttribute('href',o.flowkom_url);a1.setAttribute('target','_blank');a1.textContent='Flowkom';lk.appendChild(a1);}
        if(o.marketplace_url){var a2=document.createElement('a');a2.className='fk-lnk-m';a2.setAttribute('href',o.marketplace_url);a2.setAttribute('target','_blank');a2.textContent=cl(o.channel);lk.appendChild(a2);}
        s.appendChild(lk);
        return s;
    }

    function renderCompact(o) {
        var d=mk('div'); d.style.cssText='padding:4px 0;border-top:1px solid #eee;margin-top:3px';
        var r=mk('div'); r.style.cssText='display:flex;justify-content:space-between;align-items:center';
        var n=mk('span'); n.style.cssText='font-size:11px;font-weight:600'; n.textContent=o.order_number||''; r.appendChild(n);
        var b=mk('span','fk-badge'); b.style.fontSize='9px'; b.textContent=sl(o.status); r.appendChild(b); d.appendChild(r);
        var m=mk('div'); m.style.cssText='font-size:10px;color:#888'; m.textContent=cl(o.channel)+' \u00B7 '+fd(o.created_at); d.appendChild(m);
        return d;
    }
    /* Tracking-Antwort in den Reply-Editor einfuegen (vor der Signatur). */
    function fkInsertTracking(o) {
        var sh = (o.shipments||[])[0] || {};
        var carrier = sh.carrier || 'dem Versanddienstleister';
        if (carrier.length <= 4) { carrier = carrier.toUpperCase(); }
        else { carrier = carrier.charAt(0).toUpperCase() + carrier.slice(1); }
        var text = TRACKING_TPL
            .replace(/\{carrier\}/g, carrier)
            .replace(/\{tracking_number\}/g, sh.tracking_number || '')
            .replace(/\{tracking_url\}/g, sh.tracking_url || '')
            .replace(/\{order_number\}/g, o.order_number || '');
        var esc = document.createElement('div'); esc.textContent = text;
        var html = '<div>' + esc.innerHTML.replace(/\n/g, '<br>') + '</div><br>';

        function insert() {
            if (typeof $ === 'undefined' || !$('#body').length || !$('.note-editable:visible').length) return false;
            var cur = $('#body').summernote('code') || '';
            $('#body').summernote('code', html + cur);
            return true;
        }
        if (insert()) return;
        /* Reply-Formular erst oeffnen, dann einfuegen */
        var replyBtn = document.querySelector('.conv-reply');
        if (replyBtn) replyBtn.click();
        var tries = 0;
        var iv = setInterval(function() {
            if (insert() || ++tries > 20) clearInterval(iv);
        }, 250);
    }
})();
</script>
