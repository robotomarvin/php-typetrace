--TEST--
typetrace observer: exceptions, no return observed, stack stays consistent
--INI--
typetrace.enable=1
--FILE--
<?php
function thrower($x) { throw new RuntimeException('boom'); }
function middle($x) { return thrower($x); }
function ok($x) { return $x; }
function by_ref_throw(&$r) { $r = 1; throw new Exception('x'); }

for ($i = 0; $i < 3; $i++) {
    try { middle($i); } catch (RuntimeException $e) { }
}
ok('after');
$z = 0;
try { by_ref_throw($z); } catch (Exception $e) { }
function finally_f() { try { throw new Exception; } finally { return 'swallowed'; } }
finally_f();

$fs = json_decode(typetrace_report(), true)['functions'];
foreach (['thrower', 'middle', 'ok', 'by_ref_throw', 'finally_f'] as $k) {
    $f = $fs[$k];
    echo $k, ' calls=', $f['calls'], ' sampled=', $f['sampled'], ' ret=',
        $f['return']['acc'] === null ? 'null' : $f['return']['acc']['n'], "\n";
}
var_dump($fs['by_ref_throw']['params'][0]['out']);
echo 'ok param n=', $fs['ok']['params'][0]['acc']['n'], "\n";
?>
--EXPECT--
thrower calls=3 sampled=3 ret=null
middle calls=3 sampled=3 ret=null
ok calls=1 sampled=1 ret=1
by_ref_throw calls=1 sampled=1 ret=null
finally_f calls=1 sampled=1 ret=1
NULL
ok param n=1
