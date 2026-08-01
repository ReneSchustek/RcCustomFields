<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pinning-Tests gegen das rc-custom-fields-inputs.html.twig — sichert, dass alle
 * deklarierten Feldtypen (text, number, textarea, date, time, datetime, checkbox, select)
 * im Storefront tatsächlich gerendert werden. Drift im Template wird sofort rot.
 */
final class TwigTemplateContractTest extends TestCase
{
    private const TEMPLATE_PATH = __DIR__ . '/../../src/Resources/views/storefront/component/buy-widget/rc-custom-fields-inputs.html.twig';

    private static function template(): string
    {
        $content = file_get_contents(self::TEMPLATE_PATH);
        self::assertNotFalse($content);

        return $content;
    }

    public function testTemplateRendersTextareaBranch(): void
    {
        self::assertStringContainsString("fieldType == 'textarea'", self::template());
        self::assertStringContainsString('<textarea', self::template());
    }

    public function testTemplateRendersSelectBranch(): void
    {
        $template = self::template();
        self::assertStringContainsString("fieldType == 'select'", $template);
        self::assertStringContainsString('<select', $template);
        // Options-Parsing erwartet Format „value|label" pro Zeile.
        self::assertStringContainsString("split('|', 2)", $template);
    }

    public function testTemplateRendersCheckboxBranch(): void
    {
        $template = self::template();
        self::assertStringContainsString("fieldType == 'checkbox'", $template);
        self::assertStringContainsString('type="checkbox"', $template);
    }

    public function testTemplateRendersDateTimeDatetimeBranch(): void
    {
        $template = self::template();
        self::assertStringContainsString("fieldType in ['date', 'time', 'datetime']", $template);
        // datetime wird zu datetime-local (HTML5-Konvention).
        self::assertStringContainsString("fieldType == 'datetime' ? 'datetime-local' : fieldType", $template);
    }

    public function testTemplateRendersNumberAndTextDefaultBranch(): void
    {
        $template = self::template();
        self::assertStringContainsString("fieldType == 'number' ? 'number' : 'text'", $template);
    }

    public function testTemplateRequiresEnabledFlag(): void
    {
        self::assertStringContainsString("cf['rc_custom_fields_enabled']", self::template());
    }

    public function testTemplateLeavesIdControllerToJavaScript(): void
    {
        // Ohne Eingaben uebt das Plugin keine ID-Hoheit aus. Das Twig behauptet sie daher nicht
        // statisch — das JS setzt `data-rc-id-controller`, sobald ein Feld befüllt ist.
        $template = self::template();

        self::assertStringNotContainsString('data-rc-id-controller="true"', $template);
        self::assertStringContainsString('data-rc-cf-active-marker', $template);
    }

    public function testTemplatePayloadKeysFollowConvention(): void
    {
        $template = self::template();
        self::assertStringContainsString('rcCustomField', $template);
        self::assertStringContainsString('rcCustomFieldsActive', $template);
    }

    public function testTemplateRendersAllConfiguredFields(): void
    {
        self::assertStringContainsString('1..10', self::template());
    }
}
