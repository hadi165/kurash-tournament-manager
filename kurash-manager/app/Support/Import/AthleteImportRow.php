<?php

namespace App\Support\Import;

use Livewire\Wireable;

/**
 * One line of a submitted spreadsheet, as the importer read it.
 *
 * Carries the row number from the file rather than its position in the parsed
 * list, because the only useful thing to tell somebody about a bad row is where
 * to find it in the workbook they are looking at — and the two differ the
 * moment a blank line is skipped.
 */
final class AthleteImportRow implements Wireable
{
    public const STATUS_READY = 'ready';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_DUPLICATE = 'duplicate';

    /**
     * @param  int  $number  the line in the workbook, heading included
     * @param  array<string, mixed>  $raw  the cells as they were read, for the review table
     * @param  array<string, mixed>  $attributes  what would be written, once valid
     * @param  list<string>  $errors  why it cannot be, in words an official can act on
     */
    public function __construct(
        public readonly int $number,
        public readonly array $raw,
        public array $attributes = [],
        public array $errors = [],
        public string $status = self::STATUS_READY,
    ) {}

    public function fail(string $reason): void
    {
        $this->errors[] = $reason;
        $this->status = self::STATUS_INVALID;
    }

    /**
     * A row that names somebody already registered, or named twice in the same
     * file. Kept apart from an invalid row: nothing is wrong with it, it has
     * simply already been done, and telling an official their file is broken
     * when it is merely a re-import would send them looking for a fault that
     * is not there.
     */
    public function duplicate(string $reason): void
    {
        $this->errors[] = $reason;
        $this->status = self::STATUS_DUPLICATE;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /** What went wrong, as one line for the review table. */
    public function reason(): string
    {
        return implode(' ', $this->errors);
    }

    /**
     * Livewire keeps public properties in the browser's payload, so anything
     * held in one has to survive a round trip through JSON. Implementing the
     * contract is the supported way to say how — the alternative is flattening
     * the preview into loose arrays and rebuilding it in the view, which puts
     * the shape of the data in two places.
     *
     * Nothing here is trusted on the way back in: the import re-reads the
     * workbook before it writes anything, so a tampered payload changes what a
     * review table looks like and not what gets registered.
     *
     * @return array<string, mixed>
     */
    public function toLivewire(): array
    {
        return [
            'number' => $this->number,
            'raw' => $this->raw,
            'attributes' => $this->attributes,
            'errors' => $this->errors,
            'status' => $this->status,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromLivewire($value): self
    {
        return new self(
            number: (int) ($value['number'] ?? 0),
            raw: (array) ($value['raw'] ?? []),
            attributes: (array) ($value['attributes'] ?? []),
            errors: array_values((array) ($value['errors'] ?? [])),
            status: (string) ($value['status'] ?? self::STATUS_INVALID),
        );
    }

    public function cell(string $key): string
    {
        $value = $this->raw[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
