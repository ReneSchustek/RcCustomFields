<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Pinning-Tests gegen das Dokument-Template.
 *
 * Warum es diese Tests gibt: Das Template überschrieb bis 1.4.5 den Block
 * `document_line_items_table_row_label` -- einen Block, den Shopware NICHT kennt
 * (Tippfehler, Mehrzahl statt Einzahl). Twig ignoriert einen Override auf einen
 * unbekannten Block stillschweigend. Es gab keinen Fehler, keine Warnung, keinen
 * Testausfall -- und die Kundeneingaben erschienen auf KEINEM Dokument: nicht auf
 * der Rechnung, nicht auf dem Lieferschein, nicht auf dem Storno.
 *
 * PHPStan Level 8, PHP CS Fixer, alle Unit-Tests und die CI waren dabei grün.
 * Kein statisches Werkzeug prüft, ob ein Twig-Block je gerendert wird.
 *
 * Diese Tests nageln den Vertrag fest: der Blockname wird gegen die echten
 * Shopware-Quellen geprüft.
 */
final class DocumentTemplateContractTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../src/Resources/views/documents/base.html.twig';

    private string $content;

    protected function setUp(): void
    {
        $content = file_get_contents(self::TEMPLATE);
        self::assertIsString($content, 'Dokument-Template nicht lesbar: ' . self::TEMPLATE);

        $this->content = $content;
    }

    public function testExtendsTheDocumentBase(): void
    {
        self::assertStringContainsString(
            "{% sw_extends '@Framework/documents/base.html.twig' %}",
            $this->content,
            'Die Dokument-Basis ist der einzige Ansatzpunkt, der für alle Dokumenttypen '
            . '(Rechnung, Lieferschein, Storno, Gutschrift) gleichzeitig wirkt.',
        );
    }

    public function testOverridesTheColumnBlockNotTheRowBlock(): void
    {
        self::assertStringContainsString(
            '{% block document_line_item_table_column_label %}',
            $this->content,
            'Der Column-Block liegt INNERHALB des <td>. Der Row-Block IST das <td> -- '
            . 'ein Anhang nach parent() läge dort ausserhalb der Tabellenzelle.',
        );

        self::assertStringNotContainsString(
            '{% block document_line_item_table_row_label %}',
            $this->content,
            'Der Row-Block ist der falsche Ansatzpunkt (siehe oben).',
        );
    }

    public function testDerFruehereePhantomBlockIstVerschwunden(): void
    {
        // Geprüft wird die BLOCK-DEKLARATION, nicht die blosse Zeichenkette: Der alte
        // Name steht bewusst im Erklär-Kommentar des Templates, damit niemand ihn aus
        // Versehen wieder einbaut. Eine naive Suche nach dem Namen würde genau daran
        // scheitern -- der erste Entwurf dieses Tests tat das auch.
        self::assertStringNotContainsString(
            '{% block document_line_items_table_row_label %}',
            $this->content,
            'Mehrzahl `line_items` -- diesen Block gibt es in Shopware nicht. '
            . 'Er war die Ursache dafür, dass Kundeneingaben nie auf Dokumenten erschienen.',
        );
    }

    public function testRuftParentAufDamitDerPositionsnameErhaltenBleibt(): void
    {
        self::assertStringContainsString(
            '{{ parent() }}',
            $this->content,
            'Ohne parent() würde der Positionsname aus dem Core verschwinden und nur noch '
            . 'die Kundeneingabe in der Zelle stehen.',
        );
    }

    public function testOutputsLabelAndValueOfTheInputs(): void
    {
        // Seit RC11 liest das Template defensiv beide Schemas über den Helper
        // rc_cf_entries(...) statt direkt auf rc_custom_fields zuzugreifen — so
        // rendern auch Bestandsbestellungen mit flachem TMMS-Snapshot.
        self::assertStringContainsString('rc_cf_entries(lineItem.customFields)', $this->content);
        self::assertStringContainsString('entry.label', $this->content);
        self::assertStringContainsString('entry.value', $this->content);
    }
}
