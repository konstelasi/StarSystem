<?php

namespace App\Files;

use Illuminate\Database\Eloquent\Builder;

/**
 * Coarse file kinds, derived from the stored mime, for filtering in the
 * admin and the file picker, and for deciding how a file is served.
 */
final class FileKind
{
    public const IMAGE = 'image';

    public const VIDEO = 'video';

    public const AUDIO = 'audio';

    public const DOCUMENT = 'document';

    public const ARCHIVE = 'archive';

    public const ALL = [self::IMAGE, self::VIDEO, self::AUDIO, self::DOCUMENT, self::ARCHIVE];

    private const ARCHIVE_TYPES = ['application/zip', 'application/x-zip-compressed'];

    public static function of(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => self::IMAGE,
            str_starts_with($mime, 'video/') => self::VIDEO,
            str_starts_with($mime, 'audio/') => self::AUDIO,
            in_array($mime, self::ARCHIVE_TYPES, true) => self::ARCHIVE,
            default => self::DOCUMENT,
        };
    }

    /**
     * Limits a query to files of any of the given kinds.
     *
     * @param  Builder<File>  $query
     * @param  list<string>  $kinds
     */
    public static function constrain(Builder $query, array $kinds): void
    {
        $query->where(function (Builder $query) use ($kinds) {
            foreach ($kinds as $kind) {
                match ($kind) {
                    self::IMAGE, self::VIDEO, self::AUDIO => $query->orWhere('mime', 'like', $kind.'/%'),
                    self::ARCHIVE => $query->orWhereIn('mime', self::ARCHIVE_TYPES),
                    self::DOCUMENT => $query->orWhere(fn (Builder $query) => $query
                        ->where('mime', 'not like', 'image/%')
                        ->where('mime', 'not like', 'video/%')
                        ->where('mime', 'not like', 'audio/%')
                        ->whereNotIn('mime', self::ARCHIVE_TYPES)),
                    default => null,
                };
            }
        });
    }
}
