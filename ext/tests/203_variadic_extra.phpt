--TEST--
typetrace observer: variadics, extra args (func_get_args), named extras
--INI--
typetrace.enable=1
--FILE--
<?php
function va($a, ...$rest) { return count($rest); }
va(1);
va(1, 2, 'x');
va(1, ...[3.5, null]);
va(1, ...['k' => true]);

function ex($a) { return func_get_args(); }
ex(1);
ex(1, 'two', 3);
ex(1, [4]);

$fs = json_decode(typetrace_report(), true)['functions'];
$va = $fs['va'];
echo 'va calls=', $va['calls'], "\n";
echo 'a n=', $va['params'][0]['acc']['n'], ' defaulted=', $va['params'][0]['defaulted'], "\n";
$r = $va['params'][1];
echo 'rest variadic=', var_export($r['variadic'], true), ' n=', $r['acc']['n'], ' defaulted=', $r['defaulted'],
    ' int=', $r['acc']['int']['n'], ' string=', $r['acc']['string']['n'], ' float=', $r['acc']['float']['n'],
    ' null=', $r['acc']['null'], ' true=', $r['acc']['true'], "\n";
var_dump($va['extra_args']);
$ex = $fs['ex'];
echo 'ex calls=', $ex['calls'], ' a n=', $ex['params'][0]['acc']['n'], "\n";
echo 'extra n=', $ex['extra_args']['n'], ' string=', $ex['extra_args']['string']['n'],
    ' int=', $ex['extra_args']['int']['n'], ' array=', $ex['extra_args']['array']['n'], "\n";
?>
--EXPECT--
va calls=4
a n=4 defaulted=0
rest variadic=true n=5 defaulted=1 int=1 string=1 float=1 null=1 true=1
NULL
ex calls=3 a n=3
extra n=3 string=1 int=1 array=1
