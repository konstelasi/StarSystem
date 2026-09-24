<?php

namespace App\Files;

/**
 * Decides whether a file may be stored, from its extension and the type
 * fileinfo detects in its contents (config/files.php). The client's claimed
 * type is never consulted: browsers guess it from the extension, and an
 * attacker sets it to whatever passes.
 */
class FileTypePolicy
{
    /**
     * Returns the mime to store and serve the file as.
     *
     * @throws FileRejected
     */
    public function check(string $extension, string $detected): string
    {
        $extension = strtolower($extension);

        if ($this->isDenied($extension, $detected)) {
            throw FileRejected::because('This kind of file can\'t be uploaded because it could run code in a browser or on the server.');
        }

        if ($detected === 'application/x-empty' || $detected === 'inode/x-empty') {
            throw FileRejected::because('This file is empty.');
        }

        $types = $this->types()[$extension] ?? null;

        if ($types === null) {
            $label = $extension === '' ? 'Files without an extension' : ".{$extension} files";

            throw FileRejected::because("{$label} aren't allowed. Allowed: ".implode(', ', array_keys($this->types())).'.');
        }

        if (! in_array($detected, $types, true)) {
            throw FileRejected::because("This file's contents don't match its .{$extension} extension, so it was not uploaded.");
        }

        return $types[0];
    }

    public function isDenied(string $extension, string $mime = ''): bool
    {
        return in_array(strtolower($extension), $this->deniedExtensions(), true)
            || in_array(strtolower($mime), $this->deniedTypes(), true);
    }

    /**
     * @return array<string, non-empty-list<string>>
     */
    public function types(): array
    {
        /** @var array<string, non-empty-list<string>> */
        return config()->array('files.types');
    }

    /**
     * @return list<string>
     */
    private function deniedExtensions(): array
    {
        /** @var list<string> */
        return config()->array('files.denied_extensions');
    }

    /**
     * @return list<string>
     */
    private function deniedTypes(): array
    {
        /** @var list<string> */
        return array_map('strtolower', config()->array('files.denied_types'));
    }
}
