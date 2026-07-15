<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\M365\M365DirectoryService;
use Illuminate\Http\RedirectResponse;
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

        abort_unless($user->canViewMicrosoft365Directory(), 403);

        $client = $user->client;

        abort_unless($client && $this->directory->isAvailableForClient($client), 404);

        return $this->renderDirectory($request, $client, adminContext: false);
    }

    public function refresh(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canViewMicrosoft365Directory(), 403);

        $client = $user->client;

        abort_unless($client && $this->directory->isAvailableForClient($client), 404);

        $queued = $this->directory->queueRefresh($client, respectCooldown: true);

        return redirect()
            ->route('microsoft-365.directory')
            ->with(
                $queued ? 'success' : 'error',
                $queued
                    ? 'Directory refresh queued. This page will update when new data is available.'
                    : 'Please wait before refreshing again.',
            );
    }

    protected function renderDirectory(Request $request, Client $client, bool $adminContext): View
    {
        $error = null;
        $display = null;

        try {
            $display = $this->directory->displaySnapshot($client);

            if ($request->boolean('refresh') && $adminContext) {
                $this->directory->queueRefresh($client, respectCooldown: true);
                $display = $this->directory->displaySnapshot($client);
            }
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
            'display' => $display,
            'directory' => $display?->snapshot,
            'error' => $error,
            'adminContext' => $adminContext,
        ]);
    }
}
