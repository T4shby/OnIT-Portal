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
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $clientIds = $request->user()->accessibleClientIds();

        $users = User::query()
            ->with('client')
            ->when(! empty($clientIds), function ($q) use ($clientIds, $request) {
                $q->where(function ($q) use ($clientIds, $request) {
                    $q->whereIn('client_id', $clientIds);
                    if ($request->user()->role === UserRole::AccountManager) {
                        $q->orWhereIn('id', function ($sub) use ($clientIds) {
                            $sub->select('user_id')
                                ->from('client_user')
                                ->whereIn('client_id', $clientIds);
                        });
                    }
                });
            })
            ->latest()
            ->paginate(15);

        return view('admin.users.index', compact('users'));
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

        return view('admin.users.create', compact('clients', 'roles'));
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $user = User::create($request->validated());

        if ($request->role === UserRole::AccountManager->value && $request->assigned_clients) {
            $user->assignedClients()->sync($request->assigned_clients);
        }

        $this->activityLog->log('user.created', $user, clientId: $user->client_id);

        return redirect()->route('admin.users.index')
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

        return redirect()->route('admin.users.index')
            ->with('success', 'User updated successfully.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $this->activityLog->log('user.deleted', $user, clientId: $user->client_id);

        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', 'User deleted successfully.');
    }
}
