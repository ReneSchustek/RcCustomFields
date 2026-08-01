# Changelog (EN)

## [1.5.3] - 2026-07-20 — Server-side required validation + order-detail display (deep review)

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. No schema break, no migration.

### Fixed

- **Required fields are now enforced server-side (optional):** Previously only JavaScript checked whether required fields were filled — trivially bypassable (disable JavaScript or POST directly to the add-to-cart route), so empty required fields reached the order. A new cart validator checks every line item against the product's active required fields and blocks cart/checkout with a clear message per empty required field. Enabled via the previously dead switch **"Enforce required fields before adding to cart"** (default: off — no behaviour change for existing shops). Deliberately presence-only, no type/min/max, to avoid false checkout blocks across formats/locales.
- **Customer inputs show up again in account → order detail:** The order-detail view read inputs only from the transient cart payload. Older (TMMS-migrated) orders never had that payload — their inputs appeared only in the PDF, not in the account order history. The view now uses the same defensive dual-schema reader as the PDF (`rc_custom_fields` snapshot **and** legacy TMMS data); the payload path stays a fallback for the live cart. Controlled via the previously dead switch **"Show inputs in order history"**.

### Changed

- **Memory-safe TMMS migration:** `scanCandidates`/`rollback` loaded the entire product catalog into memory at once → OOM/timeout on large catalogs. Now iterated in batches of 100 products (`RepositoryIterator`).
- Removed the empty leftover file `test.txt` from the plugin root.

## [1.5.2] - 2026-07-20 — Server-side sanitizer reactivated (deep review)

> **Deployment:** `php bin/console cache:clear`.

### Fixed

- **Server-side capping of customer input works again:** The payload sanitizer checked the route `frontend.checkout.cart.add`, which does not exist in Shopware 6.7/6.8 — so it **never ran**. Corrected to the real add-to-cart route `frontend.checkout.line-item.add`. `strip_tags` was replaced by a plain length cap (max. 1000 chars): `strip_tags` would have destroyed legitimate special characters (an engraving text "length < 10mm" would be cut off at the `<`); XSS protection is handled at output (all output is escaped).

## [1.5.1] - 2026-07-18 — Sibling auto-split keeps working with empty custom fields

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. No schema break, no migration.

### Fixed

- **`rcCustomFieldsActive` was rendered as `1` unconditionally** whenever a product had custom fields — even when every field was empty. Through the plugin interaction protocol this permanently claimed line-item ID authority against sibling plugins without ever exercising it. On a product with meter-price auto-split (`max_rest`) the sibling split then silently degraded to a hint: no price, "Add to cart" disabled — longer cuts were **not orderable**, even though no field had been filled in. The marker and the `data-rc-id-controller` DOM attribute are now **coupled to fill state** by the JavaScript: empty → no authority, the sibling auto-split runs; filled → RcCustomFields takes ID authority as before.
- **Inputs without JavaScript:** `PayloadConverter` now reads the custom-field values from the line-item payload independently of the marker — the value is authoritative, not the (JS-set) marker.

