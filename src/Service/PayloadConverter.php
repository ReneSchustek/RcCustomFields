<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Service;

use Ruhrcoder\RcCustomFields\RcCustomFields;

/**
 * Konvertiert RcCustomFields-Payload-Werte aus dem Cart-LineItem-Payload
 * in das für order_line_item.custom_fields-Persistierung benötigte Format.
 *
 * Cart-Payload-Schema:
 *   rcCustomFieldsActive = '1'
 *   rcCustomField{i}Value = string  (i = 1..FIELD_COUNT)
 *   rcCustomField{i}Label = string
 *
 * Order-CustomFields-Schema (persistiert auf order_line_item.custom_fields):
 *   rc_custom_fields = [
 *     [
 *       'index'  => int,
 *       'label'  => string,
 *       'value'  => string,
 *     ],
 *     ...
 *   ]
 */
class PayloadConverter
{
    public const ORDER_LINE_ITEM_CUSTOM_FIELDS_KEY = 'rc_custom_fields';

    /**
     * Extrahiert RcCustomFields-Einträge aus einem LineItem-Payload-Array.
     *
     * @param array<string, mixed> $payload
     * @return list<array{index: int, label: string, value: string}>
     */
    public function extractFromPayload(array $payload): array
    {
        // Kein Gate auf `rcCustomFieldsActive`: Der Marker beschreibt die ID-Hoheit und wird vom JS
        // gesetzt. Die Eingaben tragen eigene name-Attribute und kommen auch ohne JavaScript an —
        // maßgeblich ist der Wert, nicht der Marker.
        $entries = [];
        for ($i = 1; $i <= RcCustomFields::FIELD_COUNT; $i++) {
            $valueKey = 'rcCustomField' . $i . 'Value';
            $labelKey = 'rcCustomField' . $i . 'Label';

            $rawValue = $payload[$valueKey] ?? null;
            if (!\is_string($rawValue) || $rawValue === '') {
                continue;
            }

            $rawLabel = $payload[$labelKey] ?? '';
            $label = \is_string($rawLabel) ? $rawLabel : '';

            $entries[] = [
                'index' => $i,
                'label' => $label,
                'value' => $rawValue,
            ];
        }

        return $entries;
    }

    /**
     * Baut das Custom-Fields-Array für Merge mit bestehenden order_line_item.custom_fields.
     * Bestehende Custom-Fields aus anderen Plugins bleiben unangetastet — nur der eigene Key wird gesetzt.
     *
     * @param array<string, mixed> $existingCustomFields
     * @param list<array{index: int, label: string, value: string}> $entries
     * @return array<string, mixed>
     */
    public function mergeIntoCustomFields(array $existingCustomFields, array $entries): array
    {
        if ($entries === []) {
            // Nichts zu schreiben — bestehende Custom-Fields unverändert lassen.
            return $existingCustomFields;
        }

        $existingCustomFields[self::ORDER_LINE_ITEM_CUSTOM_FIELDS_KEY] = $entries;

        return $existingCustomFields;
    }
}
