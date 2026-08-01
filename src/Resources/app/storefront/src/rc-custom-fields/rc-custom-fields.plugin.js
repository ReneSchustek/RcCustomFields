const { PluginBaseClass } = window;

/**
 * RC Custom Fields Plugin
 *
 * Überwacht Kundeneingabe-Felder im Buy-Form und berechnet daraus eine
 * deterministisch eindeutige Warenkorb-ID. Gleiche Eingaben → gleiche ID → Mengenerhöhung.
 * Unterschiedliche Eingaben → andere ID → separate Warenkorb-Position.
 *
 * Alle Inputs liegen INNERHALB des Buy-Forms (kein form=-Attribut-Trick),
 * daher funktioniert new FormData(form) in Shopware 6.7+ korrekt.
 */
export default class RcCustomFieldsPlugin extends PluginBaseClass {

    // Generisches Suffix-Event aus dem Plugin-Interaktionsprotokoll. RcCustomFields hört darauf
    // und feuert es selbst — aber ausschließlich beim WECHSEL der ID-Hoheit. Diese Bedingung ist
    // der Self-Loop-Guard: Ein Zustand, der sich nicht ändert, löst kein weiteres Event aus.
    static SUFFIX_CHANGED_EVENT = 'rcSuffixChanged';

    init() {
        this._productId  = this.el.dataset.productId;
        this._stackSame  = this.el.dataset.stackSameValues !== '0';
        this._form       = this.el.closest('form');

        if (!this._form || !this._productId) {
            return;
        }

        this._lineItemIdInput = this._form.querySelector(
            `[name="lineItems[${this._productId}][id]"]`
        );

        if (!this._lineItemIdInput) {
            return;
        }

        this._inputs = Array.from(this.el.querySelectorAll('.rc-cf-input'));

        // Payload-Marker für die ID-Hoheit. Twig rendert ihn leer und ohne
        // `data-rc-id-controller` am Wrapper — Startzustand also: keine Hoheit. `_idAuthority`
        // spiegelt genau diesen Stand, damit `_updateLineItemId()` beim Init keinen
        // Zustandswechsel meldet und kein überflüssiges Event feuert.
        this._activeMarker = this._form.querySelector(
            `[name="lineItems[${this._productId}][payload][rcCustomFieldsActive]"]`
        );
        this._idAuthority = false;

        // Generisches Suffix-Protokoll: ID neu berechnen, wenn ein Sibling-Plugin seinen Suffix ändert.
        // Bound Reference für sauberen destroy()-Cleanup.
        this._boundOnSuffixChanged = () => this._updateLineItemId();
        this._form.addEventListener(
            RcCustomFieldsPlugin.SUFFIX_CHANGED_EVENT,
            this._boundOnSuffixChanged,
        );

        this._registerEvents();
        this._updateLineItemId();

        this.$emitter.publish('afterInit');
    }

    destroy() {
        if (this._form && this._boundOnSuffixChanged) {
            this._form.removeEventListener(
                RcCustomFieldsPlugin.SUFFIX_CHANGED_EVENT,
                this._boundOnSuffixChanged,
            );
        }

        if (typeof super.destroy === 'function') {
            super.destroy();
        }
    }

    _registerEvents() {
        this._inputs.forEach(input => {
            input.addEventListener('input',  () => this._updateLineItemId());
            input.addEventListener('change', () => this._updateLineItemId());
        });

        // Auch bei Buy-Button-Klick validieren
        const buyBtn = this._form.querySelector('[type="submit"]');
        if (buyBtn) {
            this._form.addEventListener('submit', (e) => this._onFormSubmit(e));
        }
    }

    _onFormSubmit(event) {
        if (!this._validateRequiredFields()) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }

    _validateRequiredFields() {
        let valid = true;

        this._inputs.forEach(input => {
            if (!input.classList.contains('rc-cf-required')) return;

            // Checkbox: required = muss angekreuzt sein. Andere Typen: required = nicht-leerer Wert.
            const empty = input.type === 'checkbox' ? !input.checked : input.value.trim() === '';
            input.classList.toggle('is-invalid', empty);

            if (empty) {
                valid = false;

                // Fehlermeldung erzeugen falls noch nicht vorhanden
                let feedback = input.nextElementSibling;
                if (!feedback || !feedback.classList.contains('rc-cf-error')) {
                    feedback = document.createElement('div');
                    feedback.className = 'invalid-feedback rc-cf-error';
                    feedback.textContent = input.dataset.errorMsg || 'Field required.';
                    input.after(feedback);
                }
            } else {
                input.classList.remove('is-invalid');
                const feedback = input.nextElementSibling;
                if (feedback && feedback.classList.contains('rc-cf-error')) {
                    feedback.remove();
                }
            }
        });

        return valid;
    }

    /**
     * Meldet die ID-Hoheit an die Sibling-Plugins: DOM-Attribut für die Storefront, Payload-Marker
     * für den Server. Beide müssen dasselbe sagen.
     *
     * Liefert, ob sich der Zustand geändert hat — nur der Wechsel darf ein Event auslösen, sonst
     * schaukeln sich die Plugins gegenseitig hoch.
     */
    _setIdAuthority(claimed) {
        if (this._idAuthority === claimed) {
            return false;
        }

        this._idAuthority = claimed;

        if (claimed) {
            this.el.setAttribute('data-rc-id-controller', 'true');
        } else {
            this.el.removeAttribute('data-rc-id-controller');
        }

        if (this._activeMarker) {
            this._activeMarker.value = claimed ? '1' : '';
        }

        return true;
    }

