--TEST--
typetrace observer: populated tables are flushed to one valid file at shutdown (child process)
--SKIPIF--
<?php if (!getenv('TEST_PHP_EXECUTABLE')) die('skip no TEST_PHP_EXECUTABLE'); ?>
--FILE--
<?php
$dir = sys_get_temp_dir() . '/tt217_' . getmypid();
mkdir($dir);
$script = $dir . '/child.php';
file_put_contents($script, '<?php function child_fn($a, &$b) { $b = $a; return [$a]; } $x = 0; child_fn("s", $x); child_fn(2, $x);');
$cmd = escapeshellarg(getenv('TEST_PHP_EXECUTABLE')) . ' ' . (getenv('TEST_PHP_EXTRA_ARGS') ?: '')
     . ' -d typetrace.enable=1 -d ' . escapeshellarg('typetrace.output_dir=' . $dir) . ' ' . escapeshellarg($script) . ' 2>&1';
exec($cmd, $out, $rc);
echo 'rc=', $rc, ' output=', implode("\n", $out), "\n";
$files = glob("$dir/typetrace-*.json");
echo 'files=', count($files), "\n";
$j = json_decode(file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
echo 'calls=', $j['functions']['child_fn']['calls'], ' param0 n=', $j['functions']['child_fn']['params'][0]['acc']['n'],
    ' out n=', $j['functions']['child_fn']['params'][1]['out']['n'], ' ret n=', $j['functions']['child_fn']['return']['acc']['n'], "\n";
var_dump($j['sapi'], $j['requests']);
var_dump(glob("$dir/*.tmp"));
foreach (glob("$dir/*") as $f) unlink($f);
rmdir($dir);
?>
--EXPECT--
rc=0 output=
files=1
calls=2 param0 n=2 out n=2 ret n=2
string(3) "cli"
int(1)
array(0) {
}
