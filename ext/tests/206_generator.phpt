--TEST--
typetrace observer: generators count once per call, no return acc
--INI--
typetrace.enable=1
--FILE--
<?php
function gen($a, $b = 2) { $x = yield 1; yield 2; return 3; }
function &gen_ref() { $v = 1; yield $v; }
foreach (gen('s') as $v) { }
$g = gen(7, 8); $g->current(); $g->next(); $g->next(); $g->getReturn();
foreach (gen_ref() as $v) { }
gen(0); // never resumed: not observed

$fs = json_decode(typetrace_report(), true)['functions'];
$g = $fs['gen'];
echo 'calls=', $g['calls'], ' sampled=', $g['sampled'], ' generator=', var_export($g['return']['generator'], true),
    ' acc=', var_export($g['return']['acc'], true), "\n";
echo 'a string=', $g['params'][0]['acc']['string']['n'], ' int=', $g['params'][0]['acc']['int']['n'], "\n";
echo 'b n=', $g['params'][1]['acc']['n'], ' defaulted=', $g['params'][1]['defaulted'], "\n";
echo 'ref calls=', $fs['gen_ref']['calls'], ' generator=', var_export($fs['gen_ref']['return']['generator'], true), "\n";
?>
--EXPECT--
calls=2 sampled=2 generator=true acc=NULL
a string=1 int=1
b n=1 defaulted=1
ref calls=1 generator=true
