# velundo ↔ Contao – Schnittstellenvertrag v1

Zwei Seiten: (1) Contao-Bundle `velundo/contao-connect` (Composer, Typ `contao-bundle`), das der Kunde bzw. die Agentur in
der Contao-Installation (Contao 5.3 LTS oder neuer einschließlich Contao 6, PHP ≥ 8.2) installiert; (2) Modul `contao` in der velundo-App, das diese
API nutzt. Beide Seiten halten sich wörtlich an diesen Vertrag. Marke klein „velundo“. Keine Hinweise auf Betreiber/Firma.

## Transport
- Basis: `https://<host>/_velundo/v1/` (Symfony-Route des Bundles, außerhalb von Contao-Frontend und -Backend: keine
  Contao-Firewall, keine Session, keine CSRF-Prüfung, kein Seiten-Cache). Nur HTTPS. Läuft Contao in einem
  Unterverzeichnis, liegt die Basis entsprechend unter `https://<host>/<pfad>/_velundo/v1/`.
- Auth: `Authorization: Bearer <token>`, ersatzweise `X-Velundo-Token: <token>` (Hoster mit Apache und PHP-FPM geben
  `Authorization` oft nicht an PHP weiter; die App sendet beide). Token per Konsole
  `vendor/bin/contao-console velundo:token create "<Bezeichnung>"` erzeugt, nur als SHA-256-Hash in der Tabelle
  `tl_velundo_token` gespeichert, einmal angezeigt; mehrere Tokens mit Bezeichnung möglich, widerrufbar
  (`velundo:token revoke <Kennung>`). Fehlendes/falsches Token → 401 `{"error":{"code":"unauthorized","message":"…"}}`.
- Zusatzrechte je Token (ab 1.1.0, Spalte `scopes`): Ohne Zusatzrecht ändert ein Token nur unveröffentlichte Inhalte und
  Metadaten. `velundo:token create "…" --allow-publish` erlaubt `published: true` bei Seiten und Nachrichten und
  `invisible: false` bei Inhaltselementen; `--allow-live-edit` erlaubt `title`/`pageTitle` veröffentlichter Seiten,
  `headline`/`headline_unit`/`text` sichtbarer Inhaltselemente und `headline`/`teaser`/`text` veröffentlichter
  Nachrichten (`description`, `robots`, `hide`, `date` bleiben frei; wird im selben Aufruf unveröffentlicht, gilt die
  Änderung als Entwurf). Fehlt das Recht → 403 `scope_publish` bzw. `scope_live_edit` (Meldung nennt das Kommando).
  Tokens aus 1.0.x und Installationen ohne `contao:migrate` haben keine Zusatzrechte.
- Reihenfolge der Prüfungen: Token → HTTPS → Ratenbegrenzung → Route. Ohne gültiges Token antwortet jeder Pfad unter
  `/_velundo/` mit 401 – auch über `http`, ohne Versionsangaben. Mit gültigem Token über `http` (und `require_https`
  aktiv, Umgebung nicht `dev`) → 403 `https_required`. Mehr als 30 Anfragen ohne gültiges Token je Minute und
  Absenderadresse → 429 (ab 1.1.0, vor der Token-Prüfung; Adresse nur gehasht in `tl_velundo_rate`).
- Header der Antwort: `X-Velundo-Api: 1`, `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`.
- Fehler: HTTP 400/401/403/404/409/422/429/500 mit `{"error":{"code":"<snake_case>","message":"<deutsch>","details":{}}}`.
  Unbekannte Methode für einen bekannten Pfad → 404 `method_not_allowed` mit `details.allowed`.
- Ratenbegrenzung im Bundle: 120 Anfragen je Minute je Token (Einstellung `rate_limit_per_minute`) → 429 mit
  `Retry-After`. Der Zähler steht in der Tabelle `tl_velundo_rate` und wird per atomarem `UPDATE … SET hits = hits + 1`
  geführt; bei gleichzeitigen Anfragen kann die Grenze um einzelne Anfragen strenger greifen, nie weicher.
  429-Antworten werden nicht ins Contao-Protokoll geschrieben (siehe unten: Protokoll nur für Änderungen).
- Paginierung: `?limit=` (≤ 200, Standard 50) und `?offset=`; Antwort `{"items":[…],"total":n,"limit":l,"offset":o}`.
- Zeiten als ISO 8601 mit Versatz (Zeitzone der Installation); leere Zeitsteuerung (`start`, `stop`) → `null`.
  Kennungen sind Contao-`id` (int). Eingaben für Zeitpunkte (`date`) nur nach ISO 8601 (`2026-10-10`,
  `2026-10-10T12:00:00+02:00`, `…Z`); andere Schreibweisen → 422 `invalid_type`.