## [1.5.0] - 2026-07-15 — Defensive dual-schema read (Twig helper)

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`. No migration, no schema break.

### Added

- **Twig helper `rc_cf_entries(customFields)`** — reads an order line item's customer inputs **defensively from both schemas** and returns a unified list `{index, label, value}`: first the plugin's own nested `rc_custom_fields` array, falling back to the flat TMMS snapshot of old orders (`tmms_customer_input_{slot}_value/label`). Orders placed **before** the TMMS cutover keep rendering correctly on invoice/delivery-note/cancellation documents even after TMMS is uninstalled — no manual per-order maintenance. TMMS is read only, never written.
- Twig test `is rc_cf_filled` (line item has usable inputs).
- New service `Service\CustomFieldsReader` (Twig-free, pure function, unit-tested); the Twig extension only delegates.

### Changed

- The document override (invoice/delivery note/…) now uses `rc_cf_entries(...)` instead of accessing `rc_custom_fields` directly. Behaviour for existing (rc) orders is unchanged (pinning test); TMMS legacy orders now render as well.

## [1.4.5] - 2026-07-14 — Customer inputs appear on invoices again

> **Deployment:** `php bin/console plugin:update RcCustomFields && php bin/console cache:clear`.

### Fixed

- **Customer inputs appeared on no document at all.** The document template overrode the block `document_line_items_table_row_label` — **this block does not exist in Shopware** (plural instead of singular). Twig silently ignores an override of an unknown block: no error, no warning, no failing test. The inputs were therefore missing from **invoice, delivery note, cancellation and credit note** — for cut-to-length goods, that means the ordered length.

  Corrected to `document_line_item_table_column_label`. Deliberately the **column** block, not the row block: the latter *is* the `<td>`, so anything appended after `{{ parent() }}` would land outside the table cell.

### Added

- **Pinning test** `DocumentTemplateContractTest` — pins the target template, the block name and the `parent()` call. Complemented by the Twig block contract checker, which validates block names against the actual Shopware sources.

### Note on impact

All gates were green while the bug was present: PHPStan level 8, PHP CS Fixer, PHPUnit, `composer audit`, GitHub CI. The bug would only have surfaced once a customer complained about an invoice without dimensions. **This fix is mandatory before the TMMS cutover** — otherwise every document loses its customer inputs.

## [1.2.0] - 2026-05-11

> **Deployment:** `php bin/console plugin:update RcCustomFields` (new field types are upserted into the custom field set) + `php bin/console cache:clear` + `bin/build-storefront.sh` (Twig + JS changed). No raw database migration — the plugin update method invokes the installer which idempotently upserts the extended fields.

### Added
- **Five additional field types**: `date`, `time`, `datetime`, `checkbox`, `select`. Admin selectable in the field-type dropdown of the custom-field set. Storefront renders them with HTML5-native inputs (`<input type="date|time|datetime-local">`, `<input type="checkbox">`, `<select>`). Existing types `text`, `number`, `textarea` remain untouched.
- **New admin field `rc_custom_field_{i}_options`** (type TEXT_LONG): allows configuration of select options as a "value|label" list, one option per line. The storefront template parses lines and renders `<option>` tags.
- **PSR-3 logging in `CustomFieldInstaller`** (info on install/uninstall + field count, error on DAL failures with exception class). Log context `ruhrcoder_custom_fields.installer`. NullLogger as default.
- **Quality toolchain completed** (`composer.json`): explicit `php: >=8.2` constraint, Shopware 6.7/6.8 support, `shopware/storefront` require, `require-dev` with PHPUnit/PHPStan/CS-Fixer, full `scripts` section (`test`, `phpstan`, `cs-fix`, `cs-check`, `test:js`, `quality`). Previously only `test:js`.
- **`phpunit.xml.dist`** added (PHPUnit configuration with unit test suite).
- **GitHub Actions CI pipeline**: `.github/workflows/ci-php.yml` with security audit, CS-Fixer, PHPStan level 8, PHPUnit and JS tests. Triggers on push/PR to `main`.
- **Major test expansion:**
  - `CustomFieldInstallerTest` extended from 4 to 8 tests: schema contract (5 fields × 9 definitions + enabled flag), availability of all 8 type options in the select, logger paths (info/error), error path with rethrow.
  - `PayloadSanitizerSubscriberTest` new (7 tests): HTML tag stripping on `rcCustomField*` keys, foreign keys untouched, non-string values skipped, foreign routes ignored.
  - `TwigTemplateContractTest` new (9 tests): pinning for every field-type branch in the template (`textarea`, `select`, `checkbox`, `date/time/datetime`, `number`/`text`), id-controller marker, payload convention.

### Fixed
- **Checkbox validation and hash bug in the JS plugin:** A checkbox's `input.value` is always `'1'` regardless of `checked` state. Validation (`empty = input.value.trim() === ''`) would therefore have falsely reported "filled" for unchecked required checkboxes, and the deterministic line item ID would have hashed checked and unchecked checkboxes identically. Fix: `input.type === 'checkbox'` branch using `input.checked` → `'1'`/`''` as read path. Only relevant with the new field types from 1.2.0; checkbox wasn't an option before.

### Changed
- `RcCustomFields::getInstaller()`: container null-check added (stability in test/lifecycle contexts), logger is pulled from the container (fallback `NullLogger`).
- `CustomFieldInstaller`: stepdown refactor — `install()` delegates to `buildEnabledFlag()` and `buildFieldDefinitions(int $i)` (cohesion per method, no more 100-line array block).

### Note on remaining open items
- **RC02** (cart editing + document integration), **RC03** (TMMS data migration), **RC04** (own admin module) are independent feature streams and remain phase 2.5+.

## [1.1.0]

> **Deployment:** `bin/build-storefront.sh` after sync (JS changed), no database migration.

### Changed
- Listener migrated to the generic `rcSuffixChanged` event (plugin interaction protocol v2). The hard-coded list `[rcMeterLengthChanged, rcColorPickerChanged]` in `init()` is gone — sibling plugins that honour the protocol contract now trigger the LineItem ID recomputation here without any code change. RcCustomFields does not emit a suffix event itself, so no self-loop guard is required. Static constant `RcCustomFieldsPlugin.SUFFIX_CHANGED_EVENT` exposed.

### Added
- `destroy()` method with proper listener cleanup. First JS test suite (`tests/Js/rc-custom-fields.suffix-event.test.mjs`) locks in the `SUFFIX_CHANGED_EVENT` constant and verifies the listener trigger.
- `composer test:js` as the first quality-gate script.

# 1.0.0

- Added: Customer inputs per product with deterministic LineItem ID computation (FNV-1a hash over sorted field values plus all `rc*Suffix` attributes).
- Added: Generic suffix protocol for plugin interaction (RcDynamicPrice, RcColorPicker mix into the hash).
- Added: Required-field validation with `is-invalid` marker and `invalid-feedback`.
- Added: `stackSameValues` toggle — when `false`, every input creates a separate position via timestamp UUID.
