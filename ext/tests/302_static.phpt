--TEST--
typetrace props: static properties via self/static/Foo/parent/var class, by-ref
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
class P { public static $ps = 0; protected static ?int $prot = null; private static $priv; }
class C extends P {
    public static $cs;
    static function go() {
        self::$cs = 1;
        static::$cs = 'two';
        parent::$ps = 3.5;
        C::$cs = [3];
        P::$prot = 4;
        $r = 5;
        self::$cs = &$r;
        $n = 'cs';
        self::$$n = null;
    }
}
class GC2 extends C {}
C::go();
GC2::go();
P::$ps = false;
$cn = 'C';
$cn::$cs = 1;
GC2::$ps = 'inh';
try { P::$priv = 1; } catch (Error $e) { echo $e->getMessage(), "\n"; }
show(props());
?>
--EXPECT--
Cannot access private property P::$priv
C::$cs static decl=- a=11 s=0 null,int:5,string:2,array:2
P::$prot static decl=?int a=2 s=0 int:2
P::$ps static decl=- a=4 s=0 false,float:2,string:1