- Mehrere Websites (Startpunkte = Seiten vom Typ `root`, je eine Domain/Sprache) je Installation: `?root=<id>` grenzt
  Seitenbaum, SEO-Übersicht und Suche auf eine Website ein (Standard: alle). Unbekannter Startpunkt → 404 `root_not_found`.
- Sichtbarkeit: `?visibility=all|visible|hidden` (Standard `all`). „sichtbar“ heißt `published = true` bei Seiten,
  Artikeln und Nachrichten bzw. `invisible = false` bei Inhaltselementen – die Zeitsteuerung (`start`/`stop`) wird
  getrennt gemeldet und nicht ausgewertet.
- Texte: Klartextfelder (Titel, Seitentitel, Überschriften, Beschreibungen) liefert die API als Klartext. Beim Schreiben
  speichert das Bundle sie wie das Contao-Backend der jeweiligen Version: Contao 5 kodiert Sonderzeichen als Entitäten
  (`#<>()\="'` bzw. bei Beschreibungen nur `<`, z. B. `&#40;`), Contao 6 speichert roh und kodiert erst bei der Ausgabe.
  HTML-Felder (`text`, `teaser`) kommen als HTML.

## Lesen
- `GET info` → `{"contao":"5.3.12","php":"8.3.x","bundle":"1.0.0","roots":[{"id":1,"title":"…","dns":"www.example.de","language":"de","fallback":true,"published":true,"maintenance":false,"base":"https://www.example.de/"}],"features":{"news":bool,"calendar":bool,"http_cache":bool},"limits":{"max_text_kb":200,"rate_limit_per_minute":120,"limit_max":200}}`
  (`base`: Schema aus `useSSL`, Host aus `dns` oder – leer – dem Host der Anfrage, dazu `urlPrefix`; ab 1.1.1 zusätzlich
  `"token":{"name":"<Bezeichnung>","scopes":["publish","live_edit"]}` – das aufrufende Token mit seinen Zusatzrechten,
  leere Liste = keine)
- `GET system` → `{"contao":…,"php":…,"environment":"prod","bundles":["ContaoCoreBundle",…],"packages":[{"name":"contao/news-bundle","type":"contao-bundle","version":"5.3.12"}],"cron_last_run":"…"|null}`
  (ab 1.1.0 nur Bundles mit Kurznamen `Contao…`/`Velundo…` und Pakete `contao/*`, `velundo/*` – keine Inventarliste;
  Pakete der Typen `contao-bundle`, `contao-module`, `contao-theme`, `symfony-bundle` laut Composer;
  keine Pfade, keine Geheimnisse, keine Konfigurationswerte)
- `GET pages?root&parent=<id>&depth=1..20&search=&visibility&limit&offset` → Seiten in Baumreihenfolge (Tiefensuche nach
  `sorting`); items: `id, pid, root, level, type, title, alias, url, published, hide, robots, pageTitle, description, start, stop, tstamp, language, sorting`.
  `parent` liefert den Teilbaum unter dieser Seite (ohne die Seite selbst), `depth` die Zahl der Ebenen darunter
  (Standard 20 = alles); ohne `parent` zählen die Startpunkte als erste Ebene (`depth=1` = nur Startpunkte). `search` sucht in `title`, `pageTitle` und `alias`. `visibility` filtert die Ergebniszeilen, nicht den Baum.
  `url` ist die absolute Adresse (Content-URL-Generator von Contao), `null` wenn Contao keine erzeugt (Ordner-,
  Weiterleitungs- oder Fehlerseiten ohne Ziel). `level`: 0 = Startpunkt.
- `GET pages/{id}` → wie ein Listeneintrag plus `articles: [{id, title, alias, inColumn, published, start, stop, sorting, elements}]`
  (`elements` = Zahl der Inhaltselemente des Artikels, alle Sichtbarkeiten).
- `GET articles?page=<id>&visibility&limit&offset` → items: `id, pid, title, alias, inColumn, published, start, stop, sorting, tstamp, elements`.
- `GET content?article=<id>` bzw. `?news=<id>` (genau einer der beiden) `&visibility&limit&offset` → items in Reihenfolge
  (`sorting`): `id, pid, ptable, type, headline, headline_unit, text_plain (Klartext, ≤ 2000 Zeichen), text_length (Bytes HTML), invisible, start, stop, sorting, tstamp`.
