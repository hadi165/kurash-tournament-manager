/**
 * A code typed into a box that knows the codes.
 *
 * Registration asks for a three-letter NOC code, and there are two hundred of
 * them. Getting one wrong puts another country's flag beside an athlete's name
 * on a screen in front of their delegation, so the field suggests as it is
 * typed rather than waiting to complain at the end.
 *
 * The list is handed in from the server, matched here. Two hundred entries is
 * small enough that a round trip per keystroke would be slower than the answer.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('nocSuggest', ({ nations, country = null, limit = 8 }) => ({
        open: false,
        active: -1,
        matches: [],

        /** Codes beginning with what has been typed, in code order. */
        search(value) {
            const typed = (value || '').trim().toUpperCase()

            if (typed === '') {
                return this.close()
            }

            this.matches = Object.entries(nations)
                .filter(([code]) => code.startsWith(typed))
                .slice(0, limit)

            // A code typed out in full and recognised is an answer, not a
            // question — the list has nothing left to offer, so it goes away.
            if (this.matches.length === 1 && this.matches[0][0] === typed) {
                return this.close()
            }

            this.open = this.matches.length > 0
            this.active = this.open ? 0 : -1
        },

        /** Wraps, so holding the arrow key never dead-ends. */
        move(step) {
            if (!this.open || this.matches.length === 0) {
                return
            }

            this.active = (this.active + step + this.matches.length) % this.matches.length
        },

        choose(index) {
            const match = this.matches[index === undefined ? this.active : index]

            if (!match) {
                return
            }

            const [code, nation] = match

            this.fill(this.$refs.code, code)

            // Found by id rather than through a ref: the country sits in
            // its own cell of the form grid, outside this field's scope.
            //
            // Filled on an explicit choice, and overwritten: picking a
            // different nation means the country beside it is now the wrong
            // one, and leaving it would be worse than replacing it.
            const field = country ? document.getElementById(country) : null

            if (field) {
                this.fill(field, nation)
            }

            this.close()
            this.$refs.code.focus()
        },

        /**
         * Written through an input event rather than by assignment alone, so
         * Livewire's own binding sees the change instead of only the DOM.
         */
        fill(el, value) {
            el.value = value
            el.dispatchEvent(new Event('input', { bubbles: true }))
        },

        /** Closes only when focus has actually left the field and its list. */
        leave(event) {
            if (!this.$el.contains(event.relatedTarget)) {
                this.close()
            }
        },

        close() {
            this.open = false
            this.active = -1
            this.matches = []
        },
    }))
})
