<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Microsoft365DirectoryController;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientMicrosoft365DirectoryController extends Microsoft365DirectoryController
{
    public function show(Request $request, Client $client): View
    {
        $this->authorize('view', $client);

        abort_unless($this->directory->isAvailableForClient($client), 404);

        return $this->renderDirectory($request, $client, adminContext: true);
    }

    public function liveForClient(Request $request, Client $client): View
    {
        $this->authorize('view', $client);

        abort_unless($this->directory->isAvailableForClient($client), 404);

        return $this->renderDirectoryLive($request, $client, adminContext: true);
    }

    public function exportForClient(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        abort_unless($this->directory->isAvailableForClient($client), 404);

        $format = strtolower((string) $request->query('format', 'xlsx'));
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);

        return $this->streamExport($client, $format, null);
    }
}
