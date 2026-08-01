<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCustomFields\Command\MigrateTmmsCommand;
use Ruhrcoder\RcCustomFields\Service\TmmsMigrationService;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrateTmmsCommandTest extends TestCase
{
    public function testDryRunShowsCandidates(): void
    {
        $service = $this->createMock(TmmsMigrationService::class);
        $service->method('dryRun')->willReturn([
            ['productId' => 'p-1', 'productNumber' => 'SW-001', 'fieldCount' => 2, 'rawCustomFields' => []],
        ]);
        $service->expects($this->never())->method('migrate');

        $tester = new CommandTester(new MigrateTmmsCommand($service));
        $tester->execute(['--dry-run' => true]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('SW-001', $output);
        self::assertStringContainsString('1 Produkt(e) würden migriert', $output);
        self::assertSame(0, $tester->getStatusCode());
    }

    public function testDryRunWithNoCandidates(): void
    {
        $service = $this->createMock(TmmsMigrationService::class);
        $service->method('dryRun')->willReturn([]);

        $tester = new CommandTester(new MigrateTmmsCommand($service));
        $tester->execute(['--dry-run' => true]);

        self::assertStringContainsString('Keine TMMS-Kandidaten', $tester->getDisplay());
    }

    public function testMigrationRunsService(): void
    {
        $service = $this->createMock(TmmsMigrationService::class);
        $service->expects($this->once())
            ->method('migrate')
            ->willReturn(['processed' => 2, 'skipped' => 0, 'errors' => []]);

        $tester = new CommandTester(new MigrateTmmsCommand($service));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('2 Produkt(e) migriert, 0 übersprungen', $tester->getDisplay());
    }

    public function testMigrationReportsErrorsAndReturnsFailure(): void
    {
        $service = $this->createMock(TmmsMigrationService::class);
        $service->method('migrate')->willReturn([
            'processed' => 1,
            'skipped' => 1,
            'errors' => ['SW-002: Test-Fehler'],
        ]);

        $tester = new CommandTester(new MigrateTmmsCommand($service));
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Test-Fehler', $tester->getDisplay());
    }

    public function testRollbackWithConfirmation(): void
    {
        $service = $this->createMock(TmmsMigrationService::class);
        $service->expects($this->once())
            ->method('rollback')
            ->willReturn(['rolledBack' => 3]);

        $tester = $this->createTesterWithApplication(new MigrateTmmsCommand($service));
        $tester->setInputs(['yes']);
        $tester->execute(['--rollback' => true]);

        self::assertStringContainsString('3 Produkt(e) zurückgesetzt', $tester->getDisplay());
    }

    public function testRollbackAbortedOnNo(): void
    {
        $service = $this->createMock(TmmsMigrationService::class);
        $service->expects($this->never())->method('rollback');

        $tester = $this->createTesterWithApplication(new MigrateTmmsCommand($service));
        $tester->setInputs(['no']);
        $tester->execute(['--rollback' => true]);

        self::assertStringContainsString('Abgebrochen', $tester->getDisplay());
    }

    private function createTesterWithApplication(MigrateTmmsCommand $command): CommandTester
    {
        // Rollback-Pfad nutzt QuestionHelper — den setzt die Application bei add() automatisch via HelperSet.
        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('rc:migrate-tmms'));
    }

    public function testDryRunAndRollbackCombinationFails(): void
    {
        $service = $this->createMock(TmmsMigrationService::class);

        $tester = new CommandTester(new MigrateTmmsCommand($service));
        $exitCode = $tester->execute(['--dry-run' => true, '--rollback' => true]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('duerfen nicht kombiniert werden', $tester->getDisplay());
    }
}
