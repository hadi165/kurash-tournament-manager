<?php

namespace App\Services;

use RuntimeException;

/**
 * Somebody in this draw has not been admitted to competition.
 *
 * Its own type, alongside DrawIsProtectedException and DrawFormatException,
 * because the answer differs again: a protected draw is a decision to take
 * with your eyes open, an impossible format is a request to correct, and this
 * is a fact about the scale that no amount of confirming will change. The
 * athlete is weighed, or they are not in the draw.
 *
 * Thrown where the rule is enforced rather than where it is displayed, so a
 * caller that reached the generator by some other route — a command, a test, a
 * screen written later — is refused by the same check as the draw screen.
 */
class DrawEligibilityException extends RuntimeException {}
