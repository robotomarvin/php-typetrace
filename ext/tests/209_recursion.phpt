--TEST--
typetrace observer: recursion (deep) and stack growth
--INI--
typetrace.enable=1
--FILE--
<?php
function fact($n) { return $n <= 1 ? 1 : $n * fact($n - 1); }
function deep($n) { return $n === 0 ? 0 : 1 + deep($n - 1); }
echo fact(10), "\n";
echo deep(3000), "\n";
echo deep(10), "\n";
$fs = json_decode(typetrace_report(), true)['functions'];
echo 'fact calls=', $fs['fact']['calls'], ' ret n=', $fs['fact']['return']['acc']['n'], ' param n=', $fs['fact']['params'][0]['acc']['n'], "\n";
echo 'deep calls=', $fs['deep']['calls'], ' ret n=', $fs['deep']['return']['acc']['n'], "\n";
?>
--EXPECT--
3628800
3000
10
fact calls=10 ret n=10 param n=10
deep calls=3012 ret n=3012
