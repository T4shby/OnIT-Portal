<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold text-slate-900">{{ $title }}</h1>
    @if(isset($action))
        <div>{!! $action !!}</div>
    @endif
</div>
