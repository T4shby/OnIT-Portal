@props([
    'adminContext' => false,
    'client' => null,
])

@php
    $xlsxUrl = $adminContext && $client
        ? route('admin.clients.microsoft-365.export', ['client' => $client, 'format' => 'xlsx'])
        : route('microsoft-365.directory.export', ['format' => 'xlsx']);
    $csvUrl = $adminContext && $client
        ? route('admin.clients.microsoft-365.export', ['client' => $client, 'format' => 'csv'])
        : route('microsoft-365.directory.export', ['format' => 'csv']);
@endphp

<div class="m365-export" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
    <a href="{{ $xlsxUrl }}" class="m365-export-btn m365-export-btn-primary">
        Download Excel
    </a>
    <a href="{{ $csvUrl }}" class="m365-export-btn">
        Download CSV
    </a>
    <span class="org-muted" style="font-size:11px;max-width:16rem;line-height:1.35">
        Licences first, then users with licences attached.
    </span>
</div>

<style>
    .m365-export-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 8px 14px;
        border-radius: 4px;
        border: 1px solid #1F2933;
        background: #0a2537;
        color: rgba(255,255,255,.88);
        font-size: 12px;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
    }
    .m365-export-btn:hover { border-color: #FF7000; color: #fff; }
    .m365-export-btn-primary {
        background: #FF7000;
        border-color: #FF7000;
        color: #0a0f14;
    }
    .m365-export-btn-primary:hover { background: #ff8a33; border-color: #ff8a33; color: #0a0f14; }
</style>
