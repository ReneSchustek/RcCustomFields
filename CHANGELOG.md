# Changelog (DE)

## [1.5.7] - 2026-10-09: Die Zusatzfelder heißen wie die Erweiterung

### Geändert

- Unter „Zusatzfelder“ am Artikel stand „RC Custom Fields — Kundeneingaben“, der Schalter hieß „RC Custom Fields aktivieren“, die Einstellungskarte „RC Custom Fields – Einstellungen“. Alle drei tragen jetzt den Namen, der unter Erweiterungen steht: „Kundeneingaben“.

## [1.5.6] - 2026-08-19: Der Reiter am Produkt lässt sich endlich bedienen

### Behoben

- **Kein einziger Schalter im Reiter „Kundeneingaben" wurde angezeigt.** Betroffen waren der
  Hauptschalter und je Feld die Schalter „aktiv" und „Pflichtfeld". Damit ließen sich die
  Kundeneingaben für ein Produkt gar nicht erst einschalten. Die Maske benutzte ein Bauteil, das
  Shopware seit 6.7 nicht mehr kennt, und unbekannte Bauteile bleiben still leer, ohne Meldung.
- **Jede Texteingabe ging beim Speichern verloren.** Beschriftung, Platzhalter, Einheit, Minimum,
  Maximum und die Auswahlliste der Werte reichten beim Tippen das Ereignis statt des Textes
  weiter. Der Server verwarf es stillschweigend: kein Fehler, keine Meldung, und beim nächsten
  Aufruf stand wieder der alte Wert da. Auch die Auswahl des Feldtyps kam nie an.
- Ein Vertragstest prüft ab jetzt beides. Er wird rot, sobald eine Vorlage ein entferntes Bauteil
  benutzt oder ein Feld auf das Ereignis statt auf den Wert hört.

## [1.5.5] - 2026-08-17: Der Reiter am Produkt erscheint wieder

### Behoben

- **Die Kappung der Kundeneingaben griff nur auf einem von zwei Wegen.** Der Riegel prüfte
  ausschließlich die Route der Storefront und den Parameter `lineItems`. Die Store-API nimmt
  dieselben Positionen aber unter `items` und über eigene Routen entgegen. Über diesen Weg lief
  keine Kappung, und Kundeneingaben landeten in beliebiger Länge in der Bestellung. Geprüft werden
  jetzt beide Routenpräfixe und beide Parameternamen. **Es ging dabei nicht um Schadcode:** Die
  Ausgabe ist in Warenkorb, Beleg und Backoffice an allen Stellen maskiert. Offen waren unbegrenzte
  Datenmengen in der Bestelltabelle, also Speicherwachstum und übergroße Zeilen.
- **Am Produkt fehlte der Reiter für die Kundeneingaben.** Damit ließ sich für keinen Artikel
  einrichten, was der Kunde eingeben soll. Ursache waren die fehlenden gebauten Dateien des
  Backoffice-Teils. Ohne sie kündigt Shopware das Bündel nicht an und lädt es nie, und zwar ohne
  Fehlermeldung. Es sah deshalb nicht nach einem Fehler aus, sondern nach „diesen Reiter gibt es
  hier nicht". Wer den Verwaltungsbereich nach der Installation neu baut, hat ihn nie vermisst.

## [1.5.4] - 2026-08-10: Backoffice-Übersetzungen und Vorbereitung auf 6.8

### Geändert

- **Die Übersetzungen im Backoffice werden wieder angemeldet.** Der bisherige Weg existiert in Shopware 6.7 nicht mehr; der Aufruf brach beim Laden der Administration ab, und alles danach wurde nicht mehr ausgeführt.
- **Die Produktseite lädt wieder.** Die gebauten Storefront-Dateien des Plugins fehlten, und ohne sie brach die Seite mit einem Serverfehler ab.
- **Vorbereitung auf die nächste Shopware-Hauptversion.** Der Zugriff auf Suchergebnisse folgt der Schreibweise, die Shopware 6.8 verlangt. Am Verhalten ändert sich nichts.

## [1.5.3] - 2026-07-20: Server-Pflichtvalidierung und Anzeige im Bestelldetail

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. Kein Schema-Break, keine Migration.

### Behoben

