<?php

namespace App\Devnull\Exceptions;

use RuntimeException;

/**
 * A recording that can't go as a voice note, with why, in the web's words.
 */
class InvalidVoiceNote extends RuntimeException {}
