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

        $internalUsers = User::query()
            ->whereNull('client_id')
            ->orderBy('name')
            ->get();

        $internalPreview = $internalUsers->take(self::PREVIEW_USERS);

        return view('admin.users.index', compact('clients', 'internalUsers', 'internalPreview'));
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

    public function internal(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->whereNull('client_id')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.internal', compact('users'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', User::class);

        $clientIds = $request->user()->accessibleClientIds();
        $clients = Client::when(! empty($clientIds), fn ($q) => $q->whereIn('id', $clientIds))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $roles = UserRole::cases();
        $selectedClientId = old('client_id', $request->query('client'));

        return view('admin.users.create', compact('clients', 'roles', 'selectedClientId'));
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $user = User::create($request->validated());

        if ($request->role === UserRole::AccountManager->value && $request->assigned_clients) {
            $user->assignedClients()->sync($request->assigned_clients);
        }

        $this->activityLog->log('user.created', $user, clientId: $user->client_id);

        return $this->redirectAfterUserChange($user)
            ->with('success', 'User created successfully.');
    }

    public function edit(Request $request, User $user): View
    {
        $this->authorize('update', $user);

        $clientIds = $request->user()->accessibleClientIds();
        $clients = Client::when(! empty($clientIds), fn ($q) => $q->whereIn('id', $clientIds))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $roles = UserRole::cases();
        $assignedClients = $user->assignedClients()->pluck('clients.id')->toArray();

        return view('admin.users.edit', compact('user', 'clients', 'roles', 'assignedClients'));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $user->update($request->validated());

        if ($request->role === UserRole::AccountManager->value) {
            $user->assignedClients()->sync($request->assigned_clients ?? []);
        } else {
            $user->assignedClients()->detach();
        }

        $this->activityLog->log('user.updated', $user, clientId: $user->client_id);

        return $this->redirectAfterUserChange($user)
            ->with('success', 'User updated successfully.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $clientId = $user->client_id;

        $this->activityLog->log('user.deleted', $user, clientId: $user->client_id);

        $user->delete();

        return $this->redirectAfterUserChange(null, $clientId)
            ->with('success', 'User deleted successfully.');
    }

    private function redirectAfterUserChange(?User $user = null, ?int $clientId = null): RedirectResponse
    {
        $clientId ??= $user?->client_id;

        if ($clientId) {
            return redirect()->route('admin.clients.users.index', $clientId);
        }

        return redirect()->route('admin.users.internal');
    }
}
