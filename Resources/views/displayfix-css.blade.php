<style {!! \Helper::cspNonceAttr() !!}>
/* EmailDisplayFix: E-Mail-Darstellung fuer breite E-Mails (Amazon, Temu, eBay etc.) */
.thread-body {
    overflow-x: auto !important;
}
.thread-content {
    overflow-wrap: break-word;
    word-wrap: break-word;
}
/* inline-block Divs in Tabellenzellen auf block setzen - verhindert Sizing-Loop bei MJML-Emails */
.thread-content td > div {
    display: block !important;
}
/* height:100% auf Tabellen entfernen - verhindert Kollaps in FreeScouts Layout */
.thread-content table {
    height: auto !important;
}
/* overflow:hidden auf Tabellen entfernen - E-Mails nutzen das fuer border-radius, aber es clippt Content */
.thread-content table[style*="overflow"] {
    overflow: visible !important;
}
.thread-content img {
    max-width: 100% !important;
    height: auto !important;
}
/* Tracking-Pixel (1x1 Bilder) ausblenden */
.thread-content img[width="1"],
.thread-content img[height="1"] {
    display: none !important;
}
.thread-content pre {
    white-space: pre-wrap;
    word-break: break-word;
}
</style>
