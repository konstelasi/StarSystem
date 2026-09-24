<?php

namespace App\Modules;

use RuntimeException;

/**
 * Something an admin did with a module that can't go ahead. The message is
 * written for the admin and shown as is.
 */
class ModuleException extends RuntimeException {}
