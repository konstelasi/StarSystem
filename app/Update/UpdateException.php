<?php

namespace App\Update;

use RuntimeException;

/**
 * A reason the update can't go on, worded for the site owner.
 */
class UpdateException extends RuntimeException {}
