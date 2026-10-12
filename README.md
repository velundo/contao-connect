# velundo connect – Contao-Bundle `velundo/contao-connect`

Macht eine Contao-Installation für die velundo-App erreichbar. Die App liest darüber Seiten, Artikel, Inhaltselemente,
SEO-Felder und Nachrichten (contao/news-bundle) und ändert sie – gezielt, mit Version und Protokoll, nie löschend.
Verbindlich ist der Schnittstellenvertrag in `Documentation/API.md`.

- Composer-Paket `velundo/contao-connect`, Typ `contao-bundle`, Namespace `Velundo\ContaoConnect`
- Contao 5.3 LTS oder neuer, auch Contao 6 (`contao/core-bundle ^5.3 || ^6.0`), PHP ≥ 8.2, Lizenz LGPL-3.0-or-later
- Endpunktbasis: `https://<host>/_velundo/v1/` (läuft Contao in einem Unterverzeichnis: `https://<host>/<pfad>/_velundo/v1/`)

## Installation

Über die Kommandozeile:

```bash
composer require velundo/contao-connect
vendor/bin/contao-console contao:migrate        # legt die Tabellen tl_velundo_token und tl_velundo_rate an
vendor/bin/contao-console velundo:token create "Agentur Test"
```

Über den Contao Manager: Paket `velundo/contao-connect` suchen und installieren, danach im Manager
„Datenbank aktualisieren“ (entspricht `contao:migrate`). Das Token muss trotzdem auf der Kommandozeile erzeugt werden
(`vendor/bin/contao-console velundo:token create "…"`); ohne SSH-Zugang erledigt das, wer die Website betreut.

Das Token wird genau einmal angezeigt. In der velundo-App wird es zusammen mit der Adresse der Website als Verbindung
eingetragen. In Contao bleibt nur der SHA-256-Hash gespeichert.

Weitere Kommandos:

```bash
vendor/bin/contao-console velundo:token list          # Kennung, Bezeichnung, Erzeugt, Zuletzt verwendet, Status
vendor/bin/contao-console velundo:token revoke <Kennung>   # Token widerrufen (sofort wirksam)
```

Prüfen, ob die Schnittstelle antwortet:

```bash
curl -s -H "Authorization: Bearer <token>" https://<host>/_velundo/v1/info
```

Ohne Token antwortet jeder Pfad unter `/_velundo/` mit `401` – ohne Versionsangaben, auch über `http` (die Token-Prüfung
kommt vor der HTTPS-Prüfung; erst mit gültigem Token folgt über `http` die 403 `https_required`).

### Warum eine eigene Tabelle für die Tokens

Contao hat keinen allgemeinen Schlüssel-Wert-Speicher wie die `sys_registry` von TYPO3. Eine eigene Tabelle mit
DCA-Definition (`contao/dca/tl_velundo_token.php`) ist der für Contao übliche Weg: `contao:migrate` bzw. der Contao Manager
legen sie an und halten sie aktuell, sie liegt in derselben Datenbank (Sicherungen und Umzüge nehmen sie mit), der Hash
ist eindeutig indiziert, und jede Änderung betrifft nur ihre Spalten – eine Anfrage, die „zuletzt verwendet“ vermerkt,
kann einen gleichzeitigen Widerruf nicht überschreiben. Dateien im Projektordner (z. B. unter `var/`) gingen bei
Deployments leicht verloren, Einträge in `localconfig` oder `config/` sind für Geheimnisse ungeeignet. Die Zähler der
Ratenbegrenzung stehen aus denselben Gründen in `tl_velundo_rate` (atomar erhöht, alte Minutenfenster räumt das Bundle
selbst weg). Beide Tabellen sind im Backend nicht bearbeitbar (`closed`, `notEditable`).

### Einstellungen (`config/config.yaml`)

```yaml
velundo_contao_connect:
    max_text_kb: 200            # Höchstgröße von text und teaser; größer → 422 text_too_large
    rate_limit_per_minute: 120  # Anfragen je Minute und Token; darüber 429 mit Retry-After
    require_https: true         # http → 403 https_required (in APP_ENV=dev immer erlaubt)
```

### Betrieb hinter CGI/FastCGI

