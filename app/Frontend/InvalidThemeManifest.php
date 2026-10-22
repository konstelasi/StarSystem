<?php

namespace App\Frontend;

use RuntimeException;

/**
 * A theme.json that can't be used. The message is shown to the admin.
 */
class InvalidThemeManifest extends RuntimeException {}