- **Pflichtfelder werden jetzt serverseitig erzwungen (optional).** Bisher prüfte nur JavaScript, ob Pflichtfelder ausgefüllt sind. Das ließ sich trivial umgehen, etwa mit abgeschaltetem JavaScript oder einem direkten Post auf die Add-to-Cart-Route, und so gelangten leere Pflichtfelder bis in die Bestellung. Ein neuer Cart-Validator prüft jede Position gegen die aktiven Pflichtfelder des Produkts und blockiert Warenkorb und Checkout mit einer klaren Meldung pro leerem Pflichtfeld. Eingeschaltet wird er über den bislang wirkungslosen Schalter **„Pflichtfelder vor ‚In den Warenkorb' erzwingen"**. Der Standard ist aus, bestehende Shops verhalten sich also wie vorher. Geprüft wird bewusst nur, ob ein Pflichtfeld gefüllt ist, nicht Typ, Min oder Max. Unterschiedliche Formate und Sprachen sollen keine falschen Checkout-Blockaden auslösen.
- **Kundeneingaben erscheinen wieder im Konto unter Bestelldetail.** Die Ansicht las die Eingaben nur aus dem flüchtigen Warenkorb-Payload. Ältere, aus TMMS migrierte Bestellungen hatten diesen Payload nie, ihre Eingaben zeigte deshalb nur das PDF und nicht die Bestellhistorie im Konto. Jetzt nutzt die Ansicht denselben defensiven Dual-Schema-Reader wie das PDF (`rc_custom_fields`-Snapshot **und** TMMS-Altbestand). Der Payload-Pfad bleibt Fallback für den Live-Warenkorb. Steuerbar ist das über den bislang wirkungslosen Schalter **„Eingaben in der Bestellhistorie anzeigen"**.

### Geändert

- **TMMS-Migration speicherschonend.** `scanCandidates` und `rollback` luden den gesamten Produktkatalog auf einmal in den Speicher, was bei großen Katalogen zu OOM oder Timeout führte. Jetzt wird in Batches von 100 Produkten iteriert (`RepositoryIterator`).
- Die leere, verwaiste Datei `test.txt` ist aus dem Plugin-Root entfernt.

## [1.5.2] - 2026-07-20: Server-Sanitizer reaktiviert

> **Deployment:** `php bin/console cache:clear`.

### Behoben

- **Die serverseitige Kappung der Kundeneingaben greift wieder.** Der Payload-Sanitizer prüfte die Route `frontend.checkout.cart.add`, die es in Shopware 6.7/6.8 nicht gibt. Er lief also **nie**. Korrigiert ist das auf die tatsächliche Add-to-Cart-Route `frontend.checkout.line-item.add`. Dabei ersetzt eine reine Längenbegrenzung (max. 1000 Zeichen) das bisherige `strip_tags`. Das hätte legitime Sonderzeichen zerstört: Ein Gravurtext „Länge < 10mm" wäre ab dem `<` abgeschnitten worden. Der XSS-Schutz liegt ohnehin am Output, alle Ausgaben sind escaped.

## [1.5.1] - 2026-07-18: Fremder Auto-Split bleibt bei leeren Custom Fields erhalten

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. Kein Schema-Break, keine Migration.

### Behoben

- **`rcCustomFieldsActive` wurde unbedingt auf `1` gerendert**, sobald ein Produkt Custom Fields hatte, auch wenn alle Felder leer waren. Über das Plugin-Interaktionsprotokoll beanspruchte das Plugin damit dauerhaft die ID-Hoheit gegenüber Sibling-Plugins, ohne sie auszuüben. Auf einem Produkt mit Meterpreis-Auto-Split (`max_rest`) wurde der fremde Split daraufhin still zu einem bloßen Hinweis: kein Preis, „In den Warenkorb" deaktiviert. Längere Zuschnitte waren **nicht bestellbar**, obwohl kein Feld ausgefüllt war. Marker und DOM-Attribut `data-rc-id-controller` sind jetzt im JavaScript **an die Befüllung gekoppelt**. Sind die Felder leer, gibt es keine Hoheit und der fremde Auto-Split läuft. Sind sie befüllt, übernimmt RcCustomFields die ID-Hoheit wie bisher.
- **Eingaben ohne JavaScript:** Der `PayloadConverter` liest die Custom-Field-Werte jetzt unabhängig vom Marker aus dem LineItem-Payload. Maßgeblich ist der Wert, nicht der per JavaScript gesetzte Marker.

## [1.5.0] - 2026-07-15: Defensiver Dual-Schema-Read (Twig-Helper)

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. Keine Migration, kein Schema-Break.

