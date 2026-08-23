{{-- The knockout tree, drawn by alignment rather than by arithmetic.

     Shared by the bracket an official works on and the bracket a hall watches,
     because two connector implementations are two things to keep in step and
     one of them always drifts. The nodes differ — one has buttons on it and
     the other does not — but the geometry is the same drawing, so it lives
     here and nowhere else.

     Each round is a column; each slot in it takes an equal share of the
     column's height. Round two therefore has half as many slots at twice the
     height, and each one's centre falls exactly on the midpoint of the pair
     that feeds it — which is the point every connector is hung from, at every
     size, with no case per bracket.

     The host sets --bkt-line to whatever its own palette calls a hairline. --}}
.bkt {
    display: flex;
    align-items: stretch;
    --bkt-gutter: 2.5rem;
}

.bkt__round {
    display: flex;
    flex: 1 1 0;
    min-width: 15rem;
    flex-direction: column;
    /* The gutter the connectors are drawn in. The last column has no next
       round to reach, so it has no gutter. */
    padding-right: var(--bkt-gutter);
}

.bkt__round--last { padding-right: 0; }

/* The champion needs room for a name and nothing else. */
.bkt__round--champion { flex: 0 0 auto; min-width: 12rem; }

.bkt__slots {
    display: flex;
    flex: 1;
    flex-direction: column;
}

.bkt__slot {
    position: relative;
    display: flex;
    flex: 1 1 0;
    align-items: center;
    padding: 0.375rem 0;
}

.bkt__match { width: 100%; position: relative; }

/* Three connectors, three separate elements — a slot in the middle of the
   tree is both a target and a source, and two pseudo-elements cannot carry
   three lines.

     slot::after   out of this slot into the gutter
     slot::before  the vertical this pair hangs on
     match::before in from the gutter, for every round after the first
*/
.bkt__slot::after,
.bkt__slot::before,
.bkt__match::before {
    content: '';
    position: absolute;
    background: var(--bkt-line, #94a3b8);
}

/* Out of every slot, half the gutter, stopping at the vertical. */
.bkt__round:not(.bkt__round--last) .bkt__slot::after {
    left: 100%;
    top: 50%;
    width: calc(var(--bkt-gutter) / 2);
    height: 2px;
    margin-top: -1px;
}

/* The vertical, hung on the top slot of each pair and reaching down exactly
   one slot height — centre to centre, because the slots are equal.

   `:not(:last-child)` is what keeps a round with a single slot — the final —
   from hanging a vertical off the bottom of itself with nothing to reach. */
.bkt__round:not(.bkt__round--last) .bkt__slot:nth-child(odd):not(:last-child)::before {
    left: calc(100% + var(--bkt-gutter) / 2);
    top: 50%;
    width: 2px;
    height: 100%;
    margin-top: -1px;
}

/* In from the gutter, for every round after the first. Hung on the match
   rather than the slot so it cannot collide with the vertical above. */
.bkt__round:not(:first-child) .bkt__match::before {
    right: 100%;
    top: 50%;
    width: calc(var(--bkt-gutter) / 2);
    height: 2px;
    margin-top: -1px;
}
