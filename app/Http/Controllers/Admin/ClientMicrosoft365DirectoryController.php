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
}
