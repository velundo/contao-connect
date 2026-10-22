# Änderungen

## 1.1.0 – 2026-10-22

Befunde aus dem Code-Review vom 21.10.2026: Die Admin-Pflicht für Veröffentlichen und Live-Änderungen galt bisher nur in
der velundo-App – mit jedem Token war serverseitig alles möglich.

- **Zusatzrechte je Token:** Ein Token ändert ohne Zusatzrecht nur unveröffentlichte Inhalte und Metadaten.
  `velundo:token create "…" --allow-publish` erlaubt das Veröffentlichen bzw. Sichtbarschalten (Seiten, Inhaltselemente,
  Nachrichten), `--allow-live-edit` das Ändern von Titeln, Überschriften und Texten bereits sichtbarer Datensätze
  (SEO-Felder, Datum und Verbergen bleiben frei). Fehlt das Recht: 403 `scope_publish` bzw. `scope_live_edit` mit
  Hinweis auf das Kommando. `velundo:token list` zeigt die Rechte. Neue Spalte `scopes` in `tl_velundo_token` – nach dem
  Update `contao:migrate` bzw. „Datenbank aktualisieren“ ausführen; bis dahin gelten alle Tokens ohne Zusatzrechte.
  Bestehende Tokens aus 1.0.x haben keine Zusatzrechte – für Veröffentlichungen ein neues Token mit den gewünschten
  Rechten erzeugen.
- **`GET system` knapper:** nur noch Bundles und Pakete von Contao und velundo – keine Inventarliste aller Erweiterungen.
- **Bremse vor der Token-Prüfung:** Mehr als 30 Anfragen ohne gültiges Token je Minute und Absenderadresse → 429
  (Zähler in `tl_velundo_rate`, Adresse nur gehasht).

## 1.0.2 – 2026-10-21

Befund aus dem Code-Review vom 21.10.2026.

- **Sperre für Insert-Tags am Endergebnis:** Die Prüfung lief vor der Bereinigung. Ein Kommentar oder ein nicht erlaubtes
  Element zwischen den Klammern („{<!-- -->{insert_module::3}}“) verbarg das Tag, und erst das Entfernen setzte es zusammen –
  Contao hätte es beim Rendern ausgeführt. Jetzt wird nach der Grobreinigung und nach der Positivliste erneut geprüft.
- **Grobreinigung:** „/“ gilt als Trenner vor Attributen („<img/onerror=…>“), angefangene Tags ohne „>“ werden als Text
  entwertet.

## 1.0.1 – 2026-10-12

Befund aus dem Livetest mit Contao 6.0.3.

- **Protokoll nur für Änderungen:** Jeder Aufruf – auch jeder Lesezugriff – stand bisher im Contao-Protokoll, Änderungen
  doppelt. Jetzt nur noch Änderungen (Tabelle, ID, Felder) und abgelehnte Schreibversuche; regelmäßige Abfragen fluten
  die Systemmeldungen nicht mehr.

## 1.0.0 – 2026-10-12

Erste Fassung nach Schnittstellenvertrag v1 (`Documentation/API.md`).

- Route `/_velundo/{path}` außerhalb von Contao-Frontend und -Backend (keine Firewall, keine Session, keine CSRF-Prüfung):
  Token (`Authorization: Bearer` oder `X-Velundo-Token`), HTTPS-Pflicht (außer `APP_ENV=dev`), Ratenbegrenzung je Token
  (Standard 120/min, 429 mit `Retry-After`), einheitliches Fehlerformat, Köpfe `X-Velundo-Api: 1` und `Cache-Control: no-store`.
- Tokens nur als SHA-256-Hash in `tl_velundo_token`, Zähler in `tl_velundo_rate` (DCA unter `contao/dca/`, Anlage mit
  `contao:migrate`); Konsole `velundo:token create|list|revoke`.
- Lesen: `info`, `system`, `pages` (Baum mit `root`, `parent`, `depth`, `search`, `visibility`), `pages/{id}` mit Artikeln,
  `articles?page=`, `content?article=|news=`, `content/{id}`, `news_archives`, `news`, `news/{id}` mit Textelementen,
  `seo/overview`, `search?q=`; Adressen über den Content-URL-Generator.
- Schreiben direkt in der Datenbank mit `tstamp`, Version (`Contao\Versions`, Benutzer „velundo“), Protokoll (`tl_log`,
  Kanal `contao.general`) und Cache-Tags: `PATCH pages/{id}`, `POST/PATCH content` (neu immer unsichtbar),
  `POST/PATCH news` (neu immer unveröffentlicht, Text als Inhaltselement), `POST cache/clear`; jede Antwort mit `before`.
- Klartextfelder kodiert wie das Backend (`Input::encodeInput`), HTML über eigene Grobreinigung und `Input::stripTags()`
  mit den erlaubten Tags der Installation; Insert-Tags nur als Verweise.
- Contao 5.3 und 6 (`contao/core-bundle ^5.3 || ^6.0`, Symfony 6.4–8, DBAL 3/4): Klartextfelder unter Contao 6 roh
  gespeichert (Ausgabe-Escaping), Cache-Tags unter Contao 6 über `contao.cache.tag_manager`; DCA in Array-Schreibweise.
- Prüfungen: `Tests/Unit` und `Tests/smoke.php` ohne Contao, die Endpunkte gegen SQLite (auch im Modus Contao 6).
