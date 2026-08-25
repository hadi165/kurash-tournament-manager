<?php

namespace App\Services;

use RuntimeException;

/**
 * The draw cannot be generated in the format it was asked for.
 *
 * Its own type, alongside DrawIsProtectedException, because the screen answers
 * it differently: a protected draw is a decision the administrator can take
 * again with their eyes open, and this one is a request the rules do not allow
 * at all — a round robin of sixteen, a knockout override with nobody's name
 * against it. There is nothing to confirm, only something to correct.
 */
class DrawFormatException extends RuntimeException {}
