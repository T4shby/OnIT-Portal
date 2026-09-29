<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTeamMemberRequest;
use App\Http\Requests\Admin\UpdateTeamMemberRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function index(): View
    {
        $this->authorize('manageTeam', User::class);

        $users = User::query()
            ->whereIn('role', UserRole::adminRoles())
            ->with('assignedClients')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $organisationName = config('services.portal.organisation_name');

        return view('admin.team.index', compact('users', 'organisationName'));
    }

    public function create(): View
    {
        $this->authorize('manageTeam', User::class);

        $clients = Client::where('is_active', true)->orderBy('name')->get();
        $roles = array_filter(UserRole::cases(), fn (UserRole $role) => $role->isAdmin());
        $organisationName = config('services.portal.organisation_name');

        return view('admin.team.create', compact('clients', 'roles', 'organisationName'));
    }

    public function store(StoreTeamMemberRequest $request): RedirectResponse
    {
        $this->authorize('manageTeam', User::class);

        $user = User::create([
            ...$request->safe()->except('assigned_clients'),
            'client_id' => null,
        ]);

        if ($request->role === UserRole::AccountManager->value && $request->assigned_clients) {
            $user->assignedClients()->sync($request->assigned_clients);
        }

        $this->activityLog->log('user.created', $user);

        return redirect()->route('admin.team.index')
            ->with('success', 'Team member added successfully.');
    }

    public function edit(User $user): View
    {
        $this->authorize('manageTeam', User::class);
        $this->ensureTeamMember($user);

        $clients = Client::where('is_active', true)->orderBy('name')->get();
        $roles = array_filter(UserRole::cases(), fn (UserRole $role) => $role->isAdmin());
        $assignedClients = $user->assignedClients()->pluck('clients.id')->toArray();
        $inactiveAssignedClients = $user->assignedClients()
            ->where('clients.is_active', false)
            ->orderBy('name')
            ->pluck('clients.name')
            ->all();
        $organisationName = config('services.portal.organisation_name');

        return view('admin.team.edit', compact('user', 'clients', 'roles', 'assignedClients', 'inactiveAssignedClients', 'organisationName'));
    }

    public function update(UpdateTeamMemberRequest $request, User $user): RedirectResponse
    {
        $this->authorize('manageTeam', User::class);
        $this->ensureTeamMember($user);

        $user->update([
            ...$request->safe()->except('assigned_clients'),
            'client_id' => null,
        ]);

        if ($request->role === UserRole::AccountManager->value) {
            // The form only renders active clients, so an unticked box can only
            // mean "unassign" for those. Keep existing inactive-client
            // assignments, which sync() would otherwise silently drop.
            $inactiveAssigned = $user->assignedClients()
                ->where('clients.is_active', false)
                ->pluck('clients.id')
                ->all();

            $user->assignedClients()->sync(array_values(array_unique(array_map('intval', [
                ...($request->assigned_clients ?? []),
                ...$inactiveAssigned,
            ]))));
        } else {
            $user->assignedClients()->detach();
        }

        $this->activityLog->log('user.updated', $user);

        return redirect()->route('admin.team.index')
            ->with('success', 'Team member updated successfully.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('manageTeam', User::class);
        $this->ensureTeamMember($user);

        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot remove your own account.');
        }

        $this->activityLog->log('user.deleted', $user);

        $user->delete();

        return redirect()->route('admin.team.index')
            ->with('success', 'Team member removed successfully.');
    }

    private function ensureTeamMember(User $user): void
    {
        if (! $user->isTeamMember()) {
            abort(404);
        }
    }
}
