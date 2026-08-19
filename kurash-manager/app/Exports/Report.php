<?php

namespace App\Exports;

/**
 * One printable/downloadable table.
 *
 * The planning specification asks for the same tables in both PDF and
 * spreadsheet form, so a report describes its content once and the two writers
 * render it. Adding a report means adding one class, not two exporters and two
 * templates.
 */
interface Report
{
    /** Title shown at the top of the PDF. */
    public function title(): string;

    /**
     * Download name, without extension.
     *
     * The specification fixes these — "Male -91" for a confirmed weigh-in list,
     * "Draw-Male -91" for a draw sheet — because the federation files them by
     * name.
     */
    public function filename(): string;

    /**
     * Context lines printed under the title, such as the championship name and
     * the "Gender / Weight Category" header the specification requires.
     *
     * @return array<string, string>
     */
    public function meta(): array;

    /** @return list<string> */
    public function headings(): array;

    /** @return list<list<string|int|float|null>> */
    public function rows(): array;
}
