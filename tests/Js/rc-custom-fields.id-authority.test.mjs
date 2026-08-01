// Verhaltenstest fuer die ID-Hoheit: Der Payload-Marker `rcCustomFieldsActive` und das
// DOM-Attribut `data-rc-id-controller` haengen an der Befuellung der Custom Fields, nicht am blossen
// Vorhandensein des Plugins. Nur der WECHSEL der Hoheit loest ein Suffix-Event aus (Self-Loop-Guard).
// Geprueft wird ausschliesslich beobachtbares Verhalten ueber _updateLineItemId, nie ein interner
// Methodenaufruf. Zero-Dependency: Node-Standardbibliothek (node:test).

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const sourcePath = join(
    __dirname,
    '..',
    '..',
    'src',
    'Resources',
    'app',
    'storefront',
    'src',
    'rc-custom-fields',
    'rc-custom-fields.plugin.js',
);

// CRLF -> LF normalisieren, damit die Strip-Regex unabhaengig von der Zeilenende-Konvention der
// Working Copy greift (Windows-Checkout ist CRLF, das Docker-Gate LF).
const rawSource = readFileSync(sourcePath, 'utf8').replace(/\r\n/g, '\n');
const stripped = rawSource
    .replace(/^const \{ PluginBaseClass \} = window;\n/m, '')
    .replace(/^export default /m, '');

// PluginBaseClass und CustomEvent als deterministische Stubs im Wrapper-Scope bereitstellen —
// der Plugin-Code loest beide als freie Bezeichner gegen diese Locals auf.
const wrapped = `
    class PluginBaseClass {
        constructor() { this.$emitter = { publish: () => {} }; }
        init() {}
        destroy() {}
    }
    class CustomEvent {
        constructor(type, init) { this.type = type; this.detail = init ? init.detail : undefined; }
    }
    ${stripped}
    return RcCustomFieldsPlugin;
`;

const RcCustomFieldsPlugin = new Function(wrapped)();

const PRODUCT_ID = '019f648beabb724fa46ccc6d4ff2e273';
const UUID_SHAPE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

// Minimales DOM-Element mit Attribut-Buchhaltung.
function fakeEl() {
    const attrs = {};
    return {
        attrs,
        setAttribute(name, value) { attrs[name] = value; },
        removeAttribute(name) { delete attrs[name]; },
        hasAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name); },
    };
}

// Minimales Form mit Event-Buchhaltung; kein dataset -> _collectAllSuffixes iteriert ueber nichts.
function fakeForm() {
    const dispatched = [];
    return {
        dispatched,
        dispatchEvent(event) { dispatched.push(event); return true; },
    };
}

// Baut eine Plugin-Instanz im Zustand nach init(): Marker leer, keine Hoheit, kein Attribut.
function makeInstance({ stackSame = true, inputs = [] } = {}) {
    const instance = Object.create(RcCustomFieldsPlugin.prototype);
    instance.el = fakeEl();
    instance._form = fakeForm();
    instance._activeMarker = { value: '' };
    instance._lineItemIdInput = { value: '' };
    instance._productId = PRODUCT_ID;
    instance._stackSame = stackSame;
    instance._idAuthority = false;
    instance._inputs = inputs;
    instance.$emitter = { publish: () => {} };
    return instance;
}

function textInput(value) {
    return { type: 'text', value, dataset: { fieldKey: 'f1' } };
}

describe('ID-Hoheit haengt an der Befuellung, nicht am Plugin', () => {
    test('leere Felder beim Init beanspruchen keine Hoheit', () => {
        const instance = makeInstance({ inputs: [textInput('')] });

        instance._updateLineItemId();

        assert.strictEqual(instance._activeMarker.value, '');
        assert.strictEqual(instance.el.hasAttribute('data-rc-id-controller'), false);
    });

    test('befuelltes Feld beansprucht Hoheit ueber Marker und DOM-Attribut', () => {
        const instance = makeInstance({ inputs: [textInput('Hans Muster')] });

        instance._updateLineItemId();

        assert.strictEqual(instance._activeMarker.value, '1');
        assert.strictEqual(instance.el.attrs['data-rc-id-controller'], 'true');
    });

    test('befuelltes Feld berechnet eine von der Produkt-ID abweichende Positions-ID', () => {
        const instance = makeInstance({ inputs: [textInput('Hans Muster')] });

        instance._updateLineItemId();

        assert.match(instance._lineItemIdInput.value, UUID_SHAPE);
        assert.notStrictEqual(instance._lineItemIdInput.value, PRODUCT_ID);
    });
});

describe('Suffix-Event nur beim Wechsel der Hoheit', () => {
    test('unveraendert leere Felder feuern auch bei wiederholtem Update kein Event', () => {
        const instance = makeInstance({ inputs: [textInput('')] });

        instance._updateLineItemId();
        instance._updateLineItemId();

        assert.strictEqual(instance._form.dispatched.length, 0);
    });

    test('Leeren eines zuvor befuellten Feldes feuert genau ein Suffix-Event mit eigener Quelle', () => {
        const input = textInput('Hans Muster');
        const instance = makeInstance({ inputs: [input] });
        instance._updateLineItemId(); // Hoheit beanspruchen

        input.value = '';
        instance._updateLineItemId(); // Hoheit abgeben

        assert.strictEqual(instance._form.dispatched.length, 1);
        assert.strictEqual(instance._form.dispatched[0].detail.source, 'rcCustomFields');
    });

    test('Leeren gibt die ID zurueck auf die Produkt-ID und raeumt den Marker', () => {
        const input = textInput('Hans Muster');
        const instance = makeInstance({ inputs: [input] });
        instance._updateLineItemId();

        input.value = '';
        instance._updateLineItemId();

        assert.strictEqual(instance._lineItemIdInput.value, PRODUCT_ID);
        assert.strictEqual(instance._activeMarker.value, '');
    });
});

describe('stackSameValues=0 uebt Hoheit unabhaengig von Eingaben aus', () => {
    test('ohne Eingaben Hoheit beansprucht, aber ohne Suffix-Event', () => {
        const instance = makeInstance({ stackSame: false, inputs: [textInput('')] });

        instance._updateLineItemId();

        assert.strictEqual(instance._activeMarker.value, '1');
        assert.strictEqual(instance._form.dispatched.length, 0);
    });
});
