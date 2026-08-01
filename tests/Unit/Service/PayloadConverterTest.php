<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCustomFields\Service\PayloadConverter;

final class PayloadConverterTest extends TestCase
{
    public function testExtractReturnsEmptyWhenNoFieldValuesPresent(): void
    {
        // Leere Extraktion hängt an fehlenden Feldwerten, nicht am Marker.
        $converter = new PayloadConverter();

        self::assertSame([], $converter->extractFromPayload([]));
        self::assertSame([], $converter->extractFromPayload(['rcCustomFieldsActive' => '1']));
    }

    public function testExtractCollectsValuesWithoutActiveMarker(): void
    {
        // Eingaben tragen eigene name-Attribute und kommen auch ohne gesetzten Marker an (z. B. ohne
        // JavaScript). Maßgeblich ist der Wert, nicht `rcCustomFieldsActive`.
        $converter = new PayloadConverter();

        $entries = $converter->extractFromPayload([
            'rcCustomFieldsActive' => '',
            'rcCustomField1Value' => 'Gravur-Wunsch',
            'rcCustomField1Label' => 'Gravur',
        ]);

        self::assertCount(1, $entries);
        self::assertSame('Gravur-Wunsch', $entries[0]['value']);
    }

    public function testExtractCollectsActiveEntries(): void
    {
        $converter = new PayloadConverter();
        $entries = $converter->extractFromPayload([
            'rcCustomFieldsActive' => '1',
            'rcCustomField1Value' => 'Hans Muster',
            'rcCustomField1Label' => 'Name',
            'rcCustomField2Value' => 'Gravur-Wunsch',
            'rcCustomField2Label' => 'Gravur',
        ]);

        self::assertCount(2, $entries);
        self::assertSame(1, $entries[0]['index']);
        self::assertSame('Name', $entries[0]['label']);
        self::assertSame('Hans Muster', $entries[0]['value']);
        self::assertSame(2, $entries[1]['index']);
    }

    public function testExtractSkipsEmptyValues(): void
    {
        $converter = new PayloadConverter();
        $entries = $converter->extractFromPayload([
            'rcCustomFieldsActive' => '1',
            'rcCustomField1Value' => 'X',
            'rcCustomField1Label' => 'A',
            'rcCustomField2Value' => '',
            'rcCustomField3Value' => '   ',
        ]);

        // Index 1 hat Wert, Index 2 leer (skipped), Index 3 hat Whitespace — gilt als Wert (kein trim im Extract).
        self::assertCount(2, $entries);
    }

    public function testExtractIgnoresNonStringValues(): void
    {
        $converter = new PayloadConverter();
        $entries = $converter->extractFromPayload([
            'rcCustomFieldsActive' => '1',
            'rcCustomField1Value' => ['arr'],
            'rcCustomField2Value' => 42,
            'rcCustomField3Value' => 'OK',
            'rcCustomField3Label' => 'C',
        ]);

        self::assertCount(1, $entries);
        self::assertSame(3, $entries[0]['index']);
    }

    public function testExtractUsesEmptyLabelIfMissing(): void
    {
        $converter = new PayloadConverter();
        $entries = $converter->extractFromPayload([
            'rcCustomFieldsActive' => '1',
            'rcCustomField1Value' => 'X',
        ]);

        self::assertSame('', $entries[0]['label']);
    }

    public function testMergeReturnsExistingWhenEntriesEmpty(): void
    {
        $converter = new PayloadConverter();
        $existing = ['other_plugin_field' => 'keep me'];

        self::assertSame($existing, $converter->mergeIntoCustomFields($existing, []));
    }

    public function testMergePreservesOtherCustomFields(): void
    {
        $converter = new PayloadConverter();
        $existing = ['other_plugin_field' => 'foreign'];
        $entries = [['index' => 1, 'label' => 'L', 'value' => 'V']];

        $result = $converter->mergeIntoCustomFields($existing, $entries);

        self::assertArrayHasKey('other_plugin_field', $result);
        self::assertArrayHasKey(PayloadConverter::ORDER_LINE_ITEM_CUSTOM_FIELDS_KEY, $result);
        self::assertSame($entries, $result[PayloadConverter::ORDER_LINE_ITEM_CUSTOM_FIELDS_KEY]);
    }

    public function testMergeOverwritesOwnKey(): void
    {
        $converter = new PayloadConverter();
        $existing = [PayloadConverter::ORDER_LINE_ITEM_CUSTOM_FIELDS_KEY => 'old'];
        $entries = [['index' => 1, 'label' => 'L', 'value' => 'V']];

        $result = $converter->mergeIntoCustomFields($existing, $entries);

        self::assertSame($entries, $result[PayloadConverter::ORDER_LINE_ITEM_CUSTOM_FIELDS_KEY]);
    }

    public function testConstantHasExpectedValue(): void
    {
        self::assertSame('rc_custom_fields', PayloadConverter::ORDER_LINE_ITEM_CUSTOM_FIELDS_KEY);
    }
}
