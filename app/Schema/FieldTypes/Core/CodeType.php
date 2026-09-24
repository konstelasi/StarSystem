<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;

/**
 * Source code, edited with highlighting for the chosen language.
 * JSON-only.
 */
class CodeType extends FieldType
{
    public const LANGUAGES = [
        'plaintext' => 'Plain text',
        'html' => 'HTML',
        'css' => 'CSS',
        'javascript' => 'JavaScript',
        'typescript' => 'TypeScript',
        'json' => 'JSON',
        'markdown' => 'Markdown',
        'php' => 'PHP',
        'sql' => 'SQL',
        'xml' => 'XML',
        'yaml' => 'YAML',
    ];

    public function key(): string
    {
        return 'code';
    }

    public function label(): string
    {
        return 'Code';
    }

    public function icon(): string
    {
        return 'code';
    }

    public function category(): string
    {
        return 'rich';
    }

    public function settings(): array
    {
        return [Setting::select('language', 'Language', self::LANGUAGES, 'plaintext')];
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => 'string', 'canFilter' => false];
    }
}
