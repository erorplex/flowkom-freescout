<?php

namespace Modules\Flowkom\Services;

/**
 * Chat-Ansicht (Bubble-Darstellung) — CSS-only, kein DOM-Umbau,
 * keine Core-Funktion wird ueberschrieben. Portiert aus ChatViewToggle.
 */
class ChatView
{
    public static function register()
    {

        // Toggle-Button + Styles in der Conversation-Toolbar
        \Eventy::addAction('conversation.action_buttons', function ($conversation, $mailbox) {
            ?>
            <span class="conv-action glyphicon glyphicon-comment cvt-toggle-btn"
                  data-toggle="tooltip"
                  data-placement="bottom"
                  title="<?php echo __('Chat-Ansicht') ?>"
                  aria-label="<?php echo __('Chat-Ansicht') ?>"
                  role="button"></span>
            <style>
                .cvt-toggle-btn.cvt-active { color: #2f9e44 !important; }

                /* ==== Bubble-Grundgeruest ==== */
                body.cvt-chat-mode .thread {
                    padding: 3px 16px !important;
                    margin: 0 !important;
                    border: none !important;
                    background: transparent !important;
                    box-shadow: none !important;
                }
                body.cvt-chat-mode .thread-photo { display: none !important; }

                body.cvt-chat-mode .thread .thread-message {
                    max-width: 68%;
                    min-width: 220px;
                }
                body.cvt-chat-mode .thread .thread-message .thread-content {
                    padding: 10px 14px;
                    border-radius: 16px;
                    box-shadow: 0 1px 1px rgba(20,30,40,.05);
                }

                /* Kunde: links, neutral grau */
                body.cvt-chat-mode .thread.thread-type-customer .thread-message {
                    margin-right: auto; margin-left: 0;
                }
                body.cvt-chat-mode .thread.thread-type-customer .thread-message .thread-content {
                    background: #f1f3f5;
                    border-bottom-left-radius: 6px;
                }

                /* Agent: rechts, blau getoent */
                body.cvt-chat-mode .thread.thread-type-message .thread-message {
                    margin-left: auto; margin-right: 0;
                }
                body.cvt-chat-mode .thread.thread-type-message .thread-message .thread-content {
                    background: #e2eefb;
                    border-bottom-right-radius: 6px;
                }

                /* Notizen: mittig, gelb, etwas kleiner */
                body.cvt-chat-mode .thread.thread-type-note .thread-message {
                    margin: 0 auto; max-width: 56%;
                }
                body.cvt-chat-mode .thread.thread-type-note .thread-message .thread-content {
                    background: #fff9db;
                    border-radius: 12px;
                    font-size: .92em;
                }

                /* Statuszeilen (lineitems): mittig, dezent, ohne Bubble */
                body.cvt-chat-mode .thread.thread-type-lineitem {
                    text-align: center;
                    padding: 2px 16px !important;
                }
                body.cvt-chat-mode .thread.thread-type-lineitem .thread-message {
                    max-width: 100%; min-width: 0;
                    margin: 0 auto;
                }
                body.cvt-chat-mode .thread.thread-type-lineitem .thread-message .thread-content {
                    background: transparent; box-shadow: none;
                    padding: 2px; font-size: .8em; color: #9aa4ae;
                }

                /* Kompakter Kopf: Name + Zeit klein ueber der Bubble */
                body.cvt-chat-mode .thread-header {
                    padding: 0 2px 3px 2px !important;
                    min-height: 0 !important;
                    border-bottom: none !important;
                    font-size: .8em;
                    color: #93a1af;
                    text-align: left;
                }
                body.cvt-chat-mode .thread.thread-type-message .thread-header { text-align: right; }
                body.cvt-chat-mode .thread.thread-type-note .thread-header { text-align: center; }
                body.cvt-chat-mode .thread-header .thread-title { float: none !important; display: inline; }

                /* Alt-Tickets: eBay-/Tracking-Grafiken in der Chat-Ansicht daempfen */
                body.cvt-chat-mode .thread-body img[src*="ebaystatic.com"],
                body.cvt-chat-mode .thread-body img[src*="ebayadservices.com"],
                body.cvt-chat-mode .thread-body img[width="1"] { display: none !important; }
                body.cvt-chat-mode .thread-header .thread-title { font-size: 1em; }
                body.cvt-chat-mode .thread-header .thread-title a { color: #6d7a87; font-weight: 600; }
                body.cvt-chat-mode .thread-header .thread-info { font-size: .95em; }
                /* Empfaenger-Zeile (An/Cc) in der Chat-Ansicht ausblenden */
                body.cvt-chat-mode .thread-recipients { display: none !important; }
                body.cvt-chat-mode .thread-badge { display: none !important; }

                body.cvt-chat-mode .thread-body {
                    padding: 0 !important;
                    font-size: .95em;
                    line-height: 1.45;
                }
                body.cvt-chat-mode .thread-body img { max-width: 100%; height: auto; }
                body.cvt-chat-mode .thread-attachments { padding: 6px 0 0 0 !important; }

                /* Zitierte Historie einklappen */
                body.cvt-chat-mode .thread-body blockquote { display: none; }
                body.cvt-chat-mode .thread-body blockquote.cvt-visible {
                    display: block;
                    font-size: .88em; opacity: .75;
                    margin: 6px 0 0 0; padding: 5px 10px;
                    border-left: 3px solid #cfd6dc;
                }
                .cvt-show-quoted {
                    display: inline-block;
                    font-size: .78em; color: #8a94a0;
                    cursor: pointer;
                    padding: 1px 8px; margin-top: 4px;
                    border: 1px solid #dde3e8; border-radius: 10px;
                    background: #fff;
                    user-select: none;
                }
                .cvt-show-quoted:hover { background: #eef1f4; }

                /* Thread-Menue dezent, erscheint beim Hover */
                body.cvt-chat-mode .thread .dropdown.thread-options { opacity: 0; transition: opacity .15s; }
                body.cvt-chat-mode .thread:hover .dropdown.thread-options { opacity: 1; }
            </style>
            <?php
        }, 20, 2);

        // Toggle-Logik + Zitat-Chips
        \Eventy::addAction('javascript', function () {
            if (!\Route::is('conversations.view')) {
                return;
            }
            echo <<<'JS'
(function() {
    var KEY = 'cvt_chat_mode';

    function setMode(on, btn) {
        document.body.classList.toggle('cvt-chat-mode', on);
        if (btn) btn.classList.toggle('cvt-active', on);
        if (on) addQuoteChips();
        try { localStorage.setItem(KEY, on ? '1' : '0'); } catch (e) {}
    }

    function addQuoteChips() {
        var quotes = document.querySelectorAll('.thread-body blockquote');
        for (var i = 0; i < quotes.length; i++) {
            (function(q) {
                if (q.previousElementSibling && q.previousElementSibling.classList && q.previousElementSibling.classList.contains('cvt-show-quoted')) return;
                if (q.closest('blockquote')) return; /* nur oberste Ebene */
                var chip = document.createElement('span');
                chip.className = 'cvt-show-quoted';
                chip.textContent = '··· Verlauf';
                chip.title = 'Zitierten Verlauf ein-/ausblenden';
                chip.addEventListener('click', function() {
                    q.classList.toggle('cvt-visible');
                });
                q.parentNode.insertBefore(chip, q);
            })(quotes[i]);
        }
    }

    function init() {
        var btn = document.querySelector('.cvt-toggle-btn');
        if (!btn || btn.dataset.cvtBound) return;
        btn.dataset.cvtBound = '1';
        var saved = null;
        try { saved = localStorage.getItem(KEY); } catch (e) {}
        if (saved === '1') setMode(true, btn);
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            setMode(!document.body.classList.contains('cvt-chat-mode'), btn);
        });
    }

    init();
    if (typeof $ !== 'undefined') {
        $(document).on('pjax:end', init);
        /* Neue Threads nach Antwort/Refresh: Chips nachziehen */
        $(document).ajaxComplete(function() {
            if (document.body.classList.contains('cvt-chat-mode')) addQuoteChips();
        });
    }
})();
JS;
        });
        }
}
