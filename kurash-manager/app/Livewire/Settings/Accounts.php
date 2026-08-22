<?php

namespace App\Livewire\Settings;

use App\Models\Championship;
use App\Models\Court;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Accounts, as an admin manages them.
 *
 * Two kinds are handed out here — an operator who works the competition, and a
 * scoreboard viewer who only watches one. Admin is not on the list: an account
 * that can mint accounts is not something a form should be able to create, and
 * leaving it off the allowlist is what makes that true rather than a habit.
 */
class Accounts extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    /** Validated against User::ASSIGNABLE_ROLES, never trusted from the request. */
    public string $role = User::ROLE_SCOREBOARD_VIEWER;

    /** Optional: pins a scoreboard viewer to one championship. Null is all of them. */
    public ?string $scopeChampionshipId = null;

    /**
     * The mats a referee works, as ids from the form.
     *
     * Strings because that is what a checkbox posts, and validated against the
     * courts table rather than trusted — the browser's copy of the list is
     * never the authority on which mats exist.
     *
     * @var list<string>
     */
    public array $courtIds = [];

    public ?int $editingId = null;

    public function mount(): void
    {
        Gate::authorize('manage-users');
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique(User::class, 'email')->ignore($this->editingId),
            ],
            // Only ever these two, whatever the browser sends.
            'role' => ['required', Rule::in(User::ASSIGNABLE_ROLES)],
            'scopeChampionshipId' => ['nullable', Rule::exists(Championship::class, 'id')],
            'courtIds' => ['array'],
            'courtIds.*' => [Rule::exists(Court::class, 'id')],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:8'],
        ];
    }

    public function save(): void
    {
        Gate::authorize('manage-users');

        $data = $this->validate();

        $user = $this->editingId
            ? User::query()->whereIn('role', User::ASSIGNABLE_ROLES)->findOrFail($this->editingId)
            : new User;

        $existing = $user->exists;

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        // Assigned server-side after validation, and never mass-assigned: role
        // is deliberately absent from the model's fillable list.
        $user->role = $data['role'];
        $user->scoreboard_championship_id = $data['role'] === User::ROLE_SCOREBOARD_VIEWER
            ? ($data['scopeChampionshipId'] ?: null)
            : null;

        if (! $existing) {
            $user->is_active = true;
            // An admin creating the account is the verification.
            $user->email_verified_at = now();
        }

        if (filled($data['password'])) {
            // Hashed by the model's cast, which is the framework's mechanism —
            // and the plaintext never leaves this method.
            $user->password = $data['password'];
        }

        $user->save();

        // Assignments are replaced wholesale rather than added to, so clearing
        // every box revokes every mat — which is what an admin unticking them
        // means, and what §30.6 calls revoking access.
        $user->courts()->sync(
            $data['role'] === User::ROLE_REFEREE
                ? array_map('intval', $data['courtIds'])
                : []
        );

        $this->audit($existing ? 'account.updated' : 'account.created', $user);

        $this->reset('name', 'email', 'password', 'editingId', 'scopeChampionshipId', 'courtIds');
        $this->role = User::ROLE_SCOREBOARD_VIEWER;

        session()->flash('status', $existing ? __('Account updated.') : __('Account created.'));
    }

    public function edit(int $id): void
    {
        Gate::authorize('manage-users');

        $user = User::query()->whereIn('role', User::ASSIGNABLE_ROLES)->findOrFail($id);

        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->role;
        $this->scopeChampionshipId = $user->scoreboard_championship_id
            ? (string) $user->scoreboard_championship_id
            : null;
        $this->courtIds = array_values(
            $user->courts()->pluck('courts.id')->map(fn ($id) => (string) $id)->all()
        );
        $this->password = '';
    }

    public function cancelEdit(): void
    {
        $this->reset('name', 'email', 'password', 'editingId', 'scopeChampionshipId', 'courtIds');
        $this->role = User::ROLE_SCOREBOARD_VIEWER;
        $this->resetValidation();
    }

    /** Close an account, or open it again. The row itself is never deleted. */
    public function toggleActive(int $id): void
    {
        Gate::authorize('manage-users');

        $user = User::query()->whereIn('role', User::ASSIGNABLE_ROLES)->findOrFail($id);

        $user->is_active = ! $user->is_active;
        $user->save();

        $this->audit($user->is_active ? 'account.reactivated' : 'account.deactivated', $user);

        session()->flash('status', $user->is_active ? __('Account reactivated.') : __('Account deactivated.'));
    }

    /**
     * The record of who did what to which account.
     *
     * Identifiers and roles only: a password or a reset token in a log file is
     * a password or a reset token in every backup of that log file.
     */
    private function audit(string $action, User $user): void
    {
        Log::info($action, [
            'account_id' => $user->id,
            'account_email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'scoreboard_championship_id' => $user->scoreboard_championship_id,
            // Which mats an account may work is a permission, so a change to it
            // belongs in the record beside the role it qualifies.
            'court_ids' => $user->courts()->pluck('courts.id')->all(),
            'by_user_id' => auth()->id(),
        ]);
    }

    public function render(): View
    {
        return view('livewire.settings.accounts', [
            'accounts' => User::query()
                ->whereIn('role', User::ASSIGNABLE_ROLES)
                ->with(['scoreboardChampionship', 'courts.championship'])
                ->orderBy('name')
                ->get(),
            'championships' => Championship::query()->whereNull('archived_at')->orderBy('title')->get(),
            // Only mats of a live championship can be handed out: assigning a
            // referee to an archived event's mat would be assigning them to
            // nothing.
            'courts' => Court::query()
                ->whereHas('championship', fn ($q) => $q->whereNull('archived_at'))
                ->with('championship')
                ->orderBy('championship_id')
                ->orderBy('number')
                ->get(),
        ]);
    }
}
