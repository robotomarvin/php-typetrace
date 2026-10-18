--TEST--
typetrace observer: typetrace_flush() writes a file and resets tables
--INI--
typetrace.enable=1
--FILE--
<?php
$dir = sys_get_temp_dir() . '/tt216_' . getmypid();
mkdir($dir);
ini_set('typetrace.output_dir', $dir);
function fl($a) { return $a; }
fl(1); fl('x');
$path = typetrace_flush();
var_dump(is_string($path), dirname($path) === $dir, file_exists($path));
$j = json_decode(file_get_contents($path), true);
echo 'file calls=', $j['functions']['fl']['calls'], ' schema=', $j['schema'], "\n";
$r = json_decode(typetrace_report(), true);
var_dump(isset($r['functions']['fl']));
fl(3);
$r = json_decode(typetrace_report(), true);
echo 'after reset calls=', $r['functions']['fl']['calls'], ' param n=', $r['functions']['fl']['params'][0]['acc']['n'], "\n";
typetrace_reset();
var_dump(json_decode(typetrace_report(), true)['functions']);
foreach (glob("$dir/*") as $f) unlink($f);
rmdir($dir);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
file calls=2 schema=1
bool(false)
after reset calls=1 param n=1
array(0) {
}
