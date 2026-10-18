--TEST--
typetrace accum: invalid UTF-8 keys that collide after U+FFFD substitution are merged
--INI--
typetrace.enable=1
--FILE--
<?php
$j = typetrace_classify(["\xff" => 1], ["\xfe" => 2], ["ok" => 3]);
var_dump(json_decode($j) !== null);
$keys = json_decode($j, true)['array']['shape']['keys'];
echo json_encode(array_map(fn($e) => $e['n'], $keys), JSON_UNESCAPED_UNICODE), "\n";
// string literals
$j = typetrace_classify("\xff", "\xfe", "a");
$lits = json_decode($j, true)['literals']['values'];
echo json_encode($lits, JSON_UNESCAPED_UNICODE), "\n";
// duplicate-key check on the raw text
var_dump(substr_count($j, "\xEF\xBF\xBD"));
// resource types / class names go through the same path
$j = typetrace_classify(["\xc3" => 1, "\xc3\x28" => 1, "\xed\xa0\x80" => 1]);
var_dump(json_decode($j) !== null);
echo count(json_decode($j, true)['array']['shape']['keys']), "\n";
?>
--EXPECT--
bool(true)
{"ok":1,"�":2}
[["string","a",1],["string","�",2]]
int(1)
bool(true)
3
