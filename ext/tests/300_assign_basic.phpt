--TEST--
typetrace props: $this->x in methods, external writes, inherited under declaring class, private shadowing
--INI--
typetrace.enable=1
--FILE--
<?php
class A {
    public $x;
    private $p;
    public function __construct() { $this->x = 1; $this->p = 'a'; }
    public function setP($v) { $this->p = $v; }
}
class B extends A {
    private $p;
    public $own;
    public function setBP($v) { $this->p = $v; }
}
$a = new A;
$a->x = 2.5;
$b = new B;
$b->x = 'str';
$b->own = [1, 2];
$b->setBP(true);
$b->setP(null);
$b->setP(5);
$props = json_decode(typetrace_report(), true)['properties'];
ksort($props);
foreach ($props as $k => $p) {
    $acc = $p['acc'];
    $t = [];
    foreach (['null', 'true', 'false'] as $s) if ($acc[$s] ?? 0) $t[] = $s;
    foreach (['int', 'float', 'string', 'array'] as $s) if (($acc[$s]['n'] ?? 0) > 0) $t[] = $s . ':' . $acc[$s]['n'];
    echo $k, ' static=', var_export($p['static'], true), ' declared=', var_export($p['declared'], true),
        ' n=', $acc['n'], ' assign=', $p['sources']['assign'], ' sweep=', $p['sources']['sweep'], ' ', implode(',', $t), "\n";
}
?>
--EXPECT--
A::$p static=false declared=NULL n=4 assign=4 sweep=0 null,int:1,string:2
A::$x static=false declared=NULL n=4 assign=4 sweep=0 int:2,float:1,string:1
B::$own static=false declared=NULL n=1 assign=1 sweep=0 array:1
B::$p static=false declared=NULL n=1 assign=1 sweep=0 true
