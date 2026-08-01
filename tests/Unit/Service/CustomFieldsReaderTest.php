<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCustomFields\Service\CustomFieldsReader;

/**
 * Sichert den defensiven Dual-Schema-Read: der Reader liefert die Kundeneingaben
 * eines Order-Line-Item-customFields normalisiert — aus dem eigenen verschachtelten
 * rc_custom_fields-Array ODER aus dem flachen TMMS-Snapshot alter Bestellungen.
 */
final class CustomFieldsReaderTest extends TestCase
{
    private CustomFieldsReader $reader;

    protected function setUp(): void
    {
        $this->reader = new CustomFieldsReader();
    }

    /**
     * Was: Nur das eigene rc_custom_fields-Array ist gesetzt.
     * Warum: Neue Bestellungen tragen dieses Schema — es muss 1:1 durchgereicht werden.
     * Erwartet: Die Einträge in Reihenfolge, normalisiert.
     */
    public function testReadsRcNestedEntries(): void
    {
        $cf = [
            'rc_custom_fields' => [
                ['index' => 1, 'label' => 'Länge', 'value' => '1200'],
                ['index' => 2, 'label' => 'Gravur', 'value' => 'Hallo'],
            ],
        ];

        self::assertSame([
            ['index' => 1, 'label' => 'Länge', 'value' => '1200'],
            ['index' => 2, 'label' => 'Gravur', 'value' => 'Hallo'],
        ], $this->reader->entries($cf));
    }

    /**
     * Was: Nur der flache TMMS-Snapshot ist gesetzt (Bestandsbestellung).
     * Warum: KERN von RC11 — alte Bestellungen müssen nach dem TMMS-Deinstall weiter
     *        rendern, ohne dass TMMS installiert ist.
     * Erwartet: Die TMMS-Slots werden zu normalisierten Einträgen (Slot = index).
     */
    public function testReadsTmmsFlatSnapshotAsFallback(): void
    {
        $cf = [
            'tmms_customer_input_1_value' => '1200',
            'tmms_customer_input_1_label' => 'Gewünschte Länge in mm',
            'tmms_customer_input_1_placeholder' => 'z. B. 1500',
            'tmms_customer_input_1_fieldtype' => 'input',
            'product_number' => '12120V2A',
        ];

        self::assertSame([
            ['index' => 1, 'label' => 'Gewünschte Länge in mm', 'value' => '1200'],
        ], $this->reader->entries($cf));
    }

    /**
     * Was: Beide Schemas sind gesetzt.
     * Warum: Vorrang des eigenen Schemas — der TMMS-Snapshot ist nur Fallback für
     *        Bestellungen, die noch keinen rc-Snapshot haben.
     * Erwartet: Nur die rc-Einträge.
     */
    public function testRcSchemaWinsWhenBothPresent(): void
    {
        $cf = [
            'rc_custom_fields' => [['index' => 1, 'label' => 'RC', 'value' => 'neu']],
            'tmms_customer_input_1_value' => 'alt',
            'tmms_customer_input_1_label' => 'TMMS',
        ];

        self::assertSame([['index' => 1, 'label' => 'RC', 'value' => 'neu']], $this->reader->entries($cf));
    }

    /**
     * Was: rc_custom_fields ist vorhanden, aber leer (kein verwertbarer Eintrag).
     * Warum: Ein leeres eigenes Array darf den TMMS-Fallback nicht blockieren.
     * Erwartet: Fallback auf den TMMS-Snapshot.
     */
    public function testEmptyRcArrayFallsBackToTmms(): void
    {
        $cf = [
            'rc_custom_fields' => [],
            'tmms_customer_input_1_value' => '500',
            'tmms_customer_input_1_label' => 'Länge',
        ];

        self::assertSame([['index' => 1, 'label' => 'Länge', 'value' => '500']], $this->reader->entries($cf));
    }

    /**
     * Was: rc-Eintrag mit leerem Wert bzw. TMMS-Slot mit leerem Wert.
     * Warum: Ein leerer Wert ist keine Eingabe und erscheint nicht in der Liste —
     *        konsistent mit dem PayloadConverter, der leere Werte beim Schreiben überspringt.
     * Erwartet: Leere Einträge werden übersprungen.
     */
    public function testSkipsEmptyValues(): void
    {
        $rc = ['rc_custom_fields' => [
            ['index' => 1, 'label' => 'A', 'value' => ''],
            ['index' => 2, 'label' => 'B', 'value' => 'x'],
        ]];
        self::assertSame([['index' => 2, 'label' => 'B', 'value' => 'x']], $this->reader->entries($rc));

        $tmms = [
            'tmms_customer_input_1_value' => '',
            'tmms_customer_input_2_value' => 'y',
            'tmms_customer_input_2_label' => 'B',
        ];
        self::assertSame([['index' => 2, 'label' => 'B', 'value' => 'y']], $this->reader->entries($tmms));
    }

    /**
     * Was: TMMS-Slot ohne Label.
     * Warum: Der Label-Key kann fehlen — dann leerer Label-String, kein Fehler.
     * Erwartet: Eintrag mit leerem Label.
     */
    public function testTmmsSlotWithoutLabel(): void
    {
        $cf = ['tmms_customer_input_3_value' => '42'];

        self::assertSame([['index' => 3, 'label' => '', 'value' => '42']], $this->reader->entries($cf));
    }

    /**
     * Was: Ein TMMS-Slot jenseits von MAX_SLOTS (11).
     * Warum: Die Iteration ist auf 1..10 begrenzt (feste Slot-Zahl beider Schemas).
     * Erwartet: Slot 11 wird ignoriert.
     */
    public function testIgnoresSlotsBeyondMax(): void
    {
        $cf = [
            'tmms_customer_input_11_value' => 'zuviel',
            'tmms_customer_input_10_value' => 'ok',
        ];

        self::assertSame([['index' => 10, 'label' => '', 'value' => 'ok']], $this->reader->entries($cf));
    }

    /**
     * Was: null / leeres Array / fremde Keys.
     * Warum: Null-Safety ist Live-Pflicht — kein Renderer darf an einem leeren oder
     *        fremden customFields crashen.
     * Erwartet: Leere Liste, keine Exception; hasEntries() = false.
     */
    public function testNullSafeAndForeignKeys(): void
    {
        self::assertSame([], $this->reader->entries(null));
        self::assertSame([], $this->reader->entries([]));
        self::assertSame([], $this->reader->entries(['foo' => 'bar', 'rc_custom_fields' => 'kein-array']));
        self::assertFalse($this->reader->hasEntries(null));
        self::assertFalse($this->reader->hasEntries(['foo' => 'bar']));
        self::assertTrue($this->reader->hasEntries(['tmms_customer_input_1_value' => 'x']));
    }
}