Viele Hoster (Apache mit PHP-FPM) geben den Kopf `Authorization` nicht an PHP weiter. Das Token gilt deshalb ersatzweise
im Kopf `X-Velundo-Token: <token>` (eigene Köpfe erreichen PHP immer); die velundo-App sendet beide. Trägt `Authorization`
etwas anderes – etwa einen HTTP-Basic-Schutz vor der Website –, zählt ebenfalls `X-Velundo-Token`. Symfony liest
`Authorization` zusätzlich aus `HTTP_AUTHORIZATION` und `REDIRECT_HTTP_AUTHORIZATION`. Für andere Programme, die nur
`Authorization` senden, hilft in der `.htaccess` `CGIPassAuth On` (Apache ab 2.4.13) oder:

```apache
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

### Hinter einem Reverse-Proxy

Beendet ein Proxy die TLS-Verbindung, erkennt die HTTPS-Prüfung die Anfrage nur als sicher, wenn Symfony dem Proxy
vertraut (`TRUSTED_PROXIES` in `.env.local`, z. B. `TRUSTED_PROXIES=10.0.0.5`, und `X-Forwarded-Proto`). Ohne diese
Einstellung antwortet die Schnittstelle mit 403 `https_required`; `require_https: false` ist nur ein Notnagel für
geschlossene Netze.

## Was das Bundle kann

Lesen (`GET`): `info`, `system`, `pages`, `pages/{id}`, `articles?page=`, `content?article=|news=`, `content/{id}`,
`news_archives`, `news`, `news/{id}`, `seo/overview`, `search?q=`.
Schreiben: `PATCH pages/{id}` (title, pageTitle, description, robots, published, hide; Startpunkte nur title und
pageTitle), `POST content` (Typ text oder headline in einen Artikel oder eine Nachricht, **immer unsichtbar**),
`PATCH content/{id}` (headline, headline_unit, text, invisible), `POST news` (**immer unveröffentlicht**, Text als
Inhaltselement), `PATCH news/{id}` (headline, teaser, text, date, published, pageTitle, description), `POST cache/clear`
(Cache-Tags einzelner Seiten und Nachrichten). Jede Schreibantwort enthält `before` mit den vorherigen Werten.

- Jede Änderung setzt `tstamp`, legt eine Version an (`tl_version`, Benutzer „velundo“ – im Backend unter „Versionen“
  vergleichen und wiederherstellen), schreibt ins Contao-Protokoll und invalidiert die Cache-Tags
  `contao.db.<tabelle>.<id>` des Datensatzes und seines Elternsatzes (nur mit eingerichtetem HTTP-Cache).
- Klartextfelder werden wie im Backend gespeichert (Contao 5 kodiert, Contao 6 roh – siehe unten), HTML wird bereinigt (eigene Grobreinigung,
  danach `Input::stripTags()` mit den erlaubten Tags und Attributen der Installation). Insert-Tags nur als Verweise
  (`{{link_url::…}}` usw.).
- Nachrichten nur mit `contao/news-bundle` – sonst `404 feature_unavailable`; `GET info` nennt die Bereiche unter `features`.
- Der Wartungsmodus einer Website (Startpunkt) betrifft nur deren Frontend; die Schnittstelle bleibt erreichbar.

## Was es nicht kann (bewusst)

- Nichts löschen – auch nicht über Umwege; keine Dateien, Benutzer, Layouts, Module oder Konfiguration.
- Keine anderen Felder als die des Vertrags. Unbekannte Felder → 422 `unknown_field`.
- Kein Leeren des gesamten Caches; keine DCA-Callbacks (die Änderungen laufen nicht durch das Backend-Formular –
  Version, Protokoll und Cache-Tags übernimmt das Bundle selbst).

## Sicherheit und Datenschutz

- Nur HTTPS (außer `APP_ENV=dev`). Token-Vergleich in konstanter Zeit (`hash_equals` über alle Einträge), 401 ohne Details.
- Die Route liegt außerhalb der Contao-Bereiche: keine Firewall, keine Session, kein Cookie, keine CSRF-Prüfung
  (`_token_check: false`), Antworten mit `Cache-Control: no-store`.
- **Protokollierung:** Jeder Aufruf mit gültigem Token steht im Contao-Protokoll (Kategorie „Allgemein“, Text
  `velundo: <Methode> <Pfad> → <Status> – Token „<Bezeichnung>“`), Änderungen zusätzlich mit Tabelle, ID und geänderten
  Feldnamen – keine Inhalte. Anfragen ohne gültiges Token und 429-Antworten werden nicht protokolliert.
- Die Schnittstelle gibt keine Pfade, Zugangsdaten oder Konfigurationswerte aus; `GET system` nennt Versionen, Bundles
  und Pakete.
- Rechte: Wer ein Token hat, kann alle oben genannten Felder aller Websites der Installation lesen und ändern. Tokens
  deshalb je Agentur/Verbindung einzeln erzeugen und bei Bedarf widerrufen.

## Contao 5.3 und 6

Das Bundle läuft mit Contao 5.3 LTS und Contao 6 (geprüft gegen die Quellen von 5.3.52 und 6.0.4: alle Klassen laden,
Konfiguration, Route und Manager-Plugin funktionieren). Unterschiede, die das Bundle berücksichtigt:

| Bereich | Contao 5 | Contao 6 | Im Bundle |
|---|---|---|---|
| Klartextfelder (title, pageTitle, headline, description) | kodiert gespeichert (`Input::encodeInput`, z. B. `&#40;`) | roh gespeichert, Escaping erst bei der Ausgabe (`Widget::getPost`, Migration `Version600\OutputEncodingMigration`) | `PlainText` schaltet je Anfrage nach der Hauptversion um: Contao 5 kodiert, Contao 6 roh; beim Lesen werden Contao-5-Werte dekodiert, Contao-6-Werte nur von Basis-Entitäten befreit |
| Cache-Tags | `fos_http_cache.cache_manager` (DataContainer::invalidateCacheTags) | `contao.cache.tag_manager` (`CacheTagManager::invalidateTags`, Ereignis `InvalidateCacheTagsEvent`) | Tag-Manager, wenn vorhanden, sonst der FOS-Cache-Manager |
| Symfony / DBAL | 6.4 / 3.x | 8.x / 4.x | nur Methoden, die in beiden Ständen gleich sind |
| DCA-Spalten | Zeichenketten und Arrays | Arrays (Zeichenketten weiter gelesen) | Array-Schreibweise in `contao/dca/*` |

