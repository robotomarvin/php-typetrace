<?php
// Call-heavy micro benchmark: small untyped functions with mixed args.
// Usage: php [-d extension=typetrace.so -d typetrace.enable=1] bench/calls.php [iterations]
// Compare wall time with typetrace disabled vs enabled (target <= 3x).
function add($a, $b) { return $a + $b; }
function label($s, $n = 1) { return $s; }
function pick(array $a, &$out) { $out = $a[0]; return $out; }
class Box { public function get($k, $default = null) { return $k; } public static function make($v) { return $v; } }

$n = (int)($argv[1] ?? 1000000);
$box = new Box;
$arr = [1, 2, 3];
$out = null;
$t = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    add($i, 2);
    label('abc');
    label("n$i", 3);
    pick($arr, $out);
    $box->get('k');
    Box::make(1.5);
}
$ms = (hrtime(true) - $t) / 1e6;
printf("%d iterations (%d calls): %.1f ms (%.1f ns/call)%s\n", $n, $n * 6, $ms, $ms * 1e6 / ($n * 6),
    function_exists('typetrace_report') && ini_get('typetrace.enable') ? ' [typetrace on]' : ' [off]');
