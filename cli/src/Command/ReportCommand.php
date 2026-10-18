<?php

declare(strict_types=1);

namespace Typetrace\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Typetrace\Compare\Comparer;
use Typetrace\Compare\Finding;
use Typetrace\Infer\TypeInferrer;
use Typetrace\Model\Report;
use Typetrace\Model\SchemaException;
use Typetrace\Output\JsonRenderer;
use Typetrace\Output\MarkdownRenderer;
use Typetrace\Output\TextRenderer;
use Typetrace\Source\SourceIndex;

#[AsCommand(name: 'report', description: 'Suggest types by comparing a (merged) report with declared types')]
final class ReportCommand extends Command
{
    private const KINDS = ['untyped', 'narrow', 'conflict', 'bool', 'numeric'];

    protected function configure(): void
    {
        $this
            ->addArgument('report', InputArgument::REQUIRED, 'Merged (or single) report JSON')
            ->addOption('src', null, InputOption::VALUE_REQUIRED, 'Source tree to read native types / PHPDoc / class hierarchy from')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text, json or md', 'text')
            ->addOption('min-samples', null, InputOption::VALUE_REQUIRED, 'Ignore slots with fewer observations', '1')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma separated: ' . implode(',', self::KINDS))
            ->addOption('literal-max', null, InputOption::VALUE_REQUIRED, 'Max distinct values for literal unions', '8')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Also show slots whose declared type already matches');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['text', 'json', 'md'], true)) {
            $err->writeln('<error>--format must be text, json or md</error>');

            return Command::INVALID;
        }
        $only = null;
        if ($input->getOption('only') !== null) {
            $only = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('only')))));
            $bad = array_diff($only, self::KINDS);
            if ($bad !== []) {
                $err->writeln('<error>Unknown --only value(s): ' . implode(', ', $bad) . '</error>');

                return Command::INVALID;
            }
        }
        $minSamples = filter_var($input->getOption('min-samples'), FILTER_VALIDATE_INT);
        $literalMax = filter_var($input->getOption('literal-max'), FILTER_VALIDATE_INT);
        if ($minSamples === false || $minSamples < 0 || $literalMax === false || $literalMax < 0) {
            $err->writeln('<error>--min-samples and --literal-max must be non-negative integers</error>');

            return Command::INVALID;
        }

        try {
            $file = (string) $input->getArgument('report');
            $json = @file_get_contents($file);
            if ($json === false) {
                throw new \RuntimeException("Cannot read $file");
            }
            $report = Report::fromJson($json, $file);
        } catch (SchemaException | \RuntimeException | \TypeError $e) {
            $err->writeln('<error>' . ($e instanceof \TypeError ? 'invalid report data: ' : '') . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $src = $input->getOption('src');
        $index = null;
        if (is_string($src) && $src !== '') {
            if (!is_dir($src)) {
                $err->writeln("<error>--src is not a directory: $src</error>");

                return Command::FAILURE;
            }
            $index = SourceIndex::fromDirectory($src);
            foreach ($index->errors as $e) {
                $err->writeln('<comment>warning: ' . $e . '</comment>');
            }
        }

        $comparer = new Comparer($index, new TypeInferrer($literalMax), $minSamples);
        try {
            $all = $comparer->compare($report);
        } catch (\TypeError $e) {
            $err->writeln('<error>invalid report data: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $findings = array_values(array_filter(
            $all,
            static function (Finding $f) use ($only, $input): bool {
                if ($f->kind === Finding::MATCH) {
                    return (bool) $input->getOption('all');
                }

                return $only === null || in_array($f->kind, $only, true);
            },
        ));

        $root = is_string($src) && $src !== '' ? $src : null;
        $renderer = match ($format) {
            'json' => new JsonRenderer(),
            'md' => new MarkdownRenderer($root),
            default => new TextRenderer($root),
        };
        $output->write($renderer->render($findings));

        return Command::SUCCESS;
    }
}
