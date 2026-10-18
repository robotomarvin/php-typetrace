<?php

declare(strict_types=1);

namespace Typetrace\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Typetrace\Merge\Merger;
use Typetrace\Model\Report;
use Typetrace\Model\SchemaException;

#[AsCommand(name: 'merge', description: 'Merge typetrace JSON reports into one schema-1 report')]
final class MergeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('inputs', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Report files, directories (searched recursively for *.json) or globs')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file for the merged report')
            ->addOption('pretty', null, InputOption::VALUE_NONE, 'Pretty-print the JSON output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $out = $input->getOption('output');
        if (!is_string($out) || $out === '') {
            $err->writeln('<error>--output / -o is required</error>');

            return Command::INVALID;
        }
        try {
            /** @var list<string> $inputs */
            $inputs = $input->getArgument('inputs');
            $files = InputResolver::expand($inputs, $out);
            if ($files === []) {
                $err->writeln('<error>No report files found</error>');

                return Command::FAILURE;
            }
            $reports = [];
            foreach ($files as $f) {
                $json = file_get_contents($f);
                if ($json === false) {
                    throw new \RuntimeException("Cannot read $f");
                }
                $reports[] = Report::fromJson($json, $f);
            }
            $result = (new Merger())->merge($reports);
        } catch (SchemaException | \InvalidArgumentException | \RuntimeException | \TypeError $e) {
            // TypeError is a backstop for malformed input that slipped past schema validation
            $err->writeln('<error>' . ($e instanceof \TypeError ? 'invalid report data: ' : '') . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        foreach ($result->warnings as $w) {
            $err->writeln('<comment>warning: ' . $w . '</comment>');
        }
        $tmp = $out . '.tmp' . getmypid();
        if (file_put_contents($tmp, $result->report->toJson((bool) $input->getOption('pretty')) . "\n") === false || !rename($tmp, $out)) {
            $err->writeln("<error>Cannot write $out</error>");

            return Command::FAILURE;
        }
        $output->writeln(sprintf(
            'Merged %d report(s): %d function(s), %d property(ies), %d request(s) -> %s',
            count($reports), count($result->report->functions), count($result->report->properties), $result->report->requests, $out,
        ));

        return Command::SUCCESS;
    }
}
