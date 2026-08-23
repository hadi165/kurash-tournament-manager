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

/**
 * The buzzer a contest ends on.
 *
 * Sounded on the transition into a decided contest, not on the state itself: a
 * board opened while a result is already showing has not just seen a contest
 * end, and blaring at whoever opened it would be wrong. So the bout on screen
 * at load is marked as already sounded, and only a change after that rings.
 *
 * Once per contest. A board polls every two seconds and re-renders on each,
 * and a buzzer that sounded on every render of a decided contest would sound
 * for as long as the result stayed up.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('finishBell', ({ src = '', bout = null, decided = false }) => {
        /*
         * Held in the closure, deliberately not on the returned object.
         *
         * Alpine wraps its data in a reactive proxy, and an HTMLAudioElement
         * put inside one is proxied with it — after which play() is being
         * called on the proxy rather than on the element, and the browser
         * refuses it as an illegal invocation. Which is exactly as silent as
         * having no sound at all, and looks the same from the hall.
         */
        let audio = null
        let poll = null

        return {
            // Browsers refuse to play audio on a page nobody has touched. A
            // board on a projector may never be touched, so this is surfaced
            // rather than failing quietly — the operator gets something to
            // press.
            armed: false,
            sounded: decided ? bout : null,

            init() {
                if (!src) {
                    return
                }

                audio = new Audio(src)
                audio.preload = 'auto'

                // Fetched now rather than at the whistle. A buzzer that starts
                // downloading a megabyte when the contest ends is a buzzer
                // that sounds late, and its absence from the network log is
                // the first thing anybody looks for when it does not sound at
                // all.
                audio.load()

                console.info('[kurash] end-of-contest sound loaded:', src)

                // Any interaction anywhere on the page counts, including the
                // ones the operator was going to make anyway.
                const unlock = () => this.arm()

                document.addEventListener('pointerdown', unlock)
                document.addEventListener('keydown', unlock)

                // Watched on its own clock rather than through a reactive
                // expression. The board is re-rendered by Livewire every
                // couple of seconds and the attributes below are rewritten
                // with it; reading them four times a second is the one way of
                // noticing that does not depend on how a morph is applied.
                poll = setInterval(() => this.read(), 250)
                this.read()
            },

            destroy() {
                clearInterval(poll)
            },

            read() {
                const id = this.$el.dataset.bout

                this.watch(
                    id === '' || id === undefined ? null : Number(id),
                    this.$el.dataset.decided === '1',
                )
            },

            /**
             * Pressed deliberately, so it answers audibly. Clipped short: the
             * question being asked is whether this machine makes a noise, and
             * the whole buzzer is a long way to go to say yes.
             */
            test() {
                if (!audio) {
                    return
                }

                this.armed = true
                audio.muted = false
                audio.currentTime = 0

                audio.play().then(() => {
                    setTimeout(() => {
                        audio.pause()
                        audio.currentTime = 0
                    }, 900)
                }).catch((error) => {
                    this.armed = false
                    console.warn('[kurash] could not play the end-of-contest sound:', error)
                })
            },

            /** Play once, silently, which is what a browser accepts as consent. */
            arm() {
                if (!audio || this.armed) {
                    return
                }

                audio.muted = true

                audio.play().then(() => {
                    audio.pause()
                    audio.currentTime = 0
                    audio.muted = false
                    this.armed = true
                }).catch((error) => {
                    audio.muted = false

                    // Said out loud rather than swallowed. This failing is
                    // indistinguishable from having no sound configured, and
                    // the last time it failed it took a while to find.
                    console.warn('[kurash] could not arm the end-of-contest sound:', error)
                })
            },

            /**
             * Called on every read with what is on screen. Everything about
             * not repeating lives here rather than at the call sites, so a
             * second screen cannot get it subtly different.
             */
            watch(boutId, isDecided) {
                if (!isDecided) {
                    // Back to an undecided contest — the next end is a new
                    // one, including the same bout reopened and decided again.
                    this.sounded = null

                    return
                }

                if (boutId === null || this.sounded === boutId) {
                    return
                }

                this.sounded = boutId
                this.ring()
            },

            ring() {
                if (!audio) {
                    return
                }

                audio.currentTime = 0

                audio.play().catch((error) => {
                    this.armed = false
                    console.warn('[kurash] could not sound the end of the contest:', error)
                })
            },
        }
    })

    /**
     * Auditioning a buzzer on the mat screen. Same reason the element is held
     * in the closure: a proxied media element is one that will not play.
     */
    window.Alpine.data('soundPreview', () => {
        let playing = null

        return {
            play(src) {
                // Stopped before the next one starts, or holding the button
                // lays them over each other.
                if (playing) {
                    playing.pause()
                }

                playing = new Audio(src)
                playing.play().catch((error) => {
                    console.warn('[kurash] could not play that sound:', error)
                })
            },
        }
    })
})
