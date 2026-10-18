--TEST--
typetrace props: typetrace_reset() clears caches, later writes record into fresh records
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
class R { public $x; public static $s; }
$r = new R; $r->x = 1; R::$s = 1;
show(props());
typetrace_reset();
var_dump(props());
$r->x = 'a'; $r->x = 'b'; R::$s = 2.5; $r->dyn = 1;
show(props());
?>
--EXPECT--
R::$s static decl=- a=1 s=0 int:1
R::$x decl=- a=1 s=0 int:1
array(0) {
}
R::$dyn decl=- a=1 s=0 int:1
R::$s static decl=- a=1 s=0 float:1
R::$x decl=- a=2 s=0 string:2
