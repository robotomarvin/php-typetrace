<?php
require __DIR__ . '/src.php';

$mode = $argv[1] ?? 'a';
$repo = new OrderRepo();
$ids = $mode === 'a' ? ['5', '12', '42'] : ['7', '99'];
foreach ($ids as $id) {
    $repo->find($id);
}
$repo->sort([1, 2, 3], $mode === 'a' ? 'asc' : 'desc');
$repo->sort([4]);
$repo->setActive($mode === 'a' ? 1 : 0);
$repo->total([1, 2, 3]);
format_price($mode === 'a' ? '10.50' : '3', $mode === 'a' ? 'EUR' : 'USD');