### Hinzugefügt

- **Twig-Helper `rc_cf_entries(customFields)`.** Er liest die Kundeneingaben einer Bestellposition **defensiv aus beiden Schemas** und liefert eine einheitliche Liste `{index, label, value}`. Zuerst kommt das eigene verschachtelte `rc_custom_fields`-Array, als Fallback der flache TMMS-Snapshot alter Bestellungen (`tmms_customer_input_{slot}_value/label`). So rendern Bestellungen von **vor** dem TMMS-Cutover in Rechnung, Lieferschein und Storno weiter korrekt, auch nachdem TMMS deinstalliert ist, und niemand muss einzelne Bestellungen von Hand nachpflegen. TMMS wird dabei ausschließlich gelesen, nie geschrieben.
- Twig-Test `is rc_cf_filled` (Position hat verwertbare Eingaben).
- Neuer Service `Service\CustomFieldsReader` (Twig-frei, pure-function, unit-getestet); die Twig-Extension delegiert nur.

### Geändert

- Das Dokumenten-Override (Rechnung, Lieferschein, …) nutzt jetzt `rc_cf_entries(...)`, statt direkt auf `rc_custom_fields` zuzugreifen. Für bestehende (rc-)Bestellungen bleibt das Verhalten gleich, ein Pinning-Test sichert das ab. Zusätzlich rendern jetzt auch TMMS-Bestandsbestellungen.

