// Vertrags- und Listener-Test für rc-custom-fields.plugin.js. Verankert die SUFFIX_CHANGED_EVENT-
// Konstante und prüft, dass der generische Listener _updateLineItemId triggert.
// Zero-Dependency: Node-Standardbibliothek (node:test).

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

// CRLF -> LF normalisieren, damit die Strip-Regex unabhängig von der Zeilenende-Konvention der
// Working Copy greift (Windows-Checkout ist CRLF, das Docker-Gate LF).
const rawSource = readFileSync(sourcePath, 'utf8').replace(/\r\n/g, '\n');
// Plugin liest PluginBaseClass aus window — durch Stub-Klasse im Wrapper ersetzen.
const stripped = rawSource
    .replace(/^const \{ PluginBaseClass \} = window;\n/m, '')
    .replace(/^export default /m, '');

const wrapped = `
    class PluginBaseClass {
        constructor() { this.$emitter = { publish: () => {} }; }
        init() {}
        destroy() {}
    }
    ${stripped}
    return RcCustomFieldsPlugin;
`;

const RcCustomFieldsPlugin = new Function(wrapped)();

describe('SUFFIX_CHANGED_EVENT — Protokoll-Vertrag', () => {
    test('exponiert das generische Suffix-Event als statische Konstante', () => {
        assert.strictEqual(RcCustomFieldsPlugin.SUFFIX_CHANGED_EVENT, 'rcSuffixChanged');
    });
});

describe('Listener-Bindung — generisches Event triggert _updateLineItemId', () => {
    test('_boundOnSuffixChanged ruft _updateLineItemId auf', () => {
        const instance = Object.create(RcCustomFieldsPlugin.prototype);
        let calls = 0;
        instance._updateLineItemId = () => { calls++; };
        // Pattern aus init() reproduzieren — Bound-Reference verweist auf _updateLineItemId
        instance._boundOnSuffixChanged = () => instance._updateLineItemId();
        instance._boundOnSuffixChanged({ detail: { source: 'rcColorPicker' } });
        assert.strictEqual(calls, 1);
    });
});
