<?php

/*
| Docroot fallback. When a host serves this folder instead of public/, the
| .htaccess next to this file forwards requests to public/ and denies the
| project's internals. This file only answers if the server reaches it
| directly, and hands the request to the real front controller.
*/

require __DIR__.'/public/index.php';
