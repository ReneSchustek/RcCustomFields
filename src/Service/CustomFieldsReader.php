<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Service;

/**
 * Liest die Kundeneingaben eines Order-Line-Item-`customFields` **defensiv aus
 * beiden Schemas** und normalisiert sie zu einer einheitlichen Eintrags-Liste.
 * Damit rendern alte (TMMS) und neue (RcCustomFields) Bestellungen in Mail, PDF
 * und Storefront identisch — auch nachdem TMMS deinstalliert ist.
 *
 * Verifizierte Order-Snapshot-Schemas (2026-07-15, reale Bestelldaten):
 *
 *   RcCustomFields (eigenes, verschachteltes Schema):
 *     customFields['rc_custom_fields'] = [ {index:int, label:string, value:string}, … ]
 *
 *   TMMS (Fremd-Plugin, flaches Schema — wird NUR gelesen, nie geschrieben):
 *     customFields['tmms_customer_input_{slot}_value']       = string
 *     customFields['tmms_customer_input_{slot}_label']       = string
 *     customFields['tmms_customer_input_{slot}_placeholder'] = string
 *     customFields['tmms_customer_input_{slot}_fieldtype']   = string
 *
 * Vorrang: das eigene `rc_custom_fields`-Array. Nur wenn es fehlt oder keinen
 * verwertbaren Eintrag liefert, wird der flache TMMS-Snapshot gelesen (Bestellungen
 * von vor dem Cutover). Ein Slot ohne Wert (leerer String) ist keine Eingabe und
 * erscheint nicht in der Liste — konsistent mit {@see PayloadConverter}, das beim
 * Schreiben leere Werte ebenfalls überspringt.
 *
 * Pure-Function-Layer ohne Twig-Abhängigkeit: unit-testbar ohne Twig-Bootstrap.
 * Die {@see \Ruhrcoder\RcCustomFields\Twig\CustomFieldsTwigExtension} delegiert nur.
 */
final class CustomFieldsReader
{
    /** Verschachtelter Order-Snapshot-Key von RcCustomFields (siehe PayloadConverter). */
    public const KEY_RC_ENTRIES = PayloadConverter::ORDER_LINE_ITEM_CUSTOM_FIELDS_KEY;

    /** Flacher Order-Snapshot-Präfix von TMMS (Fremd-Plugin, read-only). */
    public const PREFIX_TMMS = 'tmms_customer_input_';

    /** Feste Slot-Obergrenze — TMMS wie RcCustomFields haben 10 Slots. */
    public const MAX_SLOTS = 10;

    /**
     * Normalisierte Kundeneingaben eines Order-Line-Item-`customFields`.
     *
     * @param array<string, mixed>|null $customFields
     *
     * @return list<array{index: int, label: string, value: string}>
     */
    public function entries(?array $customFields): array
    {
        if ($customFields === null || $customFields === []) {
            return [];
        }

        $rc = $this->readRcEntries($customFields);
        if ($rc !== []) {
            return $rc;
        }

        return $this->readTmmsEntries($customFields);
    }

    /**
     * @param array<string, mixed>|null $customFields
     */
    public function hasEntries(?array $customFields): bool
    {
        return $this->entries($customFields) !== [];
    }

    /**
     * @param array<string, mixed> $customFields
     *
     * @return list<array{index: int, label: string, value: string}>
     */
    private function readRcEntries(array $customFields): array
    {
        $raw = $customFields[self::KEY_RC_ENTRIES] ?? null;
        if (!\is_array($raw)) {
            return [];
        }

        $entries = [];
        foreach ($raw as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $value = $item['value'] ?? null;
            if (!\is_string($value) || $value === '') {
                continue;
            }

            $entries[] = [
                'index' => $this->toIndex($item['index'] ?? 0),
                'label' => $this->toLabel($item['label'] ?? ''),
                'value' => $value,
            ];
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $customFields
     *
     * @return list<array{index: int, label: string, value: string}>
     */
    private function readTmmsEntries(array $customFields): array
    {
        $entries = [];
        for ($slot = 1; $slot <= self::MAX_SLOTS; $slot++) {
            $value = $customFields[self::PREFIX_TMMS . $slot . '_value'] ?? null;
            if (!\is_string($value) || $value === '') {
                continue;
            }

            $entries[] = [
                'index' => $slot,
                'label' => $this->toLabel($customFields[self::PREFIX_TMMS . $slot . '_label'] ?? ''),
                'value' => $value,
            ];
        }

        return $entries;
    }

    private function toIndex(mixed $raw): int
    {
        if (\is_int($raw)) {
            return $raw;
        }

        return \is_numeric($raw) ? (int) $raw : 0;
    }

    private function toLabel(mixed $raw): string
    {
        return \is_string($raw) ? $raw : '';
    }
}
