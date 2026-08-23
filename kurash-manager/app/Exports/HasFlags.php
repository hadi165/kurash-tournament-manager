<?php

namespace App\Exports;

/**
 * A report whose rows carry a nation, and should fly its flag.
 *
 * Optional and separate from Report for the same reason HasTotal is: a list of
 * people handed to a hotel is read by somebody looking for a delegation, and a
 * flag is faster to find than three letters. A medal standing or a running
 * order has no use for one.
 *
 * Only the PDF flies it. The artwork is SVG, which is what a printed sheet
 * wants and what a spreadsheet cannot hold — so the workbook carries the code
 * and the country name in their own columns instead, which is what anybody
 * would sort or filter on anyway.
 */
interface HasFlags
{
    /**
     * Which column of each row holds the three-letter NOC code the flag is
     * drawn from. The code stays in the cell; the flag goes beside it.
     */
    public function flagColumn(): int;
}
