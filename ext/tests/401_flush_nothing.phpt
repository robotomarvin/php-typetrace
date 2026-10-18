--TEST--
typetrace: flush returns NULL without output_dir or with nothing recorded
--INI--
typetrace.enable=1
--FILE--
<?php
var_dump(typetrace_flush());
$dir = sys_get_temp_dir() . '/tt401_' . getmypid();
mkdir($dir);
ini_set('typetrace.output_dir', $dir);
var_dump(typetrace_flush());
var_dump(glob($dir . '/*'));
?>
--CLEAN--
<?php
$dir = sys_get_temp_dir() . '/tt401_' . getmypid();
foreach (glob($dir . '/*') ?: [] as $f) { unlink($f); }
@rmdir($dir);
?>
--EXPECT--
NULL
NULL
array(0) {
}
