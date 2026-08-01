<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCustomFields\Service\CustomFieldsReader;
use Ruhrcoder\RcCustomFields\Twig\CustomFieldsTwigExtension;
use Twig\TwigFunction;
use Twig\TwigTest;

/**
 * Smoke-Test der Twig-Extension: Registrierung der Callables und Delegation auf
 * den Reader. Echte Reader-Instanz (final, pure) statt Mock — Delegation wird
 * über das Ergebnis nachgewiesen.
 */
final class CustomFieldsTwigExtensionTest extends TestCase
{
    private CustomFieldsTwigExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new CustomFieldsTwigExtension(new CustomFieldsReader());
    }

    public function testRegistersRcCfEntriesFunction(): void
    {
        $names = array_map(
            static fn (TwigFunction $function): string => $function->getName(),
            $this->extension->getFunctions(),
        );

        self::assertContains('rc_cf_entries', $names);
    }

    public function testRegistersRcCfFilledTest(): void
    {
        $names = array_map(
            static fn (TwigTest $test): string => $test->getName(),
            $this->extension->getTests(),
        );

        self::assertContains('rc_cf_filled', $names);
    }

    public function testEntriesDelegatesToReaderForBothSchemas(): void
    {
        self::assertSame(
            [['index' => 1, 'label' => 'Länge', 'value' => '1200']],
            $this->extension->entries(['tmms_customer_input_1_value' => '1200', 'tmms_customer_input_1_label' => 'Länge']),
        );
        self::assertSame(
            [['index' => 1, 'label' => 'RC', 'value' => 'neu']],
            $this->extension->entries(['rc_custom_fields' => [['index' => 1, 'label' => 'RC', 'value' => 'neu']]]),
        );
    }

    public function testHasEntriesDelegates(): void
    {
        self::assertTrue($this->extension->hasEntries(['rc_custom_fields' => [['index' => 1, 'label' => 'A', 'value' => 'x']]]));
        self::assertFalse($this->extension->hasEntries(null));
    }
}
