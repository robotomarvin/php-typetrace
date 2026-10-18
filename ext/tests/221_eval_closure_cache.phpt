--TEST--
typetrace registry: freed eval'd closures whose address is reused do not hit stale cache entries
--INI--
typetrace.enable=1
--FILE--
<?php
foreach (['int' => 1, 'string' => 's', 'array' => [], 'float' => 1.5] as $t => $v) {
    $f = eval("return function($t \$x) { return \$x; };");
    $f($v);
    unset($f);
}
$r = json_decode(typetrace_report(), true)['functions'];
$out = [];
foreach ($r as $k => $f) {
    if ($f['kind'] !== 'closure') continue;
    $acc = array_keys(array_diff_key($f['params'][0]['acc'], ['n' => 1, 'literals' => 1]));
    $out[] = $f['params'][0]['declared'] . ' calls=' . $f['calls'] . ' ' . implode(',', $acc);
}
sort($out);
echo implode("\n", $out), "\n";
?>
--EXPECT--
array calls=1 array
float calls=1 float
int calls=1 int
string calls=1 string