## [1.4.5] - 2026-07-14: Kundeneingaben erscheinen wieder auf Rechnungen

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`.

### Behoben

- **Kundeneingaben erschienen auf keinem Dokument.** Das Dokument-Template überschrieb den Block `document_line_items_table_row_label`, und **diesen Block gibt es in Shopware nicht** (Mehrzahl statt Einzahl). Twig ignoriert einen Override auf einen unbekannten Block stillschweigend: kein Fehler, keine Warnung, kein Testausfall. Die Eingaben fehlten dadurch auf **Rechnung, Lieferschein, Storno und Gutschrift**, bei Zuschnittware also ausgerechnet die bestellte Länge.

  Korrigiert auf `document_line_item_table_column_label`. Es ist bewusst der **Column**-Block und nicht der Row-Block, denn Letzterer *ist* das `<td>`. Ein Anhang nach `{{ parent() }}` läge dort außerhalb der Tabellenzelle.

### Hinzugefügt

- **Pinning-Test** `DocumentTemplateContractTest`. Er nagelt Ziel-Template, Blocknamen und `parent()`-Aufruf fest. Dazu kommt der Twig-Block-Vertrag-Checker, der den Blocknamen gegen die echten Shopware-Quellen prüft.

### Hinweis zur Tragweite

Beim fehlerhaften Stand waren alle Gates grün: PHPStan Level 8, PHP CS Fixer, PHPUnit, `composer audit`, GitHub-CI. Aufgefallen wäre der Fehler erst, wenn ein Kunde eine Rechnung ohne Maßangabe reklamiert hätte. **Vor dem TMMS-Cutover ist dieser Fix zwingend**, sonst verlieren alle Belege ihre Kundeneingaben.

## [1.4.4] - 2026-06-27

> **Deployment:** `php bin/console cache:clear`. Keine Migration.

### Behoben

- **Exception-Chain:** `CustomFieldInstaller` reicht Fehler bei Install und Uninstall nicht mehr nackt durch, sondern verpackt sie in eine aussagekräftige `RuntimeException` mit der ursprünglichen Exception als `previous`. Das erleichtert die Diagnose. Das strukturierte Error-Log bleibt.
- **Zeitzonen-Stabilität:** Der Rollback-Marker der TMMS-Migration nutzt jetzt ausdrücklich UTC. Vorher hing er von der Zeitzone des Servers ab.

## [1.4.3] - 2026-05-18

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. Keine Datenbank-Migration. Vor dem produktiven Lauf von `rc:migrate-tmms` unbedingt mit `--dry-run` auf einer Spiegelinstanz mit Live-Daten verifizieren.

### Behoben

- **`rc:migrate-tmms` migriert jetzt Select-Optionen.** TMMS speichert sie pro Produkt im customField `_selectfieldvalues` als kommaseparierten String (z.B. `"Trägerplatte, Kugelring"`). RcCustomFields erwartet sie in `_options` im Format `"wert|anzeige"`, eine Zeile pro Option. Der Service konvertiert das Format jetzt korrekt. Wert und Anzeige sind dabei gleich, weil TMMS keine separate Anzeige führt. Ohne dieses Mapping wären im Produktivkatalog 47 von 75 Produkten nach der Migration mit leeren Select-Dropdowns gestrandet.
- **Range-Constraints für number-Felder werden migriert.** Aus TMMS `_minvalue` / `_maxvalue` werden RcCustomFields `_min` / `_max` (Float-Cast, nur wenn der TMMS-Wert nicht NULL oder leer ist). Im Produktivkatalog betrifft das 18 Produkte.
- **TYPE_MAP kennt jetzt `'input'` als Synonym für `'text'`.** 56 Live-Produkte tragen `fieldtype=input` und wurden bisher still über den Fallback gemappt. Jetzt gibt es dafür einen ausdrücklichen Eintrag und keine Warnung.
- **`mapType()` loggt Warnungen bei unbekanntem Typ.** Bisher fiel es still auf `'text'` zurück. Jetzt bleibt eine Audit-Spur (Logger-warning mit `tmmsType`-Kontext), an der sich Schema-Drift in künftigen TMMS-Versionen erkennen lässt.

### Hinzugefügt

- **`TmmsMigrationServiceTest`** mit 7 Mapping-Tests (Type-Map, Select-CSV-Konvertierung, Edge-Cases). Vorher war nur der Command getestet, der Service selbst nicht.

## [1.4.2] - 2026-05-12

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. **Pflichtprüfung vor dem Live-Update:** Per SQL sicherstellen, dass keine Produkt-`custom_fields` Werte unter `rc_custom_field_*_min` / `rc_custom_field_*_max` tragen (Spalte `product_translation.custom_fields`). Gibt es Treffer (> 0), wird angehalten und bewusst entschieden, wie es weitergeht.

### Behoben

- **`plugin:update` läuft auch bei Installationen mit historisch falschem CustomField-Type durch.** Auf Shops, deren v1.0.x den `_min`/`_max`-Type als `text` statt `float` angelegt hatte, brach das Upgrade auf v1.4.1 ab, mit `The field "type" of "custom_field" is immutable and cannot be updated` (10 Errors, 5 Indizes × 2 Felder). Shopware verbietet, den `type` zu ändern.
- **Der Installer gleicht Type-Drift jetzt aus.** Vor `enrichWithExistingIds` werden Felder gelöscht, deren Type in der Datenbank nicht zum Type im Code passt. Der folgende Upsert legt sie mit korrektem Type neu an.
- **Strukturiertes Logging:** Drift-Vorgänge landen mit `oldType` / `newType` / `droppedCount` als info-Eintrag im Log (Audit-Spur).
- **Datenverlust-Prüfung verpflichtend:** Weil Shopware den `type` nicht ändern lässt, bleibt nur Löschen und Neuanlegen. Die Feldwerte in `product.custom_fields` gehen für die betroffenen Keys verloren. Der Pre-Check steht deshalb im CHANGELOG.

## [1.4.1] - 2026-05-12

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. Keine Datenbank-Migration. Die Werte in `product.custom_fields` bleiben unberührt: Sie hängen am Feldnamen, und die Field-IDs bleiben dank ID-Lookup stabil.

### Behoben

- **`plugin:update` von Bestandsinstallationen schlägt nicht mehr fehl.** Auf Shops mit bereits installierter v1.0.0 / v1.1.0 brach das Upgrade auf v1.4.0 mit `Duplicate entry 'rc_custom_fields_enabled' for key 'uniq.custom_field.name'` ab. `customFieldSetRepository->upsert()` versuchte alle 91 Felder als INSERT anzulegen, ohne bestehende am Namen zu erkennen.
- **`CustomFieldInstaller` löst drei Ebenen idempotent auf:**
  1. **Field-IDs** per Name (EqualsAnyFilter auf `custom_field.name`)
  2. **Set-ID** per Name (EqualsFilter auf `custom_field_set.name`)
  3. **Set-Relation-ID** der product-Bindung (per Association `relations` mitgeladen), weil sonst `uniq.custom_field_set_relation (set_id, entity_name)` beim erneuten Upsert kollidiert
- Damit ist `install()` wirklich idempotent, mehrfache Aufrufe haben keine Seiteneffekte. Es "läuft" also nicht bloß "durch", sondern behält stabile Field-IDs und die Relation-ID.
- **Zweite Repository-Dependency:** `custom_field.repository` im Konstruktor von `CustomFieldInstaller` (services.xml + `RcCustomFields::getInstaller()`).

## [1.4.0] - 2026-05-12

> **Deployment:** `composer install && php bin/console plugin:update RcCustomFields && bin/build-administration.sh && php bin/console cache:clear`. Das Plugin-Update legt die 5 zusätzlichen Custom-Felder per Upsert idempotent an.

### Hinzugefügt
- **Vue-Admin-Modul `rc-cf-product-tab`** als neuer Tab "Kundeneingaben" in der Produkt-Detailansicht (`sw.product.detail`). Es greift per Twig-Override auf `sw_product_detail_content_tabs` und registriert eine neue Route `sw.product.detail.rc-custom-fields`.
- **Konfigurierbar pro Feld:** Aktiv-Toggle, Label, Typ (text/number/textarea/date/time/datetime/checkbox/select), Required, Placeholder, Einheit, Min/Max (für number+date+time+datetime), Optionen (für select). Gerendert wird abhängig vom Typ: Min/Max nur bei Typen mit Range-Semantik, Optionen nur bei Select.
- **Live-Vorschau-Karte**, die alle aktiven Felder mit Label, Typ und Required-Marker auflistet.
- **FIELD_COUNT auf 10 erhöht** (vorher 5), als PHP-Konstante `RcCustomFields::FIELD_COUNT`. Die Twig-Templates (Buy-Widget und Line-Item in der Storefront) iterieren jetzt über `1..10`. PayloadConverter, OrderLineItemWrittenSubscriber und TmmsMigrationService richten sich bereits nach FIELD_COUNT und brauchten keine Änderung.
- **Snippets DE/EN** vollständig (Tab-Titel, Field-Editor, Vorschau, Type-Labels).

### Datenspeicherung
- **Bleibt im bestehenden CustomField-Schema** (`rc_custom_field_{i}_*`). Eine eigene Entity gibt es nicht, sie wird nicht gebraucht. So arbeiten PayloadConverter, Order-Subscriber, Dokumenten-Twig und TMMS-Migration unverändert weiter.

### Bewusste Einschränkungen (verschoben auf v1.5.0+)
- **Drag-and-Drop-Reihenfolge:** Die Felder erscheinen in fester Reihenfolge 1..10. Eine änderbare Reihenfolge bräuchte eine eigene Persistenz-Schicht.
- **Cart-Edit-UI** (Werte im Warenkorb ändern) bleibt offen.

## [1.3.0] - 2026-05-12

> **Deployment:** `composer install && php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. Keine Datenbank-Migration im Plugin. Die TMMS-Migration ist eine optionale CLI-Operation.

