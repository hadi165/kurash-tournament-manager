<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown when something tries to change a competition that has been closed.
 *
 * Screens catch this and show the message; it reaching a user as a 500 means a
 * mutation path exists that has no archived check in front of it, which is
 * worth finding rather than swallowing.
 */
class ChampionshipArchivedException extends RuntimeException {}
