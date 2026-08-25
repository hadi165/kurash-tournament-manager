<?php

namespace App\Services;

use RuntimeException;

/**
 * The age rules do not permit this entry, or this sanction.
 *
 * Its own type, alongside DrawEligibilityException, because it is the same
 * kind of answer about a different fact: not a decision to take again with
 * your eyes open, but something about the athlete that no amount of confirming
 * will change. They were born in the year they were born in.
 *
 * Thrown where the rule is enforced rather than where it is displayed, so a
 * caller that reached the sanction service by some other route — a command, a
 * test, a screen written later — is refused by the same check as the
 * registration form.
 */
class AgeEligibilityException extends RuntimeException {}
