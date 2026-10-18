--TEST--
typetrace observer: saturate_after drops sampling to ~1%
--INI--
typetrace.enable=1
typetrace.saturate_after=5
--FILE--
<?php
function sat($a) { return $a; }
for ($i = 0; $i < 3000; $i++) sat($i);
$f = json_decode(typetrace_report(), true)['functions']['sat'];
echo 'calls=', $f['calls'], "\n";
var_dump($f['sampled'] >= 5, $f['sampled'] < 300);
var_dump($f['params'][0]['acc']['n'] === $f['sampled']);
?>
--EXPECT--
calls=3000
bool(true)
bool(true)
bool(true)
