@props(['title' => 'No items', 'description' => ''])

<div class="py-10 text-left">
    <h3 class="portal-card-title">{{ $title }}</h3>
    @if($description)
        <p class="portal-body-muted mt-2">{{ $description }}</p>
    @endif
    @if(isset($action))
        <div class="mt-6">{{ $action }}</div>
    @endif
</div>
