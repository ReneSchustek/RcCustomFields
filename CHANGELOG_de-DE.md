# Changelog (DE)

## [1.5.3] - 2026-07-20 — Server-Pflichtvalidierung + Bestelldetail-Anzeige

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. Kein Schema-Break, keine Migration.

### Behoben

- **Pflichtfelder werden jetzt serverseitig erzwungen (optional):** Bisher prüfte nur JavaScript, ob Pflichtfelder ausgefüllt sind — das ließ sich trivial umgehen (JavaScript deaktivieren oder direkt auf die Add-to-Cart-Route posten), leere Pflichtfelder gelangten so bis in die Bestellung. Ein neuer Cart-Validator prüft jede Position gegen die aktiven Pflichtfelder des Produkts und blockiert Warenkorb/Checkout mit einer klaren Meldung pro leerem Pflichtfeld. Aktivierbar über den bislang wirkungslosen Schalter **„Pflichtfelder vor ‚In den Warenkorb' erzwingen"** (Standard: aus — kein Verhaltenswechsel für bestehende Shops). Bewusst nur Pflicht-Präsenz, kein Typ/Min/Max, um bei Formaten/Sprachen keine falschen Checkout-Blockaden zu riskieren.
- **Kundeneingaben erscheinen wieder im Konto → Bestelldetail:** Die Bestelldetail-Ansicht las die Eingaben nur aus dem flüchtigen Warenkorb-Payload. Ältere (aus TMMS migrierte) Bestellungen hatten diesen Payload nie — ihre Eingaben zeigte nur das PDF, nicht die Bestellhistorie im Konto. Die Ansicht nutzt jetzt denselben defensiven Dual-Schema-Reader wie das PDF (`rc_custom_fields`-Snapshot **und** TMMS-Altbestand), der Payload-Pfad bleibt Fallback für den Live-Warenkorb. Steuerbar über den bislang wirkungslosen Schalter **„Eingaben in der Bestellhistorie anzeigen"**.

### Geändert

- **TMMS-Migration speicherschonend:** `scanCandidates`/`rollback` luden den gesamten Produktkatalog auf einmal in den Speicher → OOM/Timeout bei großen Katalogen. Jetzt wird in Batches von 100 Produkten iteriert (`RepositoryIterator`).
- Leere Leichen-Datei `test.txt` aus dem Plugin-Root entfernt.

## [1.5.2] - 2026-07-20 — Server-Sanitizer reaktiviert

> **Deployment:** `php bin/console cache:clear`.

### Behoben

