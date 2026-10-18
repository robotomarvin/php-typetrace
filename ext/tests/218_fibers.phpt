--TEST--
typetrace observer: fibers interleave begin/end without crash, counts sane
--INI--
typetrace.enable=1
--FILE--
<?php
function leaf($x) { $y = Fiber::suspend($x); return $y; }
function mid($x) { return leaf($x) . '!'; }
function plain($x) { return $x; }

$fibers = [];
for ($i = 0; $i < 3; $i++) {
    $fibers[$i] = new Fiber(function ($n) { return mid($n); });
}
foreach ($fibers as $i => $f) $f->start($i);
plain('main-between');
foreach ($fibers as $i => $f) $f->resume("r$i");
foreach ($fibers as $f) echo $f->getReturn(), ' ';
echo "\n";
// abandoned fiber: destroyed while suspended
$f = new Fiber(function () { mid(1); });
$f->start();
unset($f);
plain('after');

$fs = json_decode(typetrace_report(), true)['functions'];
foreach (['leaf', 'mid', 'plain'] as $k) {
    echo $k, ' calls=', $fs[$k]['calls'], ' ret=', $fs[$k]['return']['acc']['n'] ?? 0, "\n";
}
?>
--EXPECT--
r0! r1! r2! 
leaf calls=4 ret=3
mid calls=4 ret=3
plain calls=2 ret=2
