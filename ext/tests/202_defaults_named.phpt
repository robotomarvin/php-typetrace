--TEST--
typetrace observer: defaulted params, named args
--INI--
typetrace.enable=1
--FILE--
<?php
// Known limitation: params skipped via named args are default-filled by the engine before
// the observer runs, so dflt(1, c: 9) counts $b as passed.
function dflt($a, $b = 'x', $c = 3) { }
dflt(1);
dflt(1, 2);
dflt(1, 2, 3);
dflt(a: 1);
dflt(1, c: 9);

$f = json_decode(typetrace_report(), true)['functions']['dflt'];
echo $f['calls'], ' ', $f['sampled'], "\n";
foreach ($f['params'] as $p) {
    echo $p['name'], ' optional=', var_export($p['optional'], true), ' n=', $p['acc']['n'] ?? 0, ' defaulted=', $p['defaulted'], "\n";
}
?>
--EXPECT--
5 5
a optional=false n=5 defaulted=0
b optional=true n=3 defaulted=2
c optional=true n=2 defaulted=3
