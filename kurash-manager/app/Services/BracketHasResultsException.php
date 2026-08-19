<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown when regenerating a bracket would discard fights that have already
 * been decided. The caller must confirm explicitly — the original API deleted
 * them without asking, so a mis-click mid-event erased recorded scores.
 */
class BracketHasResultsException extends RuntimeException
{
    public function __construct(public readonly int $decidedBouts)
    {
        parent::__construct(
            "This category already has {$decidedBouts} decided bout(s). "
            .'Regenerating will erase them.'
        );
    }
}
