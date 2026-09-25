<?php

/*
| Builds the release zip, its .sha256 and the updater's release.json into
| dist/.
|
|   php build/release.php [ref] [--out=dist] [--download-base=https://...]
|
| --download-base is the URL the zip will be published under, ending in a
| slash; release.json points the updater there.
|
| Run `npm run build` first: the zip takes its front-end from public/build.
*/

use Build\ReleaseBuilder;

require __DIR__.'/../vendor/autoload.php';

$ref = 'HEAD';
$out = dirname(__DIR__).DIRECTORY_SEPARATOR.'dist';
$downloadBase = '';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $out = substr($arg, strlen('--out='));
    } elseif (str_starts_with($arg, '--download-base=')) {
        $downloadBase = substr($arg, strlen('--download-base='));
    } else {
        $ref = $arg;
    }
}

try {
    $release = (new ReleaseBuilder(dirname(__DIR__), $ref, downloadBase: $downloadBase))->build($out);
} catch (Throwable $e) {
    fwrite(STDERR, 'Release failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}

echo "StarSystem {$release['version']}".PHP_EOL;
echo $release['zip'].PHP_EOL;
echo 'sha256 '.$release['sha256'].PHP_EOL;
