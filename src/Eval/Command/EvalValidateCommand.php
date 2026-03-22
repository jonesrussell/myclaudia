<?php

declare(strict_types=1);

namespace Claudriel\Eval\Command;

use Claudriel\Eval\EvalSchemaValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;

#[AsCommand(name: 'claudriel:eval-validate', description: 'Validate eval YAML files against unified schema')]
final class EvalValidateCommand extends Command
{
    public function __construct(
        private readonly string $skillsBasePath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('skill', 's', InputOption::VALUE_REQUIRED, 'Validate only this skill')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write JSON report to file')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Treat warnings as errors');
        // Note: Symfony Console provides --quiet/-q built-in (suppresses output).
        // Do NOT add a custom --quiet option — it conflicts.
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $validator = new EvalSchemaValidator($this->skillsBasePath);
            $report = $validator->validate(
                skillFilter: $input->getOption('skill'),
                strict: (bool) $input->getOption('strict'),
            );
        } catch (ParseException $e) {
            $output->writeln("<error>YAML parse error: {$e->getMessage()}</error>");

            return Command::INVALID; // exit code 2
        } catch (\RuntimeException $e) {
            $output->writeln("<error>Runtime error: {$e->getMessage()}</error>");

            return Command::INVALID; // exit code 2
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        $outputFile = $input->getOption('output');
        if (is_string($outputFile)) {
            file_put_contents($outputFile, $json);
            $output->writeln("Report written to $outputFile");
        }

        if (! $output->isQuiet()) {
            $output->write($json);
        }

        $summary = $report['summary'];
        $output->writeln(sprintf(
            "\n%s: %d files, %d tests, %d errors, %d warnings",
            strtoupper($report['status']),
            $summary['files_scanned'],
            $summary['tests_scanned'],
            $summary['errors'],
            $summary['warnings'],
        ));

        return $report['status'] === 'pass' ? Command::SUCCESS : Command::FAILURE;
    }
}
