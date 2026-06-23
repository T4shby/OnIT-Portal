<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Client;
use App\Services\M365\M365DirectoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class Microsoft365DirectoryController extends Controller
{
    public function __construct(protected M365DirectoryService $directory) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->role === UserRole::ClientAdmin, 403);

        $client = $user->client;

        abort_unless($client && $this->directory->isAvailableForClient($client), 404);

        return $this->renderDirectory($request, $client, adminContext: false);
    }

    protected function renderDirectory(Request $request, Client $client, bool $adminContext): View
    {
        $error = null;
        $directory = null;

        try {
            $directory = $this->directory->snapshot($client, $request->boolean('refresh'));
        } catch (Throwable $e) {
            Log::error('M365 directory load failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
            $error = config('app.debug')
                ? $e->getMessage()
                : 'Unable to load Microsoft 365 directory. Ensure admin consent is granted for this tenant.';
        }

        return view('microsoft-365.directory', [
            'client' => $client,
            'directory' => $directory,
            'error' => $error,
            'adminContext' => $adminContext,
        ]);
    }
}
