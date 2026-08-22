<?php

namespace App\Services;

use RuntimeException;

/**
 * The draw is published or locked, and redrawing it needs a decision first.
 *
 * Separate from BracketHasResultsException: that one guards results already
 * recorded, this one guards a table other people have been told to work from.
 */
class DrawIsProtectedException extends RuntimeException {}
