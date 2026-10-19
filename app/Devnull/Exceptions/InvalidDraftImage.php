<?php

namespace App\Devnull\Exceptions;

use RuntimeException;

/**
 * A photo devnull wouldn't take, with why, in the web's words.
 */
class InvalidDraftImage extends RuntimeException {}
