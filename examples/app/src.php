<?php

class OrderRepo
{
    private $status;
    public $items = [];

    /** @param int $id */
    public function find($id)
    {
        return ['id' => (int) $id, 'name' => 'order-' . $id];
    }

    public function sort($rows, $dir = 'asc')
    {
        return $rows;
    }

    public function setActive($flag)
    {
        $this->status = $flag ? 'active' : 'inactive';
    }

    /** @return string */
    public function total(array $ids)
    {
        $this->items = $ids;
        return count($ids) * 10;
    }
}

function format_price($amount, $currency)
{
    return sprintf('%s %s', $amount, $currency);
}
