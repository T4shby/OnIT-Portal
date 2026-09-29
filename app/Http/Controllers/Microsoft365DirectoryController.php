<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Services\M365\M365DirectoryDisplayResult;
use App\Services\M365\M365DirectoryExportService;
use App\Services\M365\M365DirectoryService;
use App\Services\M365\M365DirectorySnapshot;
use App\Services\M365\M365InsightsService;
use App\Services\Portal\ClientVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class Microsoft365DirectoryController extends Controller
{
    public function __construct(
        protected M365DirectoryService $directory,
        protected ClientVisibilityService $visibility,
        protected M365InsightsService $insights,
        protected M365DirectoryExportService $export,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->canViewMicrosoft365Directory(), 403);

        $client = $user->client;

        abort_unless($client && $this->directory->isAvailableForClient($client), 404);

        return $this->renderDirectory($request, $client, adminContext: false);
    }

    public function export(Request $request): StreamedResponse|RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canViewMicrosoft365Directory(), 403);

        $client = $user->client;

        abort_unless($client && $this->directory->isAvailableForClient($client), 404);

        $format = strtolower((string) $request->query('format', 'xlsx'));
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);

        // Same rule as the page's canExportDirectory flag. The workbook always contains the
        // organisation's licence inventory and seat/utilisation summary (only user rows
        // were ever scoped), which personal viewers are deliberately not shown on screen.
        abort_unless($this->visibility->canViewOrganisationWide($user, $client), 403);

        return $this->streamExport($client, $format);
    }

    public function live(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->canViewMicrosoft365Directory(), 403);

        $client = $user->client;

        abort_unless($client && $this->directory->isAvailableForClient($client), 404);

        return $this->renderDirectoryLive($request, $client, adminContext: false);
    }

    public function refresh(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canViewMicrosoft365Directory(), 403);

        $client = $user->client;

        abort_unless($client && $this->directory->isAvailableForClient($client), 404);
        abort_unless($this->visibility->canViewOrganisationWide($user, $client), 403);

        $queued = $this->directory->queueRefresh($client, respectCooldown: true);

        return redirect()
            ->route('microsoft-365.directory')
            ->with(
                $queued ? 'success' : 'error',
                $queued
                    ? 'Directory refresh queued. Cached data stays on screen; tables update when the new snapshot is ready.'
                    : 'Please wait before refreshing again.',
            );
    }

    protected function renderDirectory(Request $request, Client $client, bool $adminContext): View
    {
        return view('microsoft-365.directory', $this->directoryViewData($request, $client, $adminContext));
    }

    protected function renderDirectoryLive(Request $request, Client $client, bool $adminContext): View
    {
        return view('microsoft-365._directory-live-root', $this->directoryViewData($request, $client, $adminContext));
    }

    /**
     * @return array<string, mixed>
     */
    protected function directoryViewData(Request $request, Client $client, bool $adminContext): array
    {
        $error = null;
        $display = null;
        $user = $request->user();
        $orgWide = $adminContext || ($user && $this->visibility->canViewOrganisationWide($user, $client));

        try {
            $display = $this->directory->displaySnapshot($client);

            if ($request->boolean('refresh') && $adminContext) {
                $this->directory->queueRefresh($client, respectCooldown: true);
                $display = $this->directory->displaySnapshot($client);
            }

            if ($display?->snapshot && ! $orgWide && $user instanceof User) {
                $display = $this->personalDirectory($display, $user);
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

        return [
            'client' => $client,
            'display' => $display,
            'directory' => $display?->snapshot,
            'error' => $error,
            'adminContext' => $adminContext,
            'organisationWide' => $orgWide,
            'pollSeconds' => 5,
            'm365Insights' => $orgWide ? $this->insights->summaryForClient($client) : null,
            'canExportDirectory' => $orgWide || $adminContext,
        ];
    }

    protected function streamExport(Client $client, string $format): StreamedResponse
    {
        $workbook = $this->export->buildWorkbook($client);
        $filename = $this->export->filename($client, $format);

        if ($format === 'csv') {
            $csv = $this->export->toCsv($workbook);

            return response()->streamDownload(static function () use ($csv): void {
                echo $csv;
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $xlsx = $this->export->toXlsx($workbook);

        return response()->streamDownload(static function () use ($xlsx): void {
            echo $xlsx;
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function personalDirectory(M365DirectoryDisplayResult $display, User $user): M365DirectoryDisplayResult
    {
        $snapshot = $display->snapshot;
        if ($snapshot === null) {
            return $display;
        }

        // Exact identity only (email, or Entra object id). The old fuzzy match on
        // email local part / display name substrings showed "mark@" the rows for
        // marketing@ and every "Mark …" - other people's licences and account state.
        $objectId = strtolower((string) $user->entra_object_id);
        $people = $snapshot->people->filter(function (array $person) use ($user, $objectId): bool {
            return $this->visibility->matchesEmail($person['email'] ?? null, $user)
                || ($objectId !== '' && strtolower((string) ($person['id'] ?? '')) === $objectId);
        })->values();

        $scoped = new M365DirectorySnapshot(
            people: $people,
            groups: collect(),
            refreshedAt: $snapshot->refreshedAt,
        );

        return new M365DirectoryDisplayResult(
            snapshot: $scoped,
            isStale: $display->isStale,
            refreshQueued: $display->refreshQueued,
            refreshInProgress: $display->refreshInProgress,
            lastRefreshedAt: $display->lastRefreshedAt,
            statusMessage: $display->statusMessage,
        );
    }
}