- `GET content/{id}` → wie oben plus `text` (HTML), `link_count`, `image` (bool, Bild angehängt) und
  `parent: {table, id, title, page?, archive?}` (`page` bei Artikeln, `archive` bei Nachrichten).
- `GET news_archives?limit&offset` (nur wenn `features.news`) → items: `id, title, jumpTo (Detailseite), protected, news_count, tstamp`.
- `GET news?archive=<id>&search=&visibility&limit&offset` (nur wenn `features.news`) → neueste zuerst (`date`); items:
  `id, pid (Archiv), headline, alias, date, teaser (HTML), teaser_text (Klartext, ≤ 2000), published, featured, start, stop, pageTitle, description, robots, source, url, tstamp`.
  `search` sucht in `headline` und `teaser`. `url` über den Content-URL-Generator (Detailseite des Archivs), sonst `null`.
- `GET news/{id}` → plus `content: [{id, type, headline, invisible, sorting, text_length}]` (Inhaltselemente der
  Nachricht), `text` (HTML aller Elemente vom Typ `text` in Reihenfolge, durch Zeilenumbruch getrennt), `text_plain`
  (Klartext, ≤ 20000 Zeichen) und `text_element`: `id` des Elements, das `PATCH news {text}` ändert (das einzige
  Element vom Typ `text`), `0` wenn es keines gibt (`PATCH` legt dann eines an), `null` wenn es mehrere gibt.
- `GET seo/overview?root` → `{"pages_total":n,"missing_title":[{id,title,url}],"missing_description":[…],"noindex":[…],"long_title":[…] (> 60 Zeichen),"long_description":[…] (> 160),"duplicate_titles":[[id,id],…]}`
  über veröffentlichte Seiten vom Typ `regular` (höchstens 100 je Liste). `missing_title`: kein `pageTitle` (dann gilt
  der Seitenname); Länge und Dubletten über den wirksamen Titel (`pageTitle`, sonst `title`).
- `GET search?q=&root&visibility&limit` (`q` 2–200 Zeichen, `limit` Standard 20, ≤ 100) →
  `{"q":"…","pages":[{id,title,url,published}],"content":[{id,pid,ptable,type,headline,text_plain (≤ 300),invisible}],"news":[{id,headline,url,published}]}`
  (Seiten: `title`, `pageTitle`; Inhaltselemente: `headline`, `text`; Nachrichten: `headline`, `teaser`). Mit `root`
  nur Seiten dieser Website und Inhaltselemente in deren Artikeln; Nachrichten gehören zu keiner Website und fehlen dann.

## Schreiben (direkt in der Datenbank, nie löschen)
Jede Änderung setzt `tstamp`, legt einen Versionseintrag an (`tl_version` über `Contao\Versions`, Benutzername
„velundo“ – Wiederherstellen im Backend unter „Versionen“), schreibt einen Eintrag ins Contao-Protokoll (`tl_log`,
Kanal `contao.general`, Text „velundo: … – Token „<Bezeichnung>““ mit Tabelle, ID und Feldnamen, keine Inhalte) und
invalidiert die Cache-Tags der betroffenen Datensätze (`contao.db.<tabelle>.<id>` und Elternsatz, z. B.
`contao.db.tl_article.<pid>`; Contao 6 über `contao.cache.tag_manager`, Contao 5 über den FOS-Cache-Manager; nur wenn
der HTTP-Cache von Contao eingerichtet ist). Jede Schreibantwort enthält
`"before": {…}` mit den vorherigen Werten der geänderten Felder (API-Darstellung; für den Ist-Vergleich der App).

- `PATCH pages/{id}` Body: Teilmenge von `title, pageTitle, description, robots, published, hide` → 200 mit der Seite wie `GET pages/{id}`.
  Längen: `title` 1–255, `pageTitle` ≤ 255, `description` ≤ 5000 Zeichen → sonst 422 `too_long` bzw. `empty_value`.
  `robots`: `""` (Standard), `index,follow`, `index,nofollow`, `noindex,follow`, `noindex,nofollow` → sonst 422 `invalid_value`.
  Startpunkte (`type = root`): nur `title` und `pageTitle` → sonst 422 `root_page`.
  `published: true` braucht `publish`, `title`/`pageTitle` einer veröffentlichten Seite `live_edit` (403 `scope_…`, siehe oben).
