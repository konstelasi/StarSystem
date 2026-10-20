<?php

/*
| Builds the release zip, its .sha256 and the updater's release.json into
| dist/.
|
|   php build/release.php [ref] [--out=dist] [--download-base=https://...] [--unsigned]
|
| --download-base is the URL the zip will be published under, ending in a
| slash; release.json points the updater there.
|
| release.json is signed with the key in STARSYSTEM_RELEASE_KEY (base64 of
| the 64-byte secret key). Installs refuse an unsigned feed, so without the
| key the build stops, unless --unsigned asks for a local test build.
|
| Run `npm run build` first: the zip takes its front-end from public/build.
*/

use App\Update\FeedSignature;
use Build\ReleaseBuilder;

require __DIR__.'/../vendor/autoload.php';

$ref = 'HEAD';
$out = dirname(__DIR__).DIRECTORY_SEPARATOR.'dist';
$downloadBase = '';
$unsigned = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $out = substr($arg, strlen('--out='));
    } elseif (str_starts_with($arg, '--download-base=')) {
        $downloadBase = substr($arg, strlen('--download-base='));
    } elseif ($arg === '--unsigned') {
        $unsigned = true;
    } else {
        $ref = $arg;
    }
}

$signingKey = trim((string) getenv('STARSYSTEM_RELEASE_KEY'));

if ($unsigned) {
    $signingKey = '';
} elseif ($signingKey === '') {
    fwrite(STDERR, 'No signing key. Set STARSYSTEM_RELEASE_KEY, or pass --unsigned for a local test build.'.PHP_EOL);
    exit(1);
}

try {
    $release = (new ReleaseBuilder(dirname(__DIR__), $ref, downloadBase: $downloadBase, signingKey: $signingKey === '' ? null : $signingKey))->build($out);

    // A key that isn't the one in the code would publish a release that
    // every install refuses, so catch it here, before anything is published.
    if ($signingKey !== '') {
        $feed = json_decode((string) file_get_contents($release['feed']), true);

        if (! is_array($feed) || ! FeedSignature::verify($feed, FeedSignature::PUBLIC_KEY)) {
            throw new RuntimeException('The signing key does not match FeedSignature::PUBLIC_KEY, so installs would refuse this release.');
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Release failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}

echo "StarSystem {$release['version']}".PHP_EOL;
echo $release['zip'].PHP_EOL;
echo 'sha256 '.$release['sha256'].PHP_EOL;
echo ($signingKey === '' ? 'unsigned, for local use only' : 'signed').PHP_EOL;
