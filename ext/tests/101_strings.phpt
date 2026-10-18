--TEST--
typetrace accum: string buckets
--FILE--
<?php
function b(string $s) {
    $r = json_decode(typetrace_classify($s), true)['string'];
    $hit = [];
    foreach (['empty','zero','one','int','int_noncanon','float','ws_numeric','other'] as $k) {
        if ($r[$k]) $hit[] = $k;
    }
    printf("%-26s %s (n=%d len=%d..%d)\n", json_encode($s), implode(',', $hit), $r['n'], $r['len_min'], $r['len_max']);
}
foreach ([
    '', '0', '1', '2', '-1', '42', '-0', '007', '+5', '00', '+0', ' 5', '5 ', "\t5\n", ' ', ' 5 ',
    '1e3', '1.5', '.5', '5.', '-1.5e-3', '1e', 'abc', '5a', '0x1A', 'a5', '1 2', 'NAN', 'INF',
    '9223372036854775807', '9223372036854775808', '-9223372036854775808', '-9223372036854775809',
    '99999999999999999999', ' 99999999999999999999', "5\0", "5\v",
] as $s) b($s);
echo json_encode(json_decode(typetrace_classify('', 'abcd', '12'), true)['string']), "\n";
?>
--EXPECT--
""                         empty (n=1 len=0..0)
"0"                        zero (n=1 len=1..1)
"1"                        one (n=1 len=1..1)
"2"                        int (n=1 len=1..1)
"-1"                       int (n=1 len=2..2)
"42"                       int (n=1 len=2..2)
"-0"                       int_noncanon (n=1 len=2..2)
"007"                      int_noncanon (n=1 len=3..3)
"+5"                       int_noncanon (n=1 len=2..2)
"00"                       int_noncanon (n=1 len=2..2)
"+0"                       int_noncanon (n=1 len=2..2)
" 5"                       ws_numeric (n=1 len=2..2)
"5 "                       ws_numeric (n=1 len=2..2)
"\t5\n"                    ws_numeric (n=1 len=3..3)
" "                        other (n=1 len=1..1)
" 5 "                      ws_numeric (n=1 len=3..3)
"1e3"                      float (n=1 len=3..3)
"1.5"                      float (n=1 len=3..3)
".5"                       float (n=1 len=2..2)
"5."                       float (n=1 len=2..2)
"-1.5e-3"                  float (n=1 len=7..7)
"1e"                       other (n=1 len=2..2)
"abc"                      other (n=1 len=3..3)
"5a"                       other (n=1 len=2..2)
"0x1A"                     other (n=1 len=4..4)
"a5"                       other (n=1 len=2..2)
"1 2"                      other (n=1 len=3..3)
"NAN"                      other (n=1 len=3..3)
"INF"                      other (n=1 len=3..3)
"9223372036854775807"      int (n=1 len=19..19)
"9223372036854775808"      float (n=1 len=19..19)
"-9223372036854775808"     int (n=1 len=20..20)
"-9223372036854775809"     float (n=1 len=20..20)
"99999999999999999999"     float (n=1 len=20..20)
" 99999999999999999999"    ws_numeric (n=1 len=21..21)
"5\u0000"                  other (n=1 len=2..2)
"5\u000b"                  ws_numeric (n=1 len=2..2)
{"n":3,"empty":1,"zero":0,"one":0,"int":1,"int_noncanon":0,"float":0,"ws_numeric":0,"other":1,"len_min":0,"len_max":4}
