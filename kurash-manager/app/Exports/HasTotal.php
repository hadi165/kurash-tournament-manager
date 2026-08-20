<?php

namespace App\Exports;

/**
 * A report that ends in a meaningful sum.
 *
 * Optional and separate from Report on purpose: a medal standing totals its
 * medals and an entries sheet totals its entries, but a fight order has
 * nothing to add up, and a running order with a spurious "total" at the foot
 * is worse paperwork than one without.
 */
interface HasTotal
{
    /**
     * The label and figure printed in the total row under the table.
     *
     * @return array{label: string, value: string|int|float}
     */
    public function total(): array;
}