- **Serverseitige Kappung der Kundeneingaben greift wieder:** Der Payload-Sanitizer prüfte die Route `frontend.checkout.cart.add`, die es in Shopware 6.7/6.8 nicht gibt — er lief also **nie**. Korrigiert auf die reale Add-to-Cart-Route `frontend.checkout.line-item.add`. Dabei `strip_tags` durch eine reine Längenbegrenzung (max. 1000 Zeichen) ersetzt: `strip_tags` hätte legitime Sonderzeichen zerstört (ein Gravurtext „Länge < 10mm" wäre ab dem `<` abgeschnitten worden); der XSS-Schutz liegt ohnehin am Output (alle Ausgaben sind escaped).

## [1.5.1] - 2026-07-18 — Fremder Auto-Split bleibt bei leeren Custom Fields erhalten

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. Kein Schema-Break, keine Migration.

### Behoben

- **`rcCustomFieldsActive` wurde unbedingt auf `1` gerendert**, sobald ein Produkt Custom Fields hatte — auch bei komplett leeren Feldern. Über das Plugin-Interaktionsprotokoll behauptete das Plugin damit dauerhaft ID-Hoheit gegenüber Sibling-Plugins, ohne sie auszuüben. Auf einem Produkt mit Meterpreis-Auto-Split (`max_rest`) degradierte der fremde Split daraufhin still zu einem Hinweis: kein Preis, „In den Warenkorb" deaktiviert — längere Zuschnitte waren **nicht bestellbar**, obwohl kein Feld ausgefüllt war. Marker und DOM-Attribut `data-rc-id-controller` werden jetzt vom JavaScript **an die Befüllung gekoppelt**: leer → keine Hoheit, fremder Auto-Split läuft; befüllt → RcCustomFields übernimmt die ID-Hoheit wie bisher.
- **Eingaben ohne JavaScript:** Der `PayloadConverter` liest die Custom-Field-Werte jetzt marker-unabhängig aus dem LineItem-Payload — maßgeblich ist der Wert, nicht der (per JavaScript gesetzte) Marker.

## [1.5.0] - 2026-07-15 — Defensiver Dual-Schema-Read (Twig-Helper)

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. Keine Migration, kein Schema-Break.

### Hinzugefügt

- **Twig-Helper `rc_cf_entries(customFields)`** — liest die Kundeneingaben einer Bestellposition **defensiv aus beiden Schemas** und liefert eine einheitliche Liste `{index, label, value}`: zuerst das eigene verschachtelte `rc_custom_fields`-Array, als Fallback der flache TMMS-Snapshot alter Bestellungen (`tmms_customer_input_{slot}_value/label`). Damit rendern Bestellungen von **vor** dem TMMS-Cutover in Rechnung/Lieferschein/Storno weiter korrekt, auch nachdem TMMS deinstalliert ist — ohne manuelles Nachpflegen einzelner Bestellungen. TMMS wird dabei ausschließlich gelesen, nie geschrieben.
- Twig-Test `is rc_cf_filled` (Position hat verwertbare Eingaben).
- Neuer Service `Service\CustomFieldsReader` (Twig-frei, pure-function, unit-getestet); die Twig-Extension delegiert nur.

### Geändert

- Das Dokumenten-Override (Rechnung/Lieferschein/…) nutzt jetzt `rc_cf_entries(...)` statt direkt auf `rc_custom_fields` zuzugreifen. Verhalten für bestehende (rc-)Bestellungen unverändert (Pinning-Test), zusätzlich rendern jetzt auch TMMS-Bestandsbestellungen.

## [1.4.5] - 2026-07-14 — Kundeneingaben erscheinen wieder auf Rechnungen

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`.

### Behoben

- **Kundeneingaben erschienen auf keinem Dokument.** Das Dokument-Template überschrieb den Block `document_line_items_table_row_label` — **diesen Block gibt es in Shopware nicht** (Mehrzahl statt Einzahl). Twig ignoriert einen Override auf einen unbekannten Block stillschweigend: kein Fehler, keine Warnung, kein Testausfall. Die Eingaben fehlten dadurch auf **Rechnung, Lieferschein, Storno und Gutschrift** — bei Zuschnittware also die bestellte Länge.

  Korrigiert auf `document_line_item_table_column_label`. Bewusst der **Column**-Block, nicht der Row-Block: Letzterer *ist* das `<td>`, ein Anhang nach `{{ parent() }}` läge dort außerhalb der Tabellenzelle.

### Hinzugefügt

- **Pinning-Test** `DocumentTemplateContractTest` — nagelt Ziel-Template, Blocknamen und `parent()`-Aufruf fest. Ergänzt um den Twig-Block-Vertrag-Checker, der den Blocknamen gegen die echten Shopware-Quellen prüft.

### Hinweis zur Tragweite

Alle Gates waren beim fehlerhaften Stand grün: PHPStan Level 8, PHP CS Fixer, PHPUnit, `composer audit`, GitHub-CI. Der Fehler wäre erst aufgefallen, wenn ein Kunde eine Rechnung ohne Maßangabe reklamiert hätte. **Vor dem TMMS-Cutover ist dieser Fix zwingend** — sonst verlieren alle Belege ihre Kundeneingaben.

## [1.4.4] - 2026-06-27

> **Deployment:** `php bin/console cache:clear`. Keine Migration.

### Behoben

- **Exception-Chain:** `CustomFieldInstaller` umschließt Fehler beim Install/Uninstall jetzt in einer aussagekräftigen `RuntimeException` (mit ursprünglicher Exception als `previous`) statt sie nackt durchzureichen — bessere Diagnose im Fehlerfall. Das strukturierte Error-Log bleibt.
- **Zeitzonen-Stabilität:** Der Rollback-Marker der TMMS-Migration nutzt jetzt explizit UTC (vorher server-zeitzonenabhängig).

## [1.4.3] - 2026-05-18

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. Keine Datenbank-Migration. Vor produktivem `rc:migrate-tmms`-Lauf zwingend `--dry-run` auf Spiegelinstanz mit Live-Daten verifizieren.

### Behoben

- **`rc:migrate-tmms` migriert jetzt Select-Optionen.** TMMS speichert sie pro Produkt im customField `_selectfieldvalues` als kommaseparierten String (z.B. `"Trägerplatte, Kugelring"`). RcCustomFields erwartet sie in `_options` im Format `"wert|anzeige"` pro Zeile. Der Service konvertiert das Format jetzt korrekt; Wert == Anzeige, weil TMMS keine separate Anzeige führt. Ohne diesen Mapping wären im Produktivkatalog 47 von 75 Produkten nach Migration mit leeren Select-Dropdowns gestrandet.
- **Range-Constraints für number-Felder werden migriert.** TMMS `_minvalue` / `_maxvalue` → RcCustomFields `_min` / `_max` (Float-Cast, nur wenn TMMS-Wert nicht NULL/Leer). Auf Produktivkatalog 18 Produkte betroffen.
- **TYPE_MAP kennt jetzt `'input'` als Synonym für `'text'`.** 56 Live-Produkte tragen `fieldtype=input` und wurden bisher still via Fallback gemappt — jetzt expliziter Map-Eintrag, kein Warning.
- **`mapType()` loggt Warnings bei unbekanntem Typ.** Bisher stiller Fallback auf `'text'`. Jetzt Audit-Spur (Logger-warning mit `tmmsType`-Kontext) — für Schema-Drift-Erkennung in zukünftigen TMMS-Versionen.

### Hinzugefügt

- **`TmmsMigrationServiceTest`** mit 7 Mapping-Tests (Type-Map, Select-CSV-Konvertierung, Edge-Cases). Vorher war der Service nicht unit-getestet — nur der Command.

## [1.4.2] - 2026-05-12

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. **Live-Pflicht-Pre-Check:** Vor dem Update per SQL verifizieren, dass keine Produkt-`custom_fields` Werte unter `rc_custom_field_*_min` / `rc_custom_field_*_max` tragen (Spalte `product_translation.custom_fields`). Bei Findings > 0: Stop, Senior-Decision.

### Behoben

- **`plugin:update` von Installationen mit historisch falschem CustomField-Type läuft durch.** Auf Shops, deren v1.0.x den `_min`/`_max`-Type als `text` statt `float` angelegt hat, brach das Upgrade auf v1.4.1 mit `The field "type" of "custom_field" is immutable and cannot be updated` (10 Errors, 5 Indizes × 2 Felder) ab — Shopware verbietet `type`-Mutation.
- **Installer reconciliert jetzt Type-Drift:** vor `enrichWithExistingIds` werden Felder, deren DB-Type nicht zum Code-Type passt, gelöscht. Der nachfolgende Upsert legt sie mit korrektem Type neu an.
- **Strukturiertes Logging:** Drift-Vorgänge werden mit `oldType` / `newType` / `droppedCount` als info-Eintrag protokolliert (Audit-Spur).
- **Datenverlust-Prüfung verpflichtend:** Da `type`-Mutation in Shopware nicht möglich ist, ist Drop+Recreate der einzige Pfad — Field-Werte in `product.custom_fields` gehen für die betroffenen Keys verloren. CHANGELOG dokumentiert den Pre-Check.

## [1.4.1] - 2026-05-12

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. Keine Datenbank-Migration. Datenwerte in `product.custom_fields` bleiben unberührt (Werte sind per Feld-Name keyed, Field-IDs bleiben stabil durch ID-Lookup).

### Behoben

- **`plugin:update` von Bestandsinstallationen schlägt nicht mehr fehl.** Auf Shops mit bereits installierter v1.0.0 / v1.1.0 brach das Upgrade auf v1.4.0 mit `Duplicate entry 'rc_custom_fields_enabled' for key 'uniq.custom_field.name'` ab — `customFieldSetRepository->upsert()` versuchte alle 91 Felder als INSERT, ohne bestehende per Name zu erkennen.
- **`CustomFieldInstaller` löst drei Ebenen idempotent auf:**
  1. **Field-IDs** per Name (EqualsAnyFilter auf `custom_field.name`)
  2. **Set-ID** per Name (EqualsFilter auf `custom_field_set.name`)
  3. **Set-Relation-ID** der product-Bindung (per Association `relations` mitgeladen) — sonst kollidiert `uniq.custom_field_set_relation (set_id, entity_name)` beim Re-Upsert
- Damit ist `install()` echt idempotent (mehrfache Aufrufe sind seiteneffekt-frei) — nicht nur "läuft durch", sondern erhält stabile Field-IDs und Relation-ID.
- **Zweite Repository-Dependency:** `custom_field.repository` im `CustomFieldInstaller`-Konstruktor (services.xml + `RcCustomFields::getInstaller()`).

## [1.4.0] - 2026-05-12

> **Deployment:** `composer install && php bin/console plugin:update RcCustomFields && bin/build-administration.sh && php bin/console cache:clear`. Plugin-Update legt die 5 zusätzlichen Custom-Felder per Upsert idempotent an.

### Hinzugefügt
- **Vue-Admin-Modul `rc-cf-product-tab`** als neuer Tab "Kundeneingaben" in der Produkt-Detailansicht (`sw.product.detail`). Greift per Twig-Override auf `sw_product_detail_content_tabs` und registriert eine neue Route `sw.product.detail.rc-custom-fields`.
- **Konfigurierbar pro Feld:** Aktiv-Toggle, Label, Typ (text/number/textarea/date/time/datetime/checkbox/select), Required, Placeholder, Einheit, Min/Max (für number+date+time+datetime), Optionen (für select). Konditionales Rendering — Min/Max nur bei Typen mit Range-Semantik, Options nur bei Select.
- **Live-Vorschau-Karte** listet alle aktiven Felder mit Label + Typ + Required-Marker.
- **FIELD_COUNT auf 10 erhöht** (vorher 5). PHP-Konstante `RcCustomFields::FIELD_COUNT`. Twig-Templates (Buy-Widget + Line-Item-Storefront) iterieren jetzt `1..10`. PayloadConverter, OrderLineItemWrittenSubscriber, TmmsMigrationService sind FIELD_COUNT-gerecht geschrieben — keine Code-Änderung dort nötig.
- **Snippets DE/EN** vollständig (Tab-Titel, Field-Editor, Vorschau, Type-Labels).

### Datenspeicherung
- **Bleibt im bestehenden CustomField-Schema** (`rc_custom_field_{i}_*`). Keine eigene Entity (YAGNI). Damit funktionieren PayloadConverter, Order-Subscriber, Dokumenten-Twig und TMMS-Migration unverändert weiter.

### Bewusste Einschränkungen (verschoben auf v1.5.0+)
- **Drag-and-Drop-Reihenfolge:** Felder werden in fester Reihenfolge 1..10 angezeigt. Reihenfolge-Manipulation würde eigene Persistenz-Schicht erfordern.
- **Cart-Edit-UI** (Änderung der Werte im Warenkorb) bleibt offen.

## [1.3.0] - 2026-05-12

> **Deployment:** `composer install && php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. Keine Datenbank-Migration im Plugin. TMMS-Migration ist eine optionale CLI-Operation.

### Hinzugefügt

- **`OrderLineItemWrittenSubscriber`** auf `CartConvertedEvent`: hängt RcCustomFields-Payload-Werte (`rcCustomField{i}Value/Label`) VOR der Order-Persistierung in `order_line_item.custom_fields` ein. Schlüssel: `rc_custom_fields` als Liste von `{index, label, value}`. Bestehende Custom-Fields aus anderen Plugins bleiben unangetastet.
- **`PayloadConverter`-Service** kapselt Payload-Extraktion und Custom-Field-Merge. Pure-function, testbar ohne DAL.
- **Twig-Override** `@RcCustomFields/documents/base.html.twig` extended `@Framework/documents/base.html.twig` und rendert die Custom-Field-Einträge pro Position in **Rechnung, Lieferschein, Storno** automatisch.

### Hinzugefügt

- **CLI-Command `bin/console rc:migrate-tmms`** mit drei Modi:
  - **`--dry-run`**: listet Kandidaten (Produkt-Nummer, ID, TMMS-Feldanzahl) ohne DB-Schreibung
  - **Default**: migriert TMMS-Produkt-Custom-Fields ins RcCustomFields-Schema. Pro Produkt eigene DBAL-Transaktion (atomar, kein inkonsistenter Zwischenstand bei Fehler)
  - **`--rollback`**: setzt `rc_custom_fields_enabled` auf false für alle in dieser Migration markierten Produkte (Marker `rc_custom_fields_migrated_from_tmms` mit ISO-8601-Timestamp wird gesetzt)
- **`TmmsMigrationService`** mit Type-Map (TMMS-fieldtype → RcCustomFields-type), Schema-Drift-Toleranz (fehlende TMMS-Felder werden als leerer String migriert, kein harter Abbruch).
- **Idempotenz:** zweiter Lauf produziert keinen neuen Stand, weil Read+Write auf denselben Custom-Field-Key zielt.

### Behoben (latente Bugs)

- **`CustomFieldTypes::TEXT_LONG`** existiert nicht in der aktuell installierten Shopware-Version — verwendete Konstante in `CustomFieldInstaller.php:221` (Field `rc_custom_field_{i}_options`) auf `CustomFieldTypes::TEXT` korrigiert. Funktional identisch für Textarea-basierte Eingabe.
- **PHPStan-Generic-Typen** in `CustomFieldInstaller::__construct()` und `RcCustomFields::getInstaller()` ergänzt (PHPDoc `@var EntityRepository<CustomFieldSetCollection>`).
- **`getId()` auf `Entity`** im Uninstall-Pfad — `instanceof CustomFieldSetEntity` Guard statt nullable-Check.

### Nächste Schritte (extern)

Damit kann **Phase 2.3 vollzogen werden**:

1. DB-Backup
2. `bin/console rc:migrate-tmms --dry-run` — Kandidaten verifizieren
3. `bin/console rc:migrate-tmms` — Migration ausführen
4. End-to-End-Test: Produkte mit migrierten Custom-Fields bestellen
5. `TmmsProductCustomerInputs` deaktivieren + deinstallieren
6. `RcCartSplitter` deaktivieren + Verzeichnis nach `old/` verschieben (Master-Plan Phase 6)

### Bewusste Einschränkungen (verschoben auf v1.4.0)

- **Cart-Edit-UI:** Änderung der Werte im Offcanvas-/Cart-Page ist noch nicht implementiert (Frontend-JS-Eingriff). Eingaben sind aktuell read-only nach AddToCart.
- **Admin-Bestellübersicht-Erweiterung:** Die `order_line_item.custom_fields`-Werte sind über den Custom-Field-Standard-Mechanismus im Admin sichtbar; ein eigenes Vue-Modul für prominente Anzeige steht aus.

## [1.2.0] - 2026-05-11

> **Deployment:** `php bin/console plugin:update RcCustomFields` (neue Feldtypen werden in das CustomFieldSet eingespielt) + `php bin/console cache:clear` + `bin/build-storefront.sh` (Twig + JS geändert). Keine Datenbank-Migration im engeren Sinne — die Update-Methode des Plugins ruft den Installer auf, der die erweiterten Felder per Upsert idempotent einspielt.

### Hinzugefügt
- **Fünf zusätzliche Feldtypen**: `date`, `time`, `datetime`, `checkbox`, `select`. Admin-Auswahl im Feld-Typ-Dropdown des CustomFieldSet ergänzt. Storefront rendert sie mit HTML5-nativen Inputs (`<input type="date|time|datetime-local">`, `<input type="checkbox">`, `<select>`). Vorhandene Typen `text`, `number`, `textarea` bleiben unverändert.
- **Neues Admin-Feld `rc_custom_field_{i}_options`** (Typ TEXT_LONG): erlaubt Konfiguration der Select-Optionen als „value|label"-Liste, eine Option pro Zeile. Das Storefront-Template parst die Zeilen und rendert `<option>`-Tags.
- **PSR-3-Logging im `CustomFieldInstaller`** (info bei Install/Uninstall + Anzahl Felder, error bei DAL-Fehlern mit Exception-Klasse). Log-Context `ruhrcoder_custom_fields.installer`. NullLogger als Default.
- **Quality-Toolchain komplettiert** (`composer.json`): explizites `php: >=8.2`-Constraint, Shopware 6.7/6.8-Support, `shopware/storefront`-Require, `require-dev` mit PHPUnit/PHPStan/CS-Fixer, vollständige `scripts`-Sektion (`test`, `phpstan`, `cs-fix`, `cs-check`, `test:js`, `quality`). Vorher: nur `test:js`.
- **`phpunit.xml.dist`** angelegt (PHPUnit-Konfiguration mit Unit-Test-Suite).
- **GitHub-Actions-CI-Pipeline**: `.github/workflows/ci-php.yml` mit Security-Audit, CS-Fixer, PHPStan Level 8, PHPUnit und JS-Tests. Trigger auf Push/PR `main`.
- **Massiver Test-Ausbau:**
  - `CustomFieldInstallerTest` von 4 auf 8 Tests erweitert: Schema-Vertrag (5 Felder × 9 Definitionen + Enabled-Flag), Verfügbarkeit aller 8 Type-Optionen im Select, Logger-Pfade (info/error), Fehler-Pfad mit Rethrow.
  - `PayloadSanitizerSubscriberTest` neu (7 Tests): HTML-Tag-Strip in `rcCustomField*`-Keys, fremde Keys unangetastet, Nicht-String-Werte werden skipped, fremde Routen werden ignoriert.
  - `TwigTemplateContractTest` neu (9 Tests): Pinning für alle Feldtyp-Branches im Template (`textarea`, `select`, `checkbox`, `date/time/datetime`, `number`/`text`), id-controller-Marker, Payload-Konvention.

### Behoben
- **Checkbox-Validation- und Hash-Bug im JS-Plugin:** `input.value` einer Checkbox ist immer `'1'` unabhängig vom `checked`-State. Validierung (`empty = input.value.trim() === ''`) hätte deshalb für nicht-angekreuzte Pflicht-Checkboxen fälschlich „gefüllt" gemeldet, und die deterministische LineItem-ID hätte angekreuzte und nicht-angekreuzte Checkboxen identisch gehasht. Fix: `input.type === 'checkbox'` → `input.checked`/`'1':''` als Lesepfad. Wirkt erst mit den neuen Feldtypen aus 1.2.0, davor war Checkbox nicht angeboten.

### Geändert
- `RcCustomFields::getInstaller()`: Container-Null-Check ergänzt (Stabilität in Test-/Lifecycle-Kontexten), Logger wird aus dem Container gezogen (Fallback `NullLogger`).
- `CustomFieldInstaller`: Stepdown-Refactor — `install()` delegiert an `buildEnabledFlag()` und `buildFieldDefinitions(int $i)` (Kohärenz pro Methode, kein 100-Zeilen-Array-Block mehr).

### Hinweis zu offenen Punkten
- **RC02** (Warenkorb-Bearbeitung + Dokumenten-Integration), **RC03** (TMMS-Daten-Migration), **RC04** (eigenes Admin-Modul) sind eigenständige Feature-Streams und bleiben Phase 2.5+.

## [1.1.0]

> **Deployment:** `bin/build-storefront.sh` nach Sync (JS geändert), keine Datenbank-Migration.

### Geändert
- Listener auf das generische `rcSuffixChanged`-Event umgestellt (Plugin-Interaktionsprotokoll v2). Die hartcodierte Liste `[rcMeterLengthChanged, rcColorPickerChanged]` in `init()` ist entfallen. Sibling-Plugins, die den Protokoll-Vertrag erfüllen, triggern damit auch ohne Code-Änderung hier die LineItem-ID-Re-Berechnung. RcCustomFields feuert selbst kein Suffix-Event, daher kein Self-Loop-Guard notwendig. Statische Konstante `RcCustomFieldsPlugin.SUFFIX_CHANGED_EVENT` exponiert.

### Hinzugefügt
- `destroy()`-Methode mit sauberem Listener-Cleanup. Erste JS-Test-Suite (`tests/Js/rc-custom-fields.suffix-event.test.mjs`) verankert `SUFFIX_CHANGED_EVENT`-Konstante und prüft den Listener-Trigger.
- `composer test:js` als erstes Quality-Gate-Skript.

# 1.0.0

- Hinzugefügt: Kundeneingaben pro Produkt mit deterministischer LineItem-ID-Berechnung (FNV-1a Hash über sortierte Feldwerte plus alle `rc*Suffix`-Attribute).
- Hinzugefügt: Generisches Suffix-Protokoll für Plugin-Interaktion (RcDynamicPrice, RcColorPicker fließen in den Hash ein).
- Hinzugefügt: Pflichtfeld-Validierung mit `is-invalid`-Markierung und `invalid-feedback`.
- Hinzugefügt: `stackSameValues`-Toggle — bei `false` wird per Timestamp jede Eingabe zur eigenen Position.
