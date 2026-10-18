--TEST--
typetrace observer: numeric-string param buckets
--INI--
typetrace.enable=1
--FILE--
<?php
function ns($v) { return $v; }
foreach (['10', '007', ' 5', '1.5', 'abc', '', '0', '1'] as $s) {
    ns($s);
}
$f = json_decode(typetrace_report(), true)['functions']['ns'];
$s = $f['params'][0]['acc']['string'];
echo 'n=', $s['n'], ' int=', $s['int'], ' int_noncanon=', $s['int_noncanon'], ' ws=', $s['ws_numeric'],
    ' float=', $s['float'], ' other=', $s['other'], ' empty=', $s['empty'], ' zero=', $s['zero'], ' one=', $s['one'], "\n";
echo 'return string n=', $f['return']['acc']['string']['n'], "\n";
?>
--EXPECT--
n=8 int=1 int_noncanon=1 ws=1 float=1 other=1 empty=1 zero=1 one=1
return string n=8