### Hinzugefügt

- **`OrderLineItemWrittenSubscriber`** auf `CartConvertedEvent`. Er hängt die RcCustomFields-Payload-Werte (`rcCustomField{i}Value/Label`) noch VOR dem Speichern der Bestellung in `order_line_item.custom_fields` ein. Der Schlüssel ist `rc_custom_fields`, eine Liste von `{index, label, value}`. Custom-Fields anderer Plugins bleiben unangetastet.
- **`PayloadConverter`-Service**, der Payload-Extraktion und Custom-Field-Merge kapselt. Pure-function, ohne DAL testbar.
- **Twig-Override** `@RcCustomFields/documents/base.html.twig`. Es erweitert `@Framework/documents/base.html.twig` und rendert die Custom-Field-Einträge pro Position automatisch in **Rechnung, Lieferschein, Storno**.

### Hinzugefügt

- **CLI-Command `bin/console rc:migrate-tmms`** mit drei Modi:
  - **`--dry-run`**: listet Kandidaten (Produkt-Nummer, ID, Anzahl der TMMS-Felder), ohne in die Datenbank zu schreiben
  - **Default**: migriert die TMMS-Produkt-Custom-Fields ins RcCustomFields-Schema. Jedes Produkt läuft in einer eigenen DBAL-Transaktion, ein Fehler hinterlässt also keinen halben Zwischenstand
  - **`--rollback`**: setzt `rc_custom_fields_enabled` auf false für alle Produkte, die diese Migration markiert hat (Marker `rc_custom_fields_migrated_from_tmms` mit ISO-8601-Timestamp)
