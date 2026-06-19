@props(['label', 'name', 'type' => 'text', 'required' => false, 'value' => '', 'help' => null])

<div class="mb-4">
    @if($type !== 'checkbox')
        <div class="mb-2 flex items-center justify-between gap-2">
            <label for="{{ $name }}" class="portal-label">
                {{ $label }} @if($required)<span class="text-onit">*</span>@endif
            </label>
            @if($help)
                <x-field-help :title="$label" :steps="$help" />
            @endif
        </div>
    @endif
    @if($type === 'textarea')
        <textarea name="{{ $name }}" id="{{ $name }}" rows="4"
            {{ $attributes->merge(['class' => 'admin-input']) }}>{{ old($name, $value) }}</textarea>
    @elseif($type === 'select')
        <select name="{{ $name }}" id="{{ $name }}"
            {{ $attributes->merge(['class' => 'admin-input']) }}>
            {{ $slot }}
        </select>
    @elseif($type === 'checkbox')
        <div class="flex items-start justify-between gap-2">
            <label class="flex flex-1 items-center gap-2">
                <input type="hidden" name="{{ $name }}" value="0">
                <input type="checkbox" name="{{ $name }}" id="{{ $name }}" value="1"
                    {{ old($name, $value) ? 'checked' : '' }}
                    {{ $attributes->merge(['class' => 'border-onit-border bg-onit-surface text-onit focus:ring-onit']) }}>
                <span class="portal-body text-sm">{{ $label }}</span>
            </label>
            @if($help)
                <x-field-help :title="$label" :steps="$help" />
            @endif
        </div>
    @else
        <input type="{{ $type }}" name="{{ $name }}" id="{{ $name }}" value="{{ old($name, $value) }}"
            {{ $attributes->merge(['class' => 'admin-input']) }}>
    @endif
    @error($name)
        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
    @enderror
</div>
