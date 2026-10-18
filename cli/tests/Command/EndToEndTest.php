<?php

declare(strict_types=1);

namespace Typetrace\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Typetrace\Command\MergeCommand;
use Typetrace\Command\ReportCommand;
use Typetrace\Compare\Comparer;
use Typetrace\Compare\Finding;
use Typetrace\Infer\TypeInferrer;
use Typetrace\Model\Report;
use Typetrace\Source\SourceIndex;

final class EndToEndTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/typetrace-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/runs', 0777, true);
        $app = realpath(self::FIXTURES . '/app');
        foreach ([1, 2] as $n) {
            file_put_contents(
                "{$this->tmp}/runs/run$n.json",
                str_replace('{{APP}}', (string) $app, (string) file_get_contents(self::FIXTURES . "/run$n.json")),
            );
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/{,runs/}*.json', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp . '/runs');
        @rmdir($this->tmp);
    }

    private function merge(array $inputs): CommandTester
    {
        $t = new CommandTester(new MergeCommand());
        $t->execute(['inputs' => $inputs, '--output' => $this->tmp . '/merged.json'], ['capture_stderr_separately' => true]);

        return $t;
    }

    private function report(array $opts): CommandTester
    {
        $t = new CommandTester(new ReportCommand());
        $t->execute(['report' => $this->tmp . '/merged.json'] + $opts, ['capture_stderr_separately' => true]);

        return $t;
    }

    public function testMergeDirectoryAndReportSnapshot(): void
    {
        $m = $this->merge([$this->tmp . '/runs']);
        $this->assertSame(0, $m->getStatusCode(), $m->getErrorOutput());
        $this->assertSame('', $m->getErrorOutput());

        $merged = Report::fromJson((string) file_get_contents($this->tmp . '/merged.json'));
        $this->assertSame(2, $merged->requests);
        $this->assertNull($merged->pid);
        $this->assertSame(1760000000.0, $merged->started);
        $this->assertSame(1760000030.0, $merged->finished);
        $this->assertSame(4, $merged->functions['App\\Service::find']->params[0]->acc->n);

        $r = $this->report(['--src' => self::FIXTURES . '/app', '--literal-max' => '2']);
        $this->assertSame(0, $r->getStatusCode());
        $snapshot = self::FIXTURES . '/expected/report.txt';
        if (getenv('UPDATE_SNAPSHOTS') === '1') {
            @mkdir(dirname($snapshot));
            file_put_contents($snapshot, $r->getDisplay());
        }
        $this->assertStringEqualsFile($snapshot, $r->getDisplay());
    }

    public function testMergeViaGlobAndFiles(): void
    {
        $this->assertSame(0, $this->merge([$this->tmp . '/runs/run*.json'])->getStatusCode());
        $a = file_get_contents($this->tmp . '/merged.json');
        $this->assertSame(0, $this->merge([$this->tmp . '/runs/run2.json', $this->tmp . '/runs/run1.json'])->getStatusCode());
        $this->assertSame($a, file_get_contents($this->tmp . '/merged.json'), 'input order must not matter');
        // merging the output again with itself in the dir does not pick up the output file
        $this->assertSame(0, $this->merge([$this->tmp])->getStatusCode());
    }

    public function testMergeRejectsOtherSchema(): void
    {
        file_put_contents($this->tmp . '/runs/bad.json', '{"schema": 2}');
        $m = $this->merge([$this->tmp . '/runs']);
        $this->assertSame(1, $m->getStatusCode());
        $this->assertStringContainsString('unsupported schema version 2', $m->getErrorOutput());
        $this->assertFileDoesNotExist($this->tmp . '/merged.json');
    }

    public function testMergeWarnsOnLineDrift(): void
    {
        $d = json_decode((string) file_get_contents($this->tmp . '/runs/run2.json'), true);
        $d['functions']['App\\Service::find']['line'] = 99;
        file_put_contents($this->tmp . '/runs/run2.json', json_encode($d));
        $m = $this->merge([$this->tmp . '/runs']);
        $this->assertSame(0, $m->getStatusCode());
        $this->assertStringContainsString("warning: 'App\\Service::find': metadata differs", $m->getErrorOutput());
    }

    public function testReportKindsAndFilters(): void
    {
        $this->merge([$this->tmp . '/runs']);
        $idx = SourceIndex::fromDirectory(self::FIXTURES . '/app');
        $merged = Report::fromJson((string) file_get_contents($this->tmp . '/merged.json'));
        $by = [];
        foreach ((new Comparer($idx, new TypeInferrer(2)))->compare($merged) as $f) {
            $by[$f->owner . ' ' . $f->slot] = $f;
        }
        $kind = static fn (string $k): string => $by[$k]->kind;
        $this->assertSame(Finding::CONFLICT, $kind('App\\Service::find param $id'));
        $this->assertSame('numeric-string', $by['App\\Service::find param $id']->inferred);
        $this->assertSame(Finding::UNTYPED, $kind('App\\Service::__construct param $mode'));
        $this->assertSame(Finding::BOOL, $kind('App\\Service::enable param $flag'));
        $this->assertSame('int<0,1>', $by['App\\Service::enable param $flag']->inferred);
        $this->assertSame(Finding::NARROW, $kind('App\\Service::sort param $dir'));
        $this->assertSame("'asc'|'desc'", $by['App\\Service::sort param $dir']->inferred);
        $this->assertSame(Finding::NARROW, $kind('App\\Service::load param $ids'));
        $this->assertSame(Finding::CONFLICT, $kind('App\\Service::total return'), 'wrong @return string');
        $this->assertSame(Finding::NUMERIC, $kind('App\\Service::ratio param $x'));
        // class hierarchy from --src
        $this->assertSame(Finding::CONFLICT, $kind('App\\Service::feed param $a'));
        $this->assertStringContainsString('App\\Rock', $by['App\\Service::feed param $a']->details[0]);
        $this->assertSame(Finding::CONFLICT, $kind('App\\Service::pet param $p'));
        $this->assertStringContainsString('App\\Cat', $by['App\\Service::pet param $p']->details[0]);
        $this->assertFalse($by['App\\Service::feed param $a']->unverified);

        $only = $this->report(['--src' => self::FIXTURES . '/app', '--only' => 'conflict', '--format' => 'json']);
        $data = json_decode($only->getDisplay(), true);
        $this->assertCount(4, $data['findings']);
        foreach ($data['findings'] as $f) {
            $this->assertSame('conflict', $f['kind']);
        }

        $minSamples = $this->report(['--src' => self::FIXTURES . '/app', '--min-samples' => '4', '--format' => 'json']);
        foreach (json_decode($minSamples->getDisplay(), true)['findings'] as $f) {
            $this->assertGreaterThanOrEqual(4, $f['samples']);
        }
    }

    public function testWithoutSrcSlotsAreUntypedOrUnverified(): void
    {
        $this->merge([$this->tmp . '/runs']);
        $r = $this->report(['--format' => 'json']);
        $data = json_decode($r->getDisplay(), true);
        $byOwner = [];
        foreach ($data['findings'] as $f) {
            $byOwner[$f['owner'] . ' ' . $f['slot']] = $f;
        }
        // without --src the class hierarchy is unknown: no conflict is claimed for feed()
        $this->assertArrayNotHasKey('App\\Service::feed param $a', $byOwner);
        $all = json_decode($this->report(['--format' => 'json', '--literal-max' => '0', '--all' => true])->getDisplay(), true);
        foreach ($all['findings'] as $f) {
            if ($f['owner'] === 'App\\Service::feed') {
                $this->assertTrue($f['unverified']);
            }
        }
        $this->assertArrayHasKey('App\\Service::find param $id', $byOwner);
        $this->assertSame('untyped', $byOwner['App\\Service::find param $id']['kind']);
    }

    public function testMarkdownAndFormatValidation(): void
    {
        $this->merge([$this->tmp . '/runs']);
        $md = $this->report(['--src' => self::FIXTURES . '/app', '--format' => 'md', '--only' => 'bool']);
        $this->assertStringContainsString('## `Service.php`', $md->getDisplay());
        $this->assertStringContainsString('| `App\\Service::enable` | param $enable', str_replace('param $flag', 'param $enable', $md->getDisplay()));
        $this->assertSame(2, $this->report(['--format' => 'xml'])->getStatusCode());
        $this->assertSame(2, $this->report(['--only' => 'nonsense'])->getStatusCode());
    }

    public function testAllShowsMatches(): void
    {
        $this->merge([$this->tmp . '/runs']);
        $without = json_decode($this->report(['--src' => self::FIXTURES . '/app', '--format' => 'json', '--literal-max' => '0'])->getDisplay(), true);
        $with = json_decode($this->report(['--src' => self::FIXTURES . '/app', '--format' => 'json', '--literal-max' => '0', '--all' => true])->getDisplay(), true);
        $this->assertGreaterThan(count($without['findings']), count($with['findings']));
    }
}