- `POST content` Body: `article (id) | news (id)` (genau eines), `type ("text"|"headline", Standard "text")`, `headline`,
  `headline_unit (h1–h6, Standard h2)`, `text (HTML, nur bei type text)`, `after (id, optional)` → 201 `{"id":n,"sorting":s,"before":{}}`.
  Neue Elemente sind **immer unsichtbar** (`invisible = true`); ein Feld `invisible` im Rumpf → 422 `unknown_field`.
  `type text` braucht `text`, `type headline` braucht `headline` → sonst 422 `empty_value`.
  Einsortierung: ohne `after` ans Ende (größtes `sorting` + 128); mit `after` direkt dahinter – das Element muss zum
  selben Artikel bzw. zur selben Nachricht gehören (422 `after_mismatch`); ohne Lücke wird wie im Backend in
  128er-Schritten neu nummeriert.
- `PATCH content/{id}` Body: Teilmenge von `headline, headline_unit, text, invisible` → 200 mit Element wie `GET content/{id}`.
  `headline`/`headline_unit` nur bei Typ `text` und `headline`, `text` nur bei Typ `text` → sonst 422 `unsupported_type`;
  `invisible` bei jedem Typ. `invisible: false` braucht `publish`, `headline`/`headline_unit`/`text` eines sichtbaren
  Elements `live_edit`.
- `POST news` (features.news) Body: `archive (id, Pflicht), headline (Pflicht, ≤ 255), teaser (HTML), text (HTML → ein
  Inhaltselement vom Typ text mit ptable tl_news), date (ISO, Standard jetzt), pageTitle, description` → 201
  `{"id":n,"alias":"…","text_element":m|null,"before":{}}`. Nachrichten entstehen **immer unveröffentlicht**
  (`published = false`, ein Feld `published` → 422 `unknown_field`). Unbekanntes Archiv → 404 `not_found`.
  `alias` wie im Backend aus der Überschrift (Slug-Dienst von Contao mit den Einstellungen der Detailseite, eindeutig);
  `date` und `time` erhalten denselben Zeitstempel; `author` bleibt 0 (kein Backend-Benutzer).
- `PATCH news/{id}` Body: Teilmenge von `headline, teaser, text, date, published, pageTitle, description` → 200 wie `GET news/{id}`.
  `text` ändert das einzige Element vom Typ `text` der Nachricht bzw. legt eines an (sichtbar innerhalb der Nachricht);
  bei mehreren → 422 `ambiguous_text` mit `details.elements` (dann einzeln über `PATCH content/{id}`).
  `published: true` braucht `publish`, `headline`/`teaser`/`text` einer veröffentlichten Nachricht `live_edit`.
- `POST cache/clear` Body: `{"pages":[id,…]}` und/oder `{"news":[id,…]}` (zusammen 1–200 IDs) → 200
  `{"tags":["contao.db.tl_page.1",…],"invalidated":true,"before":{}}`. Ohne HTTP-Cache (`features.http_cache = false`)
  → 404 `feature_unavailable`. Leeren des gesamten Caches ist nicht vorgesehen.
- HTML in `text` und `teaser`: höchstens `max_text_kb` (Standard 200 KB, sonst 422 `text_too_large`); vor dem Speichern
  bereinigt – keine `<script>`, `<style>`, `<iframe>`, `<object>`, `<embed>`, `<form>`, `<svg>`, `<math>`, keine
  Ereignis-Attribute, keine `javascript:`/`vbscript:`/`data:text/html`-Adressen – und danach über `Input::stripTags()` mit
  den erlaubten Tags und Attributen der Contao-Einstellungen (wie beim Speichern im Backend).
- Insert-Tags: In HTML nur Verweise (`link`, `link_open`, `link_url`, `link_title`, `link_close`, `news`, `news_open`,
  `news_url`, `news_title`, `article`, `article_open`, `article_url`, `article_title`, `event`, `event_open`, `event_url`,
  `event_title`, `faq`, `faq_open`, `faq_url`, `faq_title`, `email`, `email_open`, `email_url`, `br`), sonst und in
  Klartextfeldern jedes `{{` → 422 `insert_tag_not_allowed`.
- Klartextfelder dürfen keine Steuerzeichen enthalten → 422 `invalid_value`.
- Scheitert das Speichern in der Datenbank → 500 `write_failed` (ohne Datenbank-Fehlertexte).

## Sicherheit
- Nur Felder dieses Vertrags; keine Benutzer-, Datei-, Layout- oder Konfigurationszugriffe; keine Löschungen; der Pfad
  `/_velundo/` antwortet ohne Token nur 401 (keine Versionsangaben).
- Das Bundle protokolliert in `tl_log` jede Änderung mit Tabelle, ID und Feldnamen (keine Inhalte) sowie abgelehnte
  Schreibversuche mit gültigem Token („velundo: PATCH pages/5 → 422 – Token „…““); Lesezugriffe und 429-Antworten nicht.
