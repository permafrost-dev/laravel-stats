<?php

namespace Spatie\Stats;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;

class DataPoint implements Arrayable
{
    public Carbon $start;
    public Carbon $end;
    public int $value;
    public int $count;
    public int $increments;
    public int $decrements;
    public int $difference;

    public function __construct(
        Carbon $start,
        Carbon $end,
        int $value,
        int $count,
        int $increments,
        int $decrements,
        int $difference
    ) {
        $this->difference = $difference;
        $this->decrements = $decrements;
        $this->increments = $increments;
        $this->value = $value;
        $this->count = $count;
        $this->end = $end;
        $this->start = $start;
    }

    public function toArray(): array
    {
        return (array) $this;
    }
}