Unverändert in 6.0: Spalten und Speicherformat der genutzten Felder (`tl_content.headline` weiter serialisiert
`{value, unit}`, `ptable`, `invisible`, `tl_news.date/time`, Nachrichtentext als Inhaltselemente), `Contao\Versions`,
`ContaoContext` und Kanal `contao.general`, Content-URL-Generator, Slug-Dienst, `Input::stripTags()`, Manager-Plugin.

## Live zu prüfen

Noch ohne laufende Installation gebaut: Anlage der Tabellen mit `contao:migrate`, Versionseinträge (`Contao\Versions`
außerhalb des Backends), Protokoll in `tl_log`, Adressen über den Content-URL-Generator (Seiten, Nachrichten),
Slug-Erzeugung, Cache-Tags mit dem HTTP-Cache (unter Contao 6: ob `fos_http_cache.cache_manager` in der Managed Edition
weiter registriert ist – sonst meldet `features.http_cache` false), Darstellung der gespeicherten Titel im Frontend und
Backend (Contao 5 kodiert, Contao 6 roh), `Input::stripTags()` mit den erlaubten Tags.

## Entwicklung

```bash
php Tests/smoke.php                  # alle Prüfungen ohne Contao (PHP 8.2 und 8.5), Endpunkte gegen SQLite
vendor/bin/phpunit Tests/Unit        # dieselben Prüfungen mit PHPUnit, falls installiert
```

Aufbau: `src/Controller/ApiController.php` (Symfony-Einstieg), `src/Api/ApiKernel.php` (Token, HTTPS, Ratenbegrenzung,
Routing) und `src/Api/*Handler.php` je Bereich, `src/Service/*` (TokenService, RateLimiter, HtmlSanitizer, PlainText,
TextField, Writer, PageTree, Db, Sorting), `src/Service/Contao/*` (Anbindung an Contao: Versionen, Adressen, Cache, Slug,
HTML-Positivliste, Systemangaben), `src/Service/Store/*` (Tabellen und Prüf-Ersatz), `src/Http/*`, `src/Command/TokenCommand.php`,
`contao/dca/*`, `config/*`. Änderungen stehen in `CHANGELOG.md`.

## Lizenz

LGPL-3.0-or-later, siehe `LICENSE`.
