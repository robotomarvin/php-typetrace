--TEST--
typetrace observer: untyped params with mixed values, calls and sampled counts
--INI--
typetrace.enable=1
--FILE--
<?php
function mixed_f($a, $b) { }
mixed_f(1, 'x');
mixed_f(2.5, null);
mixed_f([1, 2], new stdClass);
mixed_f(true, false);
mixed_f('', 0);

$f = json_decode(typetrace_report(), true)['functions']['mixed_f'];
echo $f['kind'], ' ', $f['name'], ' ', var_export($f['class'], true), ' ', basename($f['file']), "\n";
echo $f['calls'], ' ', $f['sampled'], ' ', count($f['params']), "\n";
foreach ($f['params'] as $p) {
    echo $p['name'], ' pos=', $p['position'], ' decl=', var_export($p['declared'], true),
        ' n=', $p['acc']['n'], ' defaulted=', $p['defaulted'], "\n";
}
$a = $f['params'][0]['acc'];
echo 'a: int=', $a['int']['n'], ' float=', $a['float']['n'], ' array=', $a['array']['n'],
    ' true=', $a['true'], ' string=', $a['string']['n'], "\n";
$b = $f['params'][1]['acc'];
echo 'b: string=', $b['string']['n'], ' null=', $b['null'], ' object=', json_encode($b['object']['classes']),
    ' false=', $b['false'], ' int=', $b['int']['n'], "\n";
var_dump($f['extra_args']);
?>
--EXPECT--
function mixed_f NULL 200_params_mixed.php
5 5 2
a pos=0 decl=NULL n=5 defaulted=0
b pos=1 decl=NULL n=5 defaulted=0
a: int=1 float=1 array=1 true=1 string=1
b: string=1 null=1 object={"stdClass":1} false=1 int=1
NULL
