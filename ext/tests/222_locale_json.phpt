--TEST--
typetrace report: JSON is valid under a decimal-comma locale
--SKIPIF--
<?php
if (!setlocale(LC_ALL, 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR')) die('skip no decimal-comma locale installed');
?>
--INI--
typetrace.enable=1
--FILE--
<?php
function f($x) { return $x; }
f(1.5);
setlocale(LC_ALL, 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR');
echo localeconv()['decimal_point'], "\n";
$j = typetrace_report();
var_dump(json_decode($j) !== null);
var_dump(preg_match('/"started":\d+\.\d{3},"finished":\d+\.\d{3},/', $j));
?>
--EXPECT--
,
bool(true)
int(1)
