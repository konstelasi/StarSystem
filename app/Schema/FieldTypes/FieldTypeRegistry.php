<?php

namespace App\Schema\FieldTypes;

use App\Schema\FieldTypes\Core\CheckboxesType;
use App\Schema\FieldTypes\Core\CheckboxType;
use App\Schema\FieldTypes\Core\CodeType;
use App\Schema\FieldTypes\Core\ColorType;
use App\Schema\FieldTypes\Core\DatetimeType;
use App\Schema\FieldTypes\Core\EmailType;
use App\Schema\FieldTypes\Core\FileType;
use App\Schema\FieldTypes\Core\HiddenType;
use App\Schema\FieldTypes\Core\NumberType;
use App\Schema\FieldTypes\Core\RadioType;
use App\Schema\FieldTypes\Core\RangeType;
use App\Schema\FieldTypes\Core\RichTextType;
use App\Schema\FieldTypes\Core\SelectType;
use App\Schema\FieldTypes\Core\TextareaType;
use App\Schema\FieldTypes\Core\TextType;
use App\Schema\FieldTypes\Core\UrlType;
use InvalidArgumentException;

/**
 * Every field type the builder offers, in palette order. A singleton, so
 * modules can register their own types when they boot.
 */
class FieldTypeRegistry
{
    /**
     * Keys held for types that arrive with entries: a relation to another
     * model's entries, and a repeater of nested fields. Nothing else may
     * take them in the meantime.
     */
    public const RESERVED = ['relation', 'repeater'];

    /** @var array<string, FieldType> */
    private array $types = [];

    public static function withCoreTypes(): self
    {
        $registry = new self;

        foreach ([
            new TextType, new TextareaType, new RichTextType, new EmailType, new UrlType,
            new NumberType, new RangeType,
            new SelectType, new RadioType, new CheckboxType, new CheckboxesType,
            new DatetimeType,
            new FileType,
            new ColorType, new CodeType, new HiddenType,
        ] as $type) {
            $registry->register($type);
        }

        return $registry;
    }

    public function register(FieldType $type): void
    {
        $key = $type->key();

        if (in_array($key, self::RESERVED, true)) {
            throw new InvalidArgumentException("The field type key [{$key}] is reserved.");
        }

        if (isset($this->types[$key])) {
            throw new InvalidArgumentException("A field type [{$key}] is already registered.");
        }

        $this->types[$key] = $type;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function find(string $key): ?FieldType
    {
        return $this->types[$key] ?? null;
    }

    public function get(string $key): FieldType
    {
        return $this->types[$key] ?? throw new InvalidArgumentException("Unknown field type [{$key}].");
    }

    /**
     * @return array<string, FieldType>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * The builder's FieldTypeDescriptor list.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_values(array_map(fn (FieldType $type) => $type->toArray(), $this->types));
    }
}
