# Flowkom für FreeScout

Ein Modul, volles Paket: verbindet FreeScout mit Flowkom und macht Marktplatz-Support (eBay/Amazon) schnell und sauber.

## Funktionen (alle einzeln zuschaltbar)

| Funktion | Beschreibung |
|---|---|
| **Flowkom-Widget** | Kundendaten, Bestellungen & Tracking aus Flowkom in der Ticket-Sidebar |
| **QuickLinks** | Ein Klick zu: Bestellung im eBay Seller Hub / Amazon Seller Central, eBay-Käuferkonversation, Artikelseite |
| **Mail-Cleaner** | Blendet den Template-Müll aus eBay-/Amazon-Mails **nur in der Ticket-Ansicht** aus — Ticket zeigt nur Kundennachricht + Bestelldaten, Käuferfotos bleiben erhalten. **Wichtig:** der gespeicherte Body bleibt IMMER das Original (nur so bleiben eBays versteckte Zustell-Marker in der zitierten Historie erhalten; ein ersetzter Body führte zu Bounces — nie wieder einen fetch-time-Body-Ersatz einbauen). Fail-open: Unbekanntes bleibt unverändert |
| **eBay Ticket-Merger** | Folgenachrichten desselben Käufers zum selben Artikel landen im selben Ticket |
| **Amazon Ticket-Merger** | Mails zur selben Bestellnummer landen im selben Ticket |
| **Chat-Ansicht** | Umschaltbare Bubble-Ansicht pro Ticket (Sprechblasen-Symbol in der Toolbar) |
| **Tracking-Antwort** | Ein Klick im Widget fügt eine fertige Antwort mit Trackingdaten in den Editor ein (Vorlage anpassbar) |
| **Brainflow-Import** | „In Brainflow sichern" im Ticket: speichert den kompletten Ticket-Verlauf als Brainflow-Seite in Flowkom und öffnet sie im neuen Tab. Die Seite gehört dem Flowkom-User, der den Import (eingeloggt) abschließt — nicht dem API-Key. Zielseite + Anhangs-Übernahme (keine/Bilder/alle) konfigurierbar. Sichtbar nur, wenn Brainflow im Flowkom-Workspace aktiviert ist (Voraussetzung: Flowkom-Deploy mit PROJ-588) |

## Installation

1. Ordner `Flowkom` nach `<freescout>/Modules/` hochladen.
2. In FreeScout: **Verwalten → Module → Flowkom → Aktivieren**.
3. **Einstellungen → Flowkom**: Flowkom-API-URL + API-Key eintragen, „Verbindung testen".
4. Fertig — alle Funktionen sind standardmäßig aktiv.

## Ein Postfach für alles? Kein Problem.

Die Marktplatz-Erkennung läuft über den **Absender** (`…@members.ebay.de`, `…@marketplace.amazon.de`), nicht über das Postfach. Das Modul funktioniert daher auch, wenn alle Marktplätze über ein einziges Sammel-Postfach (z. B. `info@`) laufen. Optional lässt sich die Verarbeitung in den Einstellungen auf bestimmte Postfächer einschränken.

## Sicherheit / Design-Entscheidungen

- Merge nur bei eindeutiger Zuordnung (Käufer **und** Artikel bzw. Bestellnummer) — ein Fehl-Merge an den falschen Kunden ist ausgeschlossen.
- Mail-Pipeline komplett fail-open: Jeder interne Fehler führt zu einem normalen, unveränderten Ticket. Mails können nie verloren gehen.
- QuickLinks werden kanonisch aus Bestelldaten gebaut — keine tokenisierten Links aus Mails (Ablauf/Leak-Gefahr).
- Der Flowkom-API-Key sollte in Flowkom auf den FreeScout-Lookup-Endpoint beschränkt sein.

## Updates

Das Modul nutzt FreeScouts nativen Update-Mechanismus: Sobald eine neue Version veröffentlicht ist, erscheint unter **Verwalten → Module** automatisch der Hinweis „Eine neue Version ist verfügbar" mit Update-Button — ein Klick, fertig. Kein FTP nötig.

### Release veröffentlichen (intern)

Kanal ist das öffentliche GitHub-Repo `erorplex/flowkom-freescout`. `module.json` zeigt mit `latestVersionUrl`/`latestVersionZipUrl` auf `raw main/module.json` bzw. `releases/latest/download/Flowkom.zip`.

1. `version` in `module.json` erhöhen (Bugfix = Patch, Feature = Minor).
2. Committen und Tag `vX.Y.Z` pushen (Tag-Version **muss** der `module.json`-Version entsprechen).
3. Die GitHub-Action `Release` baut daraus automatisch `Flowkom.zip` (Ordner `Flowkom/` im Root) und legt das Release an — kein manueller FTP-Upload mehr.

Bestehende Instanzen sehen das Update dann unter **Verwalten → Module**. (Der alte Kanal `helpdesk.pixkom.com/fs-modules/flowkom/` diente nur als Migrations-Brücke und ist abgelöst.)

## Kompatibilität

FreeScout ≥ 1.8.0. Ersetzt die früheren Einzelmodule AmazonTicketMerger, EbayTicketMerger, MarketplaceMailCleaner, ChatViewToggle und FlowkomConnector — diese vor/nach Aktivierung deaktivieren. Bestehende Einstellungen (API-URL/-Key) werden automatisch übernommen.

## Roadmap

- Bestellstatus-Badge in der Ticketliste
- Auto-Tagging nach Anliegen (Versandstatus, Retoure, Produktfrage)
- Kaufland-/Otto-Support in Cleaner & QuickLinks
