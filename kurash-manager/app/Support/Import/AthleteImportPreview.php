<?php

namespace App\Support\Import;

use Livewire\Wireable;

/**
 * What a submitted file would do, before it does any of it.
 *
 * The import is deliberately two steps — read and report, then write — because
 * a spreadsheet assembled by a federation over a fortnight is not something to
 * find out about halfway through. Nothing in this object has touched the
 * database.
 */
final class AthleteImportPreview implements Wireable
{
    /**
     * @param  list<AthleteImportRow>  $rows
     * @param  list<string>  $unmappedHeadings  columns in the file nothing was read from
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly array $unmappedHeadings = [],
        /** Set when the file could not be read at all, and no row was reached. */
        public readonly ?string $fatal = null,
    ) {}

    public static function failed(string $reason): self
    {
        return new self(fatal: $reason);
    }

    /**
     * See AthleteImportRow::toLivewire(). The preview survives the round trip
     * so the review table can be paged through without re-reading the file on
     * every render; what gets written is re-read regardless.
     *
     * @return array<string, mixed>
     */
    public function toLivewire(): array
    {
        return [
            'rows' => array_map(fn (AthleteImportRow $row) => $row->toLivewire(), $this->rows),
            'unmappedHeadings' => $this->unmappedHeadings,
            'fatal' => $this->fatal,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromLivewire($value): self
    {
        return new self(
            rows: array_values(array_map(
                fn ($row) => AthleteImportRow::fromLivewire((array) $row),
                (array) ($value['rows'] ?? [])
            )),
            unmappedHeadings: array_values((array) ($value['unmappedHeadings'] ?? [])),
            fatal: $value['fatal'] ?? null,
        );
    }

    /** @return list<AthleteImportRow> */
    public function ready(): array
    {
        return array_values(array_filter($this->rows, fn (AthleteImportRow $row) => $row->isReady()));
    }

    /** @return list<AthleteImportRow> */
    public function rejected(): array
    {
        return array_values(array_filter($this->rows, fn (AthleteImportRow $row) => ! $row->isReady()));
    }

    public function readyCount(): int
    {
        return count($this->ready());
    }

    public function invalidCount(): int
    {
        return count(array_filter(
            $this->rows,
            fn (AthleteImportRow $row) => $row->status === AthleteImportRow::STATUS_INVALID
        ));
    }

    public function duplicateCount(): int
    {
        return count(array_filter(
            $this->rows,
            fn (AthleteImportRow $row) => $row->status === AthleteImportRow::STATUS_DUPLICATE
        ));
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /** Is there anything here worth pressing a button about? */
    public function hasWork(): bool
    {
        return $this->fatal === null && $this->readyCount() > 0;
    }
}