- **`TmmsMigrationService`** mit Type-Map (TMMS-fieldtype → RcCustomFields-type) und Toleranz gegenüber Schema-Drift: Fehlende TMMS-Felder werden als leerer String migriert statt hart abzubrechen.
- **Idempotenz:** Ein zweiter Lauf erzeugt keinen neuen Stand, weil Lesen und Schreiben auf denselben Custom-Field-Key zielen.

### Behoben (latente Bugs)

- **`CustomFieldTypes::TEXT_LONG`** gibt es in der aktuell installierten Shopware-Version nicht. Die Konstante in `CustomFieldInstaller.php:221` (Field `rc_custom_field_{i}_options`) ist auf `CustomFieldTypes::TEXT` korrigiert. Für eine Eingabe per Textarea ändert sich dadurch nichts.
- **PHPStan-Generic-Typen** in `CustomFieldInstaller::__construct()` und `RcCustomFields::getInstaller()` ergänzt (PHPDoc `@var EntityRepository<CustomFieldSetCollection>`).
- **`getId()` auf `Entity`** im Uninstall-Pfad: ein Guard mit `instanceof CustomFieldSetEntity` statt der Prüfung auf null.

### Nächste Schritte (extern)

Damit kann **Phase 2.3 vollzogen werden**:

1. DB-Backup
2. `bin/console rc:migrate-tmms --dry-run`, Kandidaten verifizieren
3. `bin/console rc:migrate-tmms`, Migration ausführen
4. End-to-End-Test: Produkte mit migrierten Custom-Fields bestellen
5. `TmmsProductCustomerInputs` deaktivieren und deinstallieren
6. `RcCartSplitter` deaktivieren und das Verzeichnis nach `old/` verschieben (Phase 6 des Gesamtplans)

### Bewusste Einschränkungen (verschoben auf v1.4.0)

- **Cart-Edit-UI:** Werte lassen sich im Offcanvas und auf der Warenkorbseite noch nicht ändern, dafür wäre ein Eingriff ins Frontend-JS nötig. Nach AddToCart sind die Eingaben vorerst nur lesbar.
- **Erweiterung der Bestellübersicht im Admin:** Die Werte aus `order_line_item.custom_fields` sind über den normalen Custom-Field-Mechanismus im Admin sichtbar. Ein eigenes Vue-Modul, das sie prominent anzeigt, steht noch aus.

## [1.2.0] - 2026-05-11

> **Deployment:** `php bin/console plugin:update RcCustomFields` (spielt die neuen Feldtypen in das CustomFieldSet ein), dazu `php bin/console cache:clear` und `bin/build-storefront.sh` (Twig und JS geändert). Eine Datenbank-Migration im engeren Sinn gibt es nicht. Die Update-Methode des Plugins ruft den Installer auf, und der spielt die erweiterten Felder per Upsert idempotent ein.

### Hinzugefügt
- **Fünf zusätzliche Feldtypen**: `date`, `time`, `datetime`, `checkbox`, `select`. Das Dropdown für den Feld-Typ im CustomFieldSet bietet sie jetzt an. Die Storefront rendert sie mit nativen HTML5-Inputs (`<input type="date|time|datetime-local">`, `<input type="checkbox">`, `<select>`). Die vorhandenen Typen `text`, `number` und `textarea` bleiben, wie sie sind.
- **Neues Admin-Feld `rc_custom_field_{i}_options`** (Typ TEXT_LONG). Hier werden die Select-Optionen als „value|label"-Liste gepflegt, eine Option pro Zeile. Das Storefront-Template zerlegt die Zeilen und rendert daraus `<option>`-Tags.
- **PSR-3-Logging im `CustomFieldInstaller`**: info bei Install und Uninstall samt Anzahl der Felder, error bei DAL-Fehlern mit der Exception-Klasse. Log-Context `ruhrcoder_custom_fields.installer`, NullLogger als Default.
- **Quality-Toolchain vervollständigt** (`composer.json`): ausdrückliches `php: >=8.2`-Constraint, Unterstützung für Shopware 6.7/6.8, `shopware/storefront` als Require, `require-dev` mit PHPUnit/PHPStan/CS-Fixer und eine vollständige `scripts`-Sektion (`test`, `phpstan`, `cs-fix`, `cs-check`, `test:js`, `quality`). Vorher gab es nur `test:js`.
- **`phpunit.xml.dist`** angelegt (PHPUnit-Konfiguration mit Unit-Test-Suite).
- **CI-Pipeline mit GitHub Actions**: `.github/workflows/ci-php.yml` mit Security-Audit, CS-Fixer, PHPStan Level 8, PHPUnit und JS-Tests. Sie startet bei Push und PR auf `main`.
- **Deutlich mehr Tests:**
  - `CustomFieldInstallerTest` von 4 auf 8 Tests erweitert: Schema-Vertrag (5 Felder × 9 Definitionen + Enabled-Flag), Verfügbarkeit aller 8 Type-Optionen im Select, Logger-Pfade (info/error), Fehler-Pfad mit Rethrow.
  - `PayloadSanitizerSubscriberTest` neu (7 Tests): HTML-Tag-Strip in `rcCustomField*`-Keys, fremde Keys unangetastet, Nicht-String-Werte werden übersprungen, fremde Routen werden ignoriert.
  - `TwigTemplateContractTest` neu (9 Tests): Pinning für alle Feldtyp-Zweige im Template (`textarea`, `select`, `checkbox`, `date/time/datetime`, `number`/`text`), id-controller-Marker, Payload-Konvention.

