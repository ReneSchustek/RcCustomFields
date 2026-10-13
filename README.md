# RcCustomFields

Ein Shopware-6-Plugin, das pro Produkt konfigurierbare Eingabefelder für den Kunden hinzufügt.

---

## Was das Plugin macht

Manche Produkte brauchen Zusatzangaben vom Kunden, etwa einen Gravurtext, individuelle Maße oder Materialeigenschaften. Shopware hat dafür keine allgemeine Lösung. Es bleiben Varianten, die bei vielen Kombinationen aufwändig werden, oder individuelle Texte, die zu eingeschränkt sind.

Das Plugin installiert dafür ein Custom-Field-Set `rc_custom_fields` für Produkte. Pro Produkt lassen sich mehrere Eingabefelder aktivieren und konfigurieren. Jedes Feld bekommt einen Typ (Text, Zahl, mehrzeiliger Text), eine Bezeichnung, einen Platzhalter und eine Einheit, auf Wunsch auch eine Prüfung auf Min/Max.

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

Konfiguriert wird direkt am Produkt, im Admin unter **Produkte → [Produkt] → Zusatzfelder → Kundeneingaben**.

### Globale Einstellung pro Produkt

| Feld | Beschreibung |
|------|-------------|
| Kundeneingaben aktivieren | Schaltet das gesamte Feature für dieses Produkt an |

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
