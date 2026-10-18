<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Vertragstest gegen die Vorlagen des Verwaltungsteils.
 *
 * Der Reiter „Kundeneingaben" kann sichtbar, aber nicht bedienbar sein, aus zwei Gründen, die
 * beide von außen wie „da ist eben nichts" aussehen:
 *
 * 1. `sw-field` kennt Shopware 6.7 nicht. Eine unbekannte Komponente bleibt als
 *    unaufgelöste Marke im Baum stehen: kein Schalter, keine Meldung, kein Eintrag in der
 *    Konsole. Betroffen wären der Hauptschalter und beide Schalter je Feldkarte.
 * 2. Die Meteor-Felder geben ihren Wert über `update:value` bzw. `update:modelValue` heraus.
 *    Wer stattdessen auf `input` oder `change` hört, bekommt das native Ereignis. Es landet
 *    unverändert im Modell und wird beim Speichern mitgeschickt — der Server verwirft es
 *    stillschweigend, die Eingabe ist weg.
 *
 * Beides ist nur im laufenden Verwaltungsbereich zu sehen und dort auch nur, wenn man genau
 * hinschaut. Deshalb hält es dieser Test fest.
 */
final class AdministrationTemplateContractTest extends TestCase
{
    private const ADMINISTRATION_PATH = __DIR__ . '/../../src/Resources/app/administration/src';

    /**
     * Komponenten, die Shopware 6.7 nicht kennt, samt Nachfolger für die Fehlermeldung.
     */
    private const REMOVED_COMPONENTS = [
        'sw-field' => 'sw-switch-field, sw-text-field, sw-textarea-field (je nach Typ)',
    ];

    /**
     * Feldkomponente => das Ereignis, das ihren Wert trägt.
     */
    private const VALUE_EVENTS = [
        'sw-text-field' => '@update:value',
        'sw-switch-field' => '@update:value',
        'sw-single-select' => '@update:value',
        'sw-textarea-field' => '@update:model-value',
    ];

    public function testNoTemplateUsesAComponentThatShopwareRemoved(): void
    {
        $offenders = [];

        foreach ($this->administrationTemplates() as $name => $content) {
            foreach (array_keys(self::REMOVED_COMPONENTS) as $component) {
                if (preg_match('/<' . preg_quote($component, '/') . '[\s>]/', $content) === 1) {
                    $offenders[] = $name . ': <' . $component . '> → ' . self::REMOVED_COMPONENTS[$component];
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Vorlage benutzt eine Komponente, die es im Verzeichnis der Verwaltung nicht gibt:\n"
            . implode("\n", $offenders)
        );
    }

    public function testEveryFieldListensToTheEventThatCarriesItsValue(): void
    {
        $offenders = [];

        foreach ($this->administrationTemplates() as $name => $content) {
            foreach ($this->fieldTags($content) as $tag) {
                $component = $tag['component'];
                $expected = self::VALUE_EVENTS[$component];

                foreach (['@input=', '@change='] as $wrongEvent) {
                    if (str_contains($tag['markup'], $wrongEvent)) {
                        $offenders[] = sprintf(
                            '%s: <%s> hört auf %s statt auf %s — das liefert das Ereignis, nicht den Wert',
                            $name,
                            $component,
                            rtrim($wrongEvent, '='),
                            $expected
                        );
                    }
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Feld überträgt seinen Wert nicht:\n" . implode("\n", $offenders)
        );
    }

    /**
     * Gegenprobe zur vorigen Prüfung: Der Reiter muss die Ereignisse auch wirklich benutzen —
     * sonst wäre der Test auch dann grün, wenn jemand alle Felder entfernt.
     */
    public function testProductTabBindsAllTenFieldsAndTheMasterSwitch(): void
    {
        $template = $this->productTabTemplate();

        self::assertStringContainsString('@update:value="setEnabled($event)"', $template);
        self::assertStringContainsString('@update:value="setCustomField(i + \'_active\', $event)"', $template);
        self::assertStringContainsString('@update:value="setCustomField(i + \'_required\', $event)"', $template);
        self::assertStringContainsString('@update:value="setCustomField(i + \'_label\', $event)"', $template);
        self::assertStringContainsString('@update:value="setCustomField(i + \'_type\', $event)"', $template);
        self::assertStringContainsString('@update:model-value="setCustomField(i + \'_options\', $event)"', $template);
    }

    /**
     * @return list<array{component: string, markup: string}>
     */
    private function fieldTags(string $content): array
    {
        $tags = [];

        foreach (array_keys(self::VALUE_EVENTS) as $component) {
            $pattern = '/<' . preg_quote($component, '/') . '\b.*?>/s';

            if (preg_match_all($pattern, $content, $matches) === 0) {
                continue;
            }

            foreach ($matches[0] as $markup) {
                $tags[] = ['component' => $component, 'markup' => $markup];
            }
        }

        return $tags;
    }

    private function productTabTemplate(): string
    {
        $path = self::ADMINISTRATION_PATH
            . '/module/rc-custom-fields-product-tab/component/rc-cf-product-tab/rc-cf-product-tab.html.twig';

        $content = file_get_contents($path);
        self::assertNotFalse($content, 'Vorlage des Reiters nicht lesbar: ' . $path);

        return $content;
    }

    /**
     * @return array<string, string> Dateiname => Inhalt
     */
    private function administrationTemplates(): array
    {
        $directory = new \RecursiveDirectoryIterator(self::ADMINISTRATION_PATH);
        $templates = [];

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.html.twig')) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            self::assertNotFalse($content);

            $templates[$file->getFilename()] = $content;
        }

        self::assertNotEmpty($templates, 'Kein Verwaltungs-Template gefunden — der Test misst sonst nichts.');

        return $templates;
    }
}
