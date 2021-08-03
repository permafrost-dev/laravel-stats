<?php

namespace Spatie\Stats;

use DateTimeInterface;
use Spatie\Stats\Models\StatsEvent;

abstract class BaseStats
{
    public function getName(): string
    {
        return class_basename($this);
    }

    public function getData(): ?string
    {
        return null;
    }

    public static function query(): StatsQuery
    {
        return new StatsQuery(static::class);
    }

    public static function increase($number = 1, ?DateTimeInterface $timestamp = null, ?string $data = null)
    {
        $number = is_int($number) ? $number : 1;

        $stats = new static;

        $stats->createEvent(StatsEvent::TYPE_CHANGE, $number, $timestamp, $data);
    }

    public static function decrease($number = 1, ?DateTimeInterface $timestamp = null, ?string $data = null)
    {
        $number = is_int($number) ? $number : 1;

        $stats = new static;

        $stats->createEvent(StatsEvent::TYPE_CHANGE, -$number, $timestamp, $data);
    }

    public static function set(int $value, ?DateTimeInterface $timestamp = null, ?string $data = null)
    {
        $stats = new static;

        $stats->createEvent(StatsEvent::TYPE_SET, $value, $timestamp, $data);
    }

    protected function createEvent($type, $value, ?DateTimeInterface $timestamp = null, ?string $data = null): StatsEvent
    {
        return StatsEvent::create([
            'name' => $this->getName(),
            'data' => $data ?? $this->getData(),
            'type' => $type,
            'value' => $value,
            'created_at' => $timestamp ?? now(),
        ]);
    }
}