    _updateLineItemId() {
        if (!this._stackSame) {
            // Immer neue Position: timestamp-basierte UUID. Das ist ID-Hoheit unabhängig von
            // Eingaben — die Position soll sich nie mit einer anderen stapeln.
            this._lineItemIdInput.value = this._timestampUuid(this._productId);
            this._setIdAuthority(true);
            return;
        }

        const values = this._collectValues();
        const hasValues = Object.values(values).some(v => v !== '');

        if (hasValues) {
            // Fremde Suffixe fließen mit ein, damit gleiche Eingaben bei unterschiedlicher
            // Länge nicht auf eine Position fallen.
            this._lineItemIdInput.value = this._computeLineItemId(this._productId, values);
        }

        const changed = this._setIdAuthority(hasValues);

        if (!hasValues && changed) {
            // Hoheit abgegeben: die ID trägt noch unseren Hash und muss zurück auf die Produkt-ID.
            // Das Event fordert die Sibling-Plugins auf, ihre Suffixe neu einzurechnen.
            this._lineItemIdInput.value = this._productId;
            this._form.dispatchEvent(new CustomEvent(RcCustomFieldsPlugin.SUFFIX_CHANGED_EVENT, {
                detail: { source: 'rcCustomFields' },
            }));
        }

        // Ohne eigene Werte bleibt die ID unangetastet — sie gehört dann dem nächsten Plugin
        // der Prioritätskette.

        this.$emitter.publish('lineItemIdUpdated', { id: this._lineItemIdInput.value });
    }

    /**
     * Liest alle Feldwerte einheitlich aus.
     * Schlüssel = data-field-key des Inputs (f1, f2, ...).
     *
     * Typ-spezifische Lesepfade:
     *   - checkbox: '1' wenn angekreuzt, '' wenn nicht — `input.value` waere immer '1' und nutzlos
     *   - sonst: getrimmter String-Wert, HTML-Tags entfernt (Defense-in-Depth)
     */
    _collectValues() {
        const values = {};

        this._inputs.forEach(input => {
            const key = input.dataset.fieldKey || input.name;

            if (input.type === 'checkbox') {
                values[key] = input.checked ? '1' : '';
                return;
            }

            values[key] = input.value.trim().replace(/<[^>]*>/g, '');
        });

        return values;
    }

    /**
     * Berechnet eine deterministische UUID-ähnliche ID aus Produkt-ID + Feldwerten.
     * Gleiche Produkt-ID + gleiche Werte → immer dieselbe ID.
     * Andere Werte → andere ID.
     *
     * Basis: FNV-1a 32-bit (schnell, gut verteilt, kein Crypto-API nötig)
     * Format: UUID v4-ähnlich (kompatibel mit Shopware-Internals)
     */
    _computeLineItemId(productId, fieldValues) {
        // Sortierte Werte + alle rc*Suffix-Attribute fuer Determinismus
        const sortedKeys = Object.keys(fieldValues).sort();
        const fieldsStr  = sortedKeys.map(k => `${k}=${fieldValues[k]}`).join('\x00');
        const allSuffixes = this._collectAllSuffixes();
        const valueStr   = allSuffixes
            ? (allSuffixes + '\x00' + fieldsStr)
            : fieldsStr;

        const h1 = this._fnv32a(valueStr);
        const h2 = this._fnv32a(productId + valueStr);

        const h1Hex = h1.toString(16).padStart(8, '0');
        const h2Hex = h2.toString(16).padStart(8, '0');

        // Ersten 16 Zeichen der Produkt-UUID behalten (Rückverfolgbarkeit),
        // letzten 16 Zeichen durch Hash ersetzen.
        const productHex = productId.replace(/-/g, ''); // 32 Hex-Zeichen
        const newHex     = productHex.substring(0, 16) + h1Hex + h2Hex;

        return [
            newHex.substring(0, 8),
            newHex.substring(8, 12),
            newHex.substring(12, 16),
            newHex.substring(16, 20),
            newHex.substring(20, 32),
        ].join('-');
    }

    /**
     * Sammelt alle rc*Suffix-Data-Attribute vom Formular.
     * Damit werden Suffixe anderer Plugins automatisch in den Hash einbezogen.
     */
    _collectAllSuffixes() {
        const parts = [];
        const dataset = this._form.dataset;

        for (const key in dataset) {
            if (key.startsWith('rc') && key.endsWith('Suffix') && dataset[key]) {
                parts.push(key + '=' + dataset[key]);
            }
        }

        return parts.sort().join('\x00');
    }

    /**
     * FNV-1a 32-bit Hash (Fowler–Noll–Vo)
     */
    _fnv32a(str) {
        let hash = 0x811c9dc5;

        for (let i = 0; i < str.length; i++) {
            hash ^= str.charCodeAt(i);
            hash  = Math.imul(hash, 0x01000193);
            hash >>>= 0;
        }

        return hash;
    }

    /**
     * Erzeugt eine einmalige UUID basierend auf Produkt-ID + Timestamp + Zufall.
     * Wird verwendet wenn stackSameValues=false ist.
     */
    _timestampUuid(productId) {
        const productHex = productId.replace(/-/g, '');
        const ts  = Date.now().toString(16).padStart(12, '0');
        const rnd = Math.floor(Math.random() * 0xFFFFFFFF).toString(16).padStart(8, '0');
        const newHex = productHex.substring(0, 12) + ts + rnd;

        return [
            newHex.substring(0, 8),
            newHex.substring(8, 12),
            newHex.substring(12, 16),
            newHex.substring(16, 20),
            newHex.substring(20, 32),
        ].join('-');
    }
}
