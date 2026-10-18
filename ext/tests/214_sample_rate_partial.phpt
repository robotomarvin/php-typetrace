--TEST--
typetrace observer: partial sample_rate samples a fraction; param/return counts match sampled
--INI--
typetrace.enable=1
typetrace.sample_rate=0.5
--FILE--
<?php
function sp($a) { return $a; }
for ($i = 0; $i < 2000; $i++) sp($i);
$f = json_decode(typetrace_report(), true)['functions']['sp'];
echo 'calls=', $f['calls'], "\n";
var_dump($f['sampled'] > 700 && $f['sampled'] < 1300);
var_dump($f['params'][0]['acc']['n'] === $f['sampled'], $f['return']['acc']['n'] === $f['sampled']);
?>
--EXPECT--
calls=2000
bool(true)
bool(true)
bool(true)
