<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    private const PREVIEW_USERS = 5;

    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $clientIds = $request->user()->accessibleClientIds();

        $clients = Client::query()
            ->withCount([
                'users',
                'users as active_users_count' => fn ($q) => $q->where('is_active', true),
            ])
            ->with(['users' => fn ($q) => $q->orderBy('name')->limit(self::PREVIEW_USERS)])
            ->when(! empty($clientIds), fn ($q) => $q->whereIn('id', $clientIds))
            ->orderBy('name')
            ->get();

        return view('admin.users.index', compact('clients'));
    }

    public function forClient(Request $request, Client $client): View
    {
        $this->authorize('view', $client);

        $users = User::query()
            ->where('client_id', $client->id)
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.client', compact('client', 'users'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', User::class);

        $client = Client::findOrFail($request->query('client'));
        $this->authorize('view', $client);

        $roles = UserRole::assignableClientRoles();

        return view('admin.users.create', [
            'client' => $client,
            'roles' => $roles,
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $client = Client::findOrFail($request->client_id);
        $this->authorize('view', $client);

        $user = User::create($request->validated());

        $this->activityLog->log('user.created', $user, clientId: $user->client_id);

        return redirect()->route('admin.clients.users.index', $client)
            ->with('success', 'User created successfully.');
    }

    public function edit(Request $request, User $user): View|RedirectResponse
    {
        if ($user->isTeamMember()) {
            return redirect()->route('admin.team.edit', $user);
        }

        $this->authorize('update', $user);

        $roles = UserRole::assignableClientRoles();

        return view('admin.users.edit', compact('user', 'roles'));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        if ($user->isTeamMember()) {
            return redirect()->route('admin.team.edit', $user);
        }

        $this->authorize('update', $user);

        $previousRole = $user->role->value;
        $user->update($request->validated());

        $properties = null;
        if ($previousRole !== $user->role->value) {
            $properties = [
                'previous_role' => $previousRole,
                'new_role' => $user->role->value,
            ];
            $this->activityLog->log('user.role_changed', $user, properties: $properties, clientId: $user->client_id);
        }

        $this->activityLog->log('user.updated', $user, properties: $properties, clientId: $user->client_id);

        return redirect()->route('admin.clients.users.index', $user->client_id)
            ->with('success', 'User updated successfully.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->isTeamMember()) {
            return redirect()->route('admin.team.edit', $user)
                ->with('error', 'Use Team to manage On IT staff accounts.');
        }

        $this->authorize('delete', $user);

        $clientId = $user->client_id;

        $this->activityLog->log('user.deleted', $user, clientId: $user->client_id);

        $user->delete();

        return redirect()->route('admin.clients.users.index', $clientId)
            ->with('success', 'User deleted successfully.');
    }
}
