<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property string $role
 * @property bool $is_active
 * @property int|null $scoreboard_championship_id
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public const ROLE_ADMIN = 'admin';

    public const ROLE_SUPERVISOR = 'supervisor';

    /**
     * The Chief Referee, as the IKA rules name them.
     *
     * An office rather than a rank. Section 25(2) gives this one official the
     * power to let a 16- or 17-year-old into an adults' competition, and the
     * point of the rule is that a particular person answers for it — so it is
     * not folded into the administrator, who can already do everything and
     * whose approval would therefore say nothing about who decided.
     *
     * Reaches the competition screens, because a sanction is granted from the
     * entry list. Not confined to a mat: this is the office that oversees
     * refereeing, not a chair on it.
     */
    public const ROLE_CHIEF_REFEREE = 'chief_referee';

    /** The operator: works a mat and the presentation screens. */
    public const ROLE_OFFICIAL = 'official';

    public const ROLE_VIEWER = 'viewer';

    /** Reads one scoreboard and nothing else. */
    public const ROLE_SCOREBOARD_VIEWER = 'scoreboard_viewer';

    /**
     * Scores contests on a mat, and reaches nothing else.
     *
     * Deliberately not a variant of official, which reads every competition
     * screen but may change none of them. A referee is the reverse: they change
     * the one thing that matters most — the result — and should not be able to
     * open the draw it sits in.
     */
    public const ROLE_REFEREE = 'referee';

    /** Roles allowed to change competition data. */
    public const MANAGING_ROLES = [self::ROLE_ADMIN, self::ROLE_SUPERVISOR];

    /**
     * What an admin may hand out from the account form.
     *
     * A server-side allowlist, checked after validation and never taken from
     * the request as-is: admin is absent on purpose, so no form post can mint
     * an account that can mint accounts.
     */
    public const ASSIGNABLE_ROLES = [
        self::ROLE_CHIEF_REFEREE,
        self::ROLE_OFFICIAL,
        self::ROLE_REFEREE,
        self::ROLE_SCOREBOARD_VIEWER,
    ];

    /**
     * Roles that may read a scoreboard.
     *
     * Stated as a permission rather than inferred from "can manage": an
     * operator watching a mat and an admin watching from an office are doing
     * the same read, and neither of them is scoring from that screen.
     */
    public const SCOREBOARD_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_SUPERVISOR,
        self::ROLE_CHIEF_REFEREE,
        self::ROLE_OFFICIAL,
        self::ROLE_VIEWER,
        self::ROLE_SCOREBOARD_VIEWER,
        self::ROLE_REFEREE,
    ];

    /**
     * Roles that may score a contest on a mat.
     *
     * Separate from MANAGING_ROLES on purpose. Scoring and running the
     * competition were the same permission until referees had accounts of their
     * own, and collapsing them again would hand every referee the draw.
     */
    public const SCORING_ROLES = [self::ROLE_ADMIN, self::ROLE_SUPERVISOR, self::ROLE_REFEREE];

    /**
     * Roles confined to a mat and a board.
     *
     * The two accounts that have no business on a competition screen, for the
     * opposite reasons: one may only watch, and one may only score.
     */
    public const CONFINED_ROLES = [self::ROLE_SCOREBOARD_VIEWER, self::ROLE_REFEREE];

    /**
     * Every capability check goes through is_active first.
     *
     * A closed account keeps its rows — it is referenced by everything it ever
     * recorded — so "closed" has to mean "authorises nothing" rather than
     * "deleted".
     */
    public function canManageCompetition(): bool
    {
        return $this->is_active && in_array($this->role, self::MANAGING_ROLES, true);
    }

    /**
     * The administrator, as distinct from everybody who may run a competition.
     *
     * Supervisors manage competitions — they draw, publish and delete — but a
     * decision taken against the IKA rule is narrower than that, and the
     * account that signs for it is named here rather than inferred from
     * MANAGING_ROLES.
     */
    public function isAdmin(): bool
    {
        return $this->is_active && $this->role === self::ROLE_ADMIN;
    }

    /**
     * The Chief Referee, as distinct from anybody senior.
     *
     * Named exactly, and not widened to include administrators: Section 25(2)
     * gives the power to an office, and an approval that any senior account
     * could have granted does not record who decided. Same reasoning as
     * isAdmin() backing draw.override_format, one step narrower.
     */
    public function isChiefReferee(): bool
    {
        return $this->is_active && $this->role === self::ROLE_CHIEF_REFEREE;
    }

    public function canViewScoreboard(): bool
    {
        return $this->is_active && in_array($this->role, self::SCOREBOARD_ROLES, true);
    }

    public function isScoreboardViewer(): bool
    {
        return $this->role === self::ROLE_SCOREBOARD_VIEWER;
    }

    public function isReferee(): bool
    {
        return $this->role === self::ROLE_REFEREE;
    }

    /**
     * The mats this account has been assigned to work.
     *
     * @return BelongsToMany<Court, $this>
     */
    public function courts(): BelongsToMany
    {
        return $this->belongsToMany(Court::class, 'court_referee')->withTimestamps();
    }

    /**
     * May this account referee on this mat?
     *
     * Two questions, and both have to pass. Holding the referee role says what
     * kind of work the account does; the assignment says where. A role on its
     * own would let the referee on mat one score the final on mat three by
     * editing a number in the address bar, which is the whole thing this
     * guards against.
     *
     * Everyone else who may score — an admin, a supervisor — is not assigned
     * to mats at all and reaches every one, because running the competition is
     * what their role is for.
     */
    public function mayRefereeCourt(?Court $court): bool
    {
        if (! $this->canScoreBouts() || $court === null) {
            return false;
        }

        if (! $this->isReferee()) {
            return true;
        }

        // Read through the relation so an assignment revoked mid-session bites
        // on the next request rather than at the next sign-in.
        return $this->courts()->whereKey($court->getKey())->exists();
    }

    /**
     * The mat ids this account may work, or null for an account that is not
     * limited to any.
     *
     * Null and an empty list mean opposite things and must not be confused: an
     * admin is unrestricted, a referee with nothing assigned reaches nothing.
     *
     * @return list<int>|null
     */
    public function refereeCourtIds(): ?array
    {
        if (! $this->isReferee()) {
            return null;
        }

        // array_values keeps it a list: pluck preserves the collection's keys,
        // and a gap in them is not something the callers are prepared for.
        return array_values($this->courts()->pluck('courts.id')->map(fn ($id) => (int) $id)->all());
    }

    /** May this account record calls and declare a winner on a mat? */
    public function canScoreBouts(): bool
    {
        return $this->is_active && in_array($this->role, self::SCORING_ROLES, true);
    }

    /**
     * Is this account confined to a mat and a board?
     *
     * What keeps a referee and a scoreboard viewer out of the competition
     * screens by typing a URL, rather than by not being shown a link.
     */
    public function isConfinedToMat(): bool
    {
        return in_array($this->role, self::CONFINED_ROLES, true);
    }

    public function canManageUsers(): bool
    {
        return $this->is_active && $this->role === self::ROLE_ADMIN;
    }

    /**
     * Is this championship inside the account's scoreboard scope?
     *
     * A null scope is every championship, which is what an unscoped account
     * means; a set scope is exactly one. Checked in the query as well as here,
     * so a tampered id never reaches a board.
     */
    public function mayViewChampionship(?Championship $championship): bool
    {
        if (! $this->canViewScoreboard()) {
            return false;
        }

        // A referee's scope is their mats, and a mat belongs to exactly one
        // championship — so the championship they may read is whichever their
        // assignments are in. An account with no assignment reaches nothing,
        // which is the point of the assignment.
        if ($this->isReferee()) {
            return $championship !== null
                && $this->courts()->where('championship_id', $championship->getKey())->exists();
        }

        return $this->scoreboard_championship_id === null
            || $championship?->getKey() === $this->scoreboard_championship_id;
    }

    /** @return BelongsTo<Championship, $this> */
    public function scoreboardChampionship(): BelongsTo
    {
        return $this->belongsTo(Championship::class, 'scoreboard_championship_id');
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
