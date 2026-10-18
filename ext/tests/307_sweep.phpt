--TEST--
typetrace props: sweep=1 observes final state of objects incl. internally-set props
--INI--
typetrace.enable=1
typetrace.sweep=1
--FILE--
<?php
function show(array $props) {
    ksort($props);
    foreach ($props as $k => $p) {
        $acc = $p["acc"]; $t = [];
        if ($acc) {
            foreach (["null", "true", "false"] as $s) if ($acc[$s] ?? 0) $t[] = $s;
            foreach (["int", "float", "string", "array", "object"] as $s) if ((is_array($acc[$s] ?? null) ? $acc[$s]["n"] : 0) > 0) $t[] = $s . ":" . $acc[$s]["n"];
        }
        echo $k, $p["static"] ? " static" : "", " decl=", $p["declared"] ?? "-", " a=", $p["sources"]["assign"], " s=", $p["sources"]["sweep"], " ", implode(",", $t), "\n";
    }
}
function props() { return json_decode(typetrace_report(), true)["properties"]; }
#[AllowDynamicProperties]
class S { public $a; public int $t = 5; public ?string $u; private $priv = 'p'; }
class S2 extends S { private $priv = 2.5; }
$o = unserialize('O:1:"S":2:{s:1:"a";i:42;s:3:"dyn";b:1;}');
$p = new S2;
$p->a = 'x';
$rp = new ReflectionProperty('S', 'a');
$rp->setValue(new S, [1]);
$std = new stdClass; $std->q = 1;
register_shutdown_function(function () { global $late; $late = new S; $late->a = 1.5; });
class Rep { function __destruct() { show(props()); } }
$rep = new Rep;
?>
--EXPECT--
S2::$priv decl=- a=0 s=1 float:1
S::$a decl=- a=2 s=3 int:1,float:2,string:2
S::$dyn decl=- a=0 s=1 true
S::$priv decl=- a=0 s=3 string:3
S::$t decl=int a=0 s=3 int:3