### Behoben
- **Checkbox-Validation- und Hash-Bug im JS-Plugin:** `input.value` einer Checkbox ist immer `'1'`, egal ob sie angekreuzt ist. Die Validierung (`empty = input.value.trim() === ''`) hätte deshalb nicht angekreuzte Pflicht-Checkboxen fälschlich als „gefüllt" gemeldet. Außerdem hätte die deterministische LineItem-ID angekreuzte und nicht angekreuzte Checkboxen gleich gehasht. Der Fix liest bei `input.type === 'checkbox'` den Wert über `input.checked` als `'1'` oder `''`. Wirksam wird das erst mit den neuen Feldtypen aus 1.2.0, vorher gab es keine Checkbox.

### Geändert
- `RcCustomFields::getInstaller()`: Null-Check auf den Container ergänzt (Stabilität in Test- und Lifecycle-Kontexten). Der Logger kommt aus dem Container, mit `NullLogger` als Fallback.
- `CustomFieldInstaller`: nach der Stepdown-Regel umgebaut. `install()` delegiert an `buildEnabledFlag()` und `buildFieldDefinitions(int $i)`, jede Methode bleibt bei einer Sache, und der 100-Zeilen-Array-Block ist verschwunden.

### Hinweis zu offenen Punkten
- Warenkorb-Bearbeitung mit Dokumenten-Integration, die Migration der TMMS-Daten und ein eigenes Admin-Modul sind eigenständige Vorhaben und bleiben für Phase 2.5 und später.

## [1.1.0]

> **Deployment:** `bin/build-storefront.sh` nach Sync (JS geändert), keine Datenbank-Migration.

### Geändert
- Der Listener hört jetzt auf das generische Event `rcSuffixChanged` (Plugin-Interaktionsprotokoll v2). Die fest eingetragene Liste `[rcMeterLengthChanged, rcColorPickerChanged]` in `init()` ist entfallen. Sibling-Plugins, die den Protokoll-Vertrag erfüllen, lösen damit die Neuberechnung der LineItem-ID aus, ohne dass hier Code geändert werden muss. RcCustomFields feuert selbst kein Suffix-Event, ein Schutz gegen Selbstaufrufe ist also nicht nötig. Die statische Konstante `RcCustomFieldsPlugin.SUFFIX_CHANGED_EVENT` ist nach außen sichtbar.

### Hinzugefügt
- `destroy()`-Methode, die ihre Listener sauber wieder abmeldet. Dazu die erste JS-Test-Suite (`tests/Js/rc-custom-fields.suffix-event.test.mjs`), die die Konstante `SUFFIX_CHANGED_EVENT` verankert und den Listener-Trigger prüft.
- `composer test:js` als erstes Quality-Gate-Skript.

# 1.0.0

- Hinzugefügt: Kundeneingaben pro Produkt mit deterministischer Berechnung der LineItem-ID (FNV-1a Hash über die sortierten Feldwerte plus alle `rc*Suffix`-Attribute).
- Hinzugefügt: Generisches Suffix-Protokoll für das Zusammenspiel der Plugins (RcDynamicPrice und RcColorPicker fließen in den Hash ein).
- Hinzugefügt: Pflichtfeld-Validierung mit `is-invalid`-Markierung und `invalid-feedback`.
- Hinzugefügt: `stackSameValues`-Toggle. Steht er auf `false`, wird jede Eingabe per Timestamp zur eigenen Position.
