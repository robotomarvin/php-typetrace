--TEST--
typetrace props: virtual and set-hooked properties are not recorded from assignments (PHP 8.4+)
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip property hooks need PHP 8.4'); ?>
--INI--
typetrace.enable=1
typetrace.sweep=0
--FILE--
<?php
class H {
    public string $plain = '';
    public string $lower = '' { set => strtolower($value); }
    public string $ro { get => 'const'; }
    public string $both { get => 'x'; set { echo "set\n"; } }
    public string $getonly = 'a' { get => $this->getonly; }
}
$h = new H;
$h->plain = 'P';
$h->lower = 'ABC';
$h->both = 'B';
$h->getonly = 'g';
try { $h->ro = 'z'; } catch (Error $e) { echo get_class($e), "\n"; }
$p = json_decode(typetrace_report(), true)['properties'];
ksort($p);
foreach ($p as $k => $r) echo $k, ' ', $r['acc']['n'], "\n";
?>
--EXPECT--
set
Error
H::$getonly 1
H::$plain 1
