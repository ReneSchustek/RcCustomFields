# RcCustomFields

Shopware 6 Plugin — fügt pro Produkt konfigurierbare Kundeneingabefelder hinzu.

---

## Was das Plugin macht

Einige Produkte erfordern Zusatzangaben vom Kunden — z. B. Gravurtext, individuelle Maße oder Materialeigenschaften. Shopware bietet dafür keine generische Lösung: entweder Varianten (aufwändig bei vielen Kombinationen) oder individuelle Texte (zu eingeschränkt).

Dieses Plugin installiert ein Custom-Field-Set `rc_custom_fields` für Produkte. Pro Produkt lassen sich bis zu mehrere Eingabefelder aktivieren und konfigurieren. Jedes Feld hat einen Typ (Text, Zahl, mehrzeiliger Text), eine Bezeichnung, einen Platzhalter, eine Einheit sowie optional Min/Max-Validierung.

---

## Voraussetzungen

- Shopware 6.7 oder 6.8
- PHP 8.2+

---

## Installation

```bash
php bin/console plugin:refresh
php bin/console plugin:install --activate RcCustomFields
php bin/console cache:clear
```

---

## Konfiguration

Die Felder werden direkt am Produkt konfiguriert. Im Admin unter **Produkte → [Produkt] → Individuelle Felder → RC Custom Fields — Kundeneingaben**.

### Globale Einstellung pro Produkt

| Feld | Beschreibung |
|------|-------------|
| RC Custom Fields aktivieren | Schaltet das gesamte Feature für dieses Produkt an |

### Einstellungen pro Eingabefeld (Feld 1–N)

| Feld | Beschreibung | Beispiel |
|------|-------------|---------|
| Aktiv | Feld wird dem Kunden angezeigt | — |
| Bezeichnung | Label über dem Eingabefeld | „Gravurtext", „Länge" |
| Typ | Text, Zahl oder mehrzeiliger Text | `text` |
| Pflichtfeld | Eingabe ist vor dem Kauf erforderlich | — |
| Platzhalter | Hinweistext im leeren Feld | „z. B. Max Mustermann" |
| Einheit | Hinter dem Wert angezeigt | `mm`, `cm`, `kg` |
| Mindestwert | Nur bei Zahlenfeldern | `1` |
| Maximalwert | Nur bei Zahlenfeldern | `10000` |

---

## Update

```bash
php bin/console plugin:refresh
php bin/console plugin:update RcCustomFields
php bin/console cache:clear
```

---

## Entwicklung

```bash
composer install
composer quality   # cs-check + phpstan + test
```

---

Entwickelt von [Ruhrcoder](https://ruhrcoder.de)

<!-- TRIAGE-WORKFLOW: auto-managed by triage-deploy.ps1 -->
