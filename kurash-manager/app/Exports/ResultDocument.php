<?php

namespace App\Exports;

/**
 * A report of what happened, rather than of what is being prepared.
 *
 * Entries, weigh-ins, draws and running orders are the competition being set
 * up; medals and results are the competition having happened. They print in
 * different colours so a table covered in paper can be read at a glance, and
 * this is how a report says which it is.
 *
 * A marker rather than a method: there are two schemes and no report should be
 * choosing its own shade.
 */
interface ResultDocument {}
