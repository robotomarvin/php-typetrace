--TEST--
typetrace props: dynamic props, stdClass skipped, typed props, dynamic names, by-ref, readonly
--INI--
typetrace.enable=1
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
class D { public int $n = 0; public ?string $s = null; public readonly int $ro;
    function __construct() { $this->ro = 7; }
    function dyn($name, $v) { $this->$name = $v; } }
$d = new D;
$d->extra = 1.5;
$d->extra = 'x';
$d->n = 3;
$d->s = 'abc';
$d->s = null;
$d->dyn('viaName', true);
$d->{'k' . 1} = [1];
$o = new stdClass;
$o->a = 1;
$y = 10;
$d->n = &$y;
$d->nope_cv = $undef ?? 'u';
try { $d->ro = 9; } catch (Error $e) { echo get_class($e), "\n"; }
show(props());
?>
--EXPECT--
Error
D::$extra decl=- a=2 s=0 float:1,string:1
D::$k1 decl=- a=1 s=0 array:1
D::$n decl=int a=2 s=0 int:2
D::$nope_cv decl=- a=1 s=0 string:1
D::$ro decl=int a=1 s=0 int:1
D::$s decl=?string a=2 s=0 null,string:1
D::$viaName decl=- a=1 s=0 true
