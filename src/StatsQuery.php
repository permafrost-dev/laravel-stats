<?php

namespace Spatie\Stats;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Stats\Models\StatsEvent;

class StatsQuery
{
    protected BaseStats $statistic;

    protected string $period;

    protected DateTimeInterface $start;

    protected DateTimeInterface $end;

    protected ?string $havingData = null;

    public function __construct(string $statistic)
    {
        $this->statistic = new $statistic();

        $this->period = 'week';

        $this->start = now()->subMonth();

        $this->end = now();

        $this->havingData = null;
    }

    public static function for(string $statistic): self
    {
        return new self($statistic);
    }

    public function groupByYear(): self
    {
        $this->period = 'year';

        return $this;
    }

    public function groupByMonth(): self
    {
        $this->period = 'month';

        return $this;
    }

    public function groupByWeek(): self
    {
        $this->period = 'week';

        return $this;
    }

    public function groupByDay(): self
    {
        $this->period = 'day';

        return $this;
    }

    public function groupByHour(): self
    {
        $this->period = 'hour';

        return $this;
    }

    public function groupByMinute(): self
    {
        $this->period = 'minute';

        return $this;
    }

    public function start(DateTimeInterface $start): self
    {
        $this->start = $start;

        return $this;
    }

    public function end(DateTimeInterface $end): self
    {
        $this->end = $end;

        return $this;
    }

    public function havingData(string $data): self
    {
        $this->havingData = $data;

        return $this;
    }

    /** @return \Illuminate\Support\Collection|\Spatie\Stats\DataPoint[] */
    public function get(): Collection
    {
        $periods = $this->generatePeriods();

        $changes = $this->queryStats()
            ->whereType(StatsEvent::TYPE_CHANGE)
            ->where('created_at', '>=', $this->start)
            ->where('created_at', '<', $this->end)
            ->get();

        $differencesPerPeriod = $this->getDifferencesPerPeriod();

        //$latestSetPerPeriod = $this->getLatestSetPerPeriod();

        $lastPeriodValue = $this->getValue($this->start);

        return $periods->map(function (array $periodBoundaries) use ($changes, $differencesPerPeriod, &$lastPeriodValue) {
            [$periodStart, $periodEnd, $periodKey] = $periodBoundaries;

            //$setEvent = $latestSetPerPeriod->where('period', $periodKey)->first();
            $setEvent = ['value' => 0];

            $startValue = $setEvent['value'] ?? $lastPeriodValue;

            $applyChangesAfter = $setEvent['created_at'] ?? $periodStart;

            $difference = $changes
                ->where('created_at', '>=', $applyChangesAfter)
                ->where('created_at', '<', $periodEnd)
                ->sum('value');

            $count = $changes
                ->where('created_at', '>=', $applyChangesAfter)
                ->where('created_at', '<', $periodEnd)
                ->count();

            $value = $startValue + $difference;
            $lastPeriodValue = $value;

            return new DataPoint(
                $periodStart,
                $periodEnd,
                (int) $value,
                (int) $count,
                (int) ($differencesPerPeriod[$periodKey]['increments'] ?? 0),
                (int) ($differencesPerPeriod[$periodKey]['decrements'] ?? 0),
                (int) ($differencesPerPeriod[$periodKey]['difference'] ?? 0)
            );
        });
    }

    /**
     * Gets the value at a point in time by using the previous
     * snapshot and the changes since that snapshot.
     *
     * @param \DateTimeInterface $dateTime
     *
     * @return int
     */
    public function getValue(DateTimeInterface $dateTime): int
    {
        $nearestSet = $this->queryStats()
            ->where('type', StatsEvent::TYPE_SET)
            ->where('created_at', '<', $dateTime)
            ->orderByDesc('created_at')
            ->first();

        $startId = optional($nearestSet)->id ?? 0;
        $startValue = optional($nearestSet)->value ?? 0;

        $differenceSinceSet = $this->queryStats()
            ->where('type', StatsEvent::TYPE_CHANGE)
            ->where('id', '>', $startId)
            ->where('created_at', '<', $dateTime)
            ->sum('value');

        return $startValue + $differenceSinceSet;
    }

    public function generatePeriods(): Collection
    {
        $data = collect();
        $currentDateTime = (new Carbon($this->start))->startOf($this->period);

        do {
            $data->push([
                $currentDateTime->copy(),
                $currentDateTime->copy()->add(1, $this->period),
                $currentDateTime->format($this->getPeriodTimestampFormat()),
            ]);

            $currentDateTime->add(1, $this->period);
        } while ($currentDateTime->lt($this->end));

        return $data;
    }

    public function getPeriodTimestampFormat(): string
    {
        $map = [
            'year' => 'Y',
            'month' => 'Y-m',
            'week' => 'oW', // see https://stackoverflow.com/questions/15562270/php-datew-vs-mysql-yearweeknow
            'day' => 'Y-m-d',
            'hour' => 'Y-m-d H',
            'minute' => 'Y-m-d H:i',
        ];

        return $map[$this->period] ?? $map['day'];
    }

    public function getStatistic(): BaseStats
    {
        return $this->statistic;
    }

    protected function queryStats(): Builder
    {
        $result = StatsEvent::query()
            ->where('name', $this->statistic->getName());

        if ($this->havingData !== null) {
            $result = $result->where('data', $this->havingData);
        }

        return $result;
    }

    protected function getDifferencesPerPeriod(): EloquentCollection
    {
        return $this->queryStats()
            ->whereType(StatsEvent::TYPE_CHANGE)
            ->where('created_at', '>=', $this->start)
            ->where('created_at', '<', $this->end)
            ->selectRaw('sum(case when value > 0 then value else 0 end) as increments')
            ->selectRaw('abs(sum(case when value < 0 then value else 0 end)) as decrements')
            ->selectRaw('sum(value) as difference')
            ->groupByPeriod($this->period)
            ->get()
            ->keyBy('period');
    }

    protected function getLatestSetPerPeriod(): EloquentCollection
    {
        $periodDateFormat = StatsEvent::getPeriodDateFormat($this->period);

        $rankedSets = $this->queryStats()
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY {$periodDateFormat} ORDER BY `id` DESC) AS rn, `stats_events`.*, {$periodDateFormat} as period")
            ->whereType(StatsEvent::TYPE_SET)
            ->where('created_at', '>=', $this->start)
            ->where('created_at', '<', $this->end)
            ->get();

        return $rankedSets->where('rn', 1);
    }
}
