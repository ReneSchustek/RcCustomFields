<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCustomFields\Command;

use Ruhrcoder\RcCustomFields\Service\TmmsMigrationService;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(
    name: 'rc:migrate-tmms',
    description: 'Migriert TMMS-Produkt-Custom-Fields in das RcCustomFields-Schema (idempotent, transactional, rollback-fähig).',
)]
class MigrateTmmsCommand extends Command
{
    public function __construct(
        private readonly TmmsMigrationService $migrationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Listet nur die Kandidaten ohne DB-Schreibung.')
            ->addOption('rollback', null, InputOption::VALUE_NONE, 'Setzt rc_custom_fields_enabled auf false für alle in dieser Migration markierten Produkte.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = Context::createDefaultContext();
        $isDryRun = (bool) $input->getOption('dry-run');
        $isRollback = (bool) $input->getOption('rollback');

        if ($isDryRun && $isRollback) {
            $output->writeln('<error>--dry-run und --rollback duerfen nicht kombiniert werden.</error>');

            return Command::FAILURE;
        }

        if ($isRollback) {
            return $this->runRollback($input, $output, $context);
        }

        if ($isDryRun) {
            return $this->runDryRun($output, $context);
        }

        return $this->runMigration($output, $context);
    }

    private function runDryRun(OutputInterface $output, Context $context): int
    {
        $candidates = $this->migrationService->dryRun($context);
        if ($candidates === []) {
            $output->writeln('<info>Keine TMMS-Kandidaten gefunden.</info>');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<comment>%d Produkt(e) würden migriert (Dry-Run):</comment>', \count($candidates)));
        foreach ($candidates as $c) {
            $output->writeln(sprintf('  - %s (%s): %d TMMS-Feld(er)', $c['productNumber'], $c['productId'], $c['fieldCount']));
        }
        $output->writeln('<info>Keine Aenderungen geschrieben. Lauf ohne --dry-run wiederholen, um zu migrieren.</info>');

        return Command::SUCCESS;
    }

    private function runMigration(OutputInterface $output, Context $context): int
    {
        $output->writeln('<comment>Migration wird gestartet. Datenbank-Backup vor diesem Schritt ist Pflicht.</comment>');

        $result = $this->migrationService->migrate($context);

        $output->writeln(sprintf(
            '<info>TMMS-Migration abgeschlossen: %d Produkt(e) migriert, %d übersprungen.</info>',
            $result['processed'],
            $result['skipped'],
        ));

        if ($result['errors'] !== []) {
            $output->writeln('<error>Fehler:</error>');
            foreach ($result['errors'] as $error) {
                $output->writeln('  - ' . $error);
            }
        }

        return $result['skipped'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function runRollback(InputInterface $input, OutputInterface $output, Context $context): int
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            'Rollback setzt rc_custom_fields_enabled für alle migrierten Produkte auf false. Fortfahren? [y/N] ',
            false,
        );

        if (!$helper->ask($input, $output, $question)) {
            $output->writeln('<info>Abgebrochen.</info>');

            return Command::SUCCESS;
        }

        $result = $this->migrationService->rollback($context);
        $output->writeln(sprintf('<info>Rollback abgeschlossen: %d Produkt(e) zurückgesetzt.</info>', $result['rolledBack']));

        return Command::SUCCESS;
    }
}
