<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Uploads live on this disk, under one folder per site
    | ({root}/{site id}/{year}/{month}/{random name}). The root stays outside
    | public/ and every file is served through /files/{uuid}, because many
    | shared hosts don't allow the symlink `storage:link` would create.
    |
    | FilesServiceProvider registers the disk under this name unless
    | config/filesystems.php already defines one. Serving needs a local disk.
    |
    */

    'disk' => env('FILES_DISK', 'files'),

    'root' => storage_path('app/sites'),

    /*
    |--------------------------------------------------------------------------
    | Size limit
    |--------------------------------------------------------------------------
    |
    | The largest upload StarSystem accepts, in bytes. PHP's
    | upload_max_filesize and post_max_size cap it further, and most shared
    | hosts set those low (2 to 64 MB), so the effective limit is the smallest
    | of the three. The media library shows it.
    |
    */

    'max_size' => (int) env('FILES_MAX_SIZE', 256 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Allowed types
    |--------------------------------------------------------------------------
    |
    | Extension => the types fileinfo may detect for it. The first type is the
    | one stored and served. An upload is accepted only when its extension is
    | listed here and the type detected from its contents is one of that
    | extension's types, so a script renamed to .jpg is rejected. The
    | client's claimed type is never used.
    |
    | Office files are zip or OLE containers, and older fileinfo databases
    | only see the container, so those types are listed as well.
    |
    */

    'types' => [
        // Images
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'ico' => ['image/vnd.microsoft.icon', 'image/x-icon'],

        // Documents
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/CDFV2', 'application/x-ole-storage'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],

        // Audio
        'mp3' => ['audio/mpeg'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
        'ogg' => ['audio/ogg', 'application/ogg'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],

        // Video
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm'],
        'mov' => ['video/quicktime'],

        // Archives
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Always denied
    |--------------------------------------------------------------------------
    |
    | Checked before the allowed types and wins over them, so adding one of
    | these to `types` by mistake doesn't open a hole. Each could run code:
    | on the server if a host is misconfigured to execute it, or in a
    | visitor's browser under this site's origin.
    |
    | SVG is here on purpose. It looks like an image but is an XML document
    | that can carry <script> and event handlers, and a browser that opens
    | one directly runs them with this site's cookies. Rasterise logos to
    | PNG or WebP instead.
    |
    */

    'denied_extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc',
        'html', 'htm', 'xhtml', 'shtml', 'xht', 'mht', 'mhtml', 'svg', 'svgz', 'xml', 'xsl', 'xslt',
        'js', 'mjs', 'cjs', 'jsx', 'ts', 'vbs', 'wsf', 'hta',
        'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'ps1',
        'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'scr', 'jar', 'swf',
        'htaccess', 'htpasswd', 'ini',
    ],

    'denied_types' => [
        'text/x-php', 'application/x-php', 'application/x-httpd-php',
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml',
        'text/javascript', 'application/javascript', 'application/x-javascript',
        'text/x-shellscript', 'application/x-sh', 'text/x-perl', 'text/x-python',
        'application/x-dosexec', 'application/x-msdownload', 'application/x-executable',
        'application/x-sharedlib', 'application/java-archive', 'application/x-shockwave-flash',
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser caching
    |--------------------------------------------------------------------------
    |
    | How long browsers may reuse a served file before revalidating. A file's
    | bytes never change under its uuid, so revalidation is a cheap 304.
    |
    */

    'cache_seconds' => (int) env('FILES_CACHE_SECONDS', 86400),

    /*
    |--------------------------------------------------------------------------
    | Trash
    |--------------------------------------------------------------------------
    |
    | Deleted files stop being served at once but keep their bytes for this
    | many days, then files:purge-trash (scheduled daily) removes them, so
    | disk quota comes back without anyone running a command.
    |
    */

    'trash_days' => (int) env('FILES_TRASH_DAYS', 30),

];
