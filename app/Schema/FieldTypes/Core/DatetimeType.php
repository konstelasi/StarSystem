<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * A date and time, stored in UTC. StarDust reads an offset-free datetime
 * as UTC, so normalize() converts before storing.
 */
class DatetimeType extends FieldType
{
    public function key(): string
    {
        return 'datetime';
    }

    public function label(): string
    {
        return 'Date and time';
    }

    public function icon(): string
    {
        return 'calendar';
    }

    public function category(): string
    {
        return 'date';
    }

    public function settings(): array
    {
        return [Setting::boolean('date_only', 'Date only', false, 'Hides the time; the date is stored as midnight UTC.')];
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => 'datetime', 'canFilter' => true];
    }

    public function normalize(mixed $value, array $settings = []): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse(is_scalar($value) ? (string) $value : '', 'UTC');
        } catch (Throwable) {
            return $value;
        }

        $date = $date->utc();

        return ($settings['date_only'] ?? false) ? $date->format('Y-m-d').' 00:00:00' : $date->format('Y-m-d H:i:s');
    }

    protected function valueRules(array $settings): array
    {
        return ($settings['date_only'] ?? false) ? ['date_format:Y-m-d'] : ['date'];
    }
}
