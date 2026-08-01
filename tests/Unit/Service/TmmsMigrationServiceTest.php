<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Ruhrcoder\RcCustomFields\Service\TmmsMigrationService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Unit-Tests für das erweiterte TMMS→RcCustomFields-Schema-Mapping.
 *
 * `dryRun()`, `migrate()` und `rollback()` lassen sich nur mit DAL-Vollintegration
 * sinnvoll testen — die werden im Integration-Test-Pfad geprüft. Hier sind die
 * Konvertierungs-Funktionen via Reflection isoliert, weil sie die kritische
 * Mapping-Logik tragen (CSV → Options-Format, Min/Max-Cast, Type-Map).
 */
final class TmmsMigrationServiceTest extends TestCase
{
    public function testMapTypeKnowsInputAsTextWithoutWarning(): void
    {
        $logger = new RecordingLogger();
        $service = $this->makeService($logger);

        self::assertSame('text', $this->invokeMapType($service, 'input'));
        self::assertSame('text', $this->invokeMapType($service, 'text'));
        self::assertSame('select', $this->invokeMapType($service, 'select'));
        self::assertSame('number', $this->invokeMapType($service, 'number'));
        self::assertSame([], $logger->records, 'Bekannte TMMS-Fieldtypes dürfen kein Warning auslösen.');
    }

    public function testMapTypeWarnsOnUnknownType(): void
    {
        $logger = new RecordingLogger();
        $service = $this->makeService($logger);

        self::assertSame('text', $this->invokeMapType($service, 'wildcard'));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('unbekannter TMMS-Fieldtype', $logger->records[0]['message']);
        self::assertSame('wildcard', $logger->records[0]['context']['tmmsType']);
    }

    public function testMapTypeWarnsOnNonStringInput(): void
    {
        $logger = new RecordingLogger();
        $service = $this->makeService($logger);

        self::assertSame('text', $this->invokeMapType($service, 42));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('nicht-String-Fieldtype', $logger->records[0]['message']);
    }

    public function testConvertSelectOptionsTrimsAndConvertsCsvToValueLabelFormat(): void
    {
        $service = $this->makeService(new RecordingLogger());

        // Realer Live-Daten-Inhalt von PF-230
        $result = $this->invokeConvertSelectOptions($service, 'Trägerplatte, Kugelring');

        self::assertSame("Trägerplatte|Trägerplatte\nKugelring|Kugelring", $result);
    }

    public function testConvertSelectOptionsSkipsEmptyTokens(): void
    {
        $service = $this->makeService(new RecordingLogger());

        $result = $this->invokeConvertSelectOptions($service, 'a,  ,b, , c');

        self::assertSame("a|a\nb|b\nc|c", $result);
    }

    public function testConvertSelectOptionsSingleValue(): void
    {
        $service = $this->makeService(new RecordingLogger());

        $result = $this->invokeConvertSelectOptions($service, 'OnlyOne');

        self::assertSame('OnlyOne|OnlyOne', $result);
    }

    public function testFieldCountConstantIsAtLeastFiveForTmmsCompatibility(): void
    {
        // TMMS hat in der Live-Daten-Basis bis Slot 5 aktive Felder; loop muss mindestens so weit gehen.
        self::assertGreaterThanOrEqual(5, \Ruhrcoder\RcCustomFields\RcCustomFields::FIELD_COUNT);
    }

    public function testTypeMapCoversAllLiveFieldtypes(): void
    {
        // Im Live-Bestand vorkommende Typen (Stand 2026-05-18): input, select, number.
        // Wenn jemand einen Wert aus dieser Liste ohne TYPE_MAP-Eintrag entfernt, scheitert der Test.
        $service = $this->makeService(new RecordingLogger());

        foreach (['input', 'select', 'number', 'text', 'textarea', 'date', 'time', 'datetime', 'checkbox'] as $tmmsType) {
            $logger = new RecordingLogger();
            $serviceWithLogger = $this->makeService($logger);
            $result = $this->invokeMapType($serviceWithLogger, $tmmsType);

            self::assertNotEmpty($result, sprintf('TYPE_MAP-Eintrag fehlt für "%s"', $tmmsType));
            self::assertSame([], $logger->records, sprintf('TYPE_MAP-Eintrag "%s" darf kein Warning auslösen', $tmmsType));
        }

        // mit Service muss man auch gar nichts machen, das ist nur ein wertvoller Sanity-Check.
        unset($service);
    }

    private function makeService(RecordingLogger $logger): TmmsMigrationService
    {
        return new TmmsMigrationService(
            $this->createMock(Connection::class),
            $this->createMock(EntityRepository::class),
            $logger,
        );
    }

    private function invokeMapType(TmmsMigrationService $service, mixed $tmmsType): string
    {
        $reflection = new \ReflectionMethod($service, 'mapType');
        $reflection->setAccessible(true);

        return (string) $reflection->invoke($service, $tmmsType);
    }

    private function invokeConvertSelectOptions(TmmsMigrationService $service, string $csv): string
    {
        $reflection = new \ReflectionMethod($service, 'convertSelectOptions');
        $reflection->setAccessible(true);

        return (string) $reflection->invoke($service, $csv);
    }
}

/**
 * Minimaler PSR-3-Logger, der alle Aufrufe in einem Array sammelt.
 * @internal
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
