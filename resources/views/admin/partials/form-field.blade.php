@props(['label', 'name', 'type' => 'text', 'required' => false, 'value' => ''])

<div class="mb-4">
    @if($type !== 'checkbox')
        <label for="{{ $name }}" class="block text-sm font-medium text-slate-700 mb-1">
            {{ $label }} @if($required)<span class="text-red-500">*</span>@endif
        </label>
    @endif
    @if($type === 'textarea')
        <textarea name="{{ $name }}" id="{{ $name }}" rows="4"
            {{ $attributes->merge(['class' => 'w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit']) }}>{{ old($name, $value) }}</textarea>
    @elseif($type === 'select')
        <select name="{{ $name }}" id="{{ $name }}"
            {{ $attributes->merge(['class' => 'w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit']) }}>
            {{ $slot }}
        </select>
    @elseif($type === 'checkbox')
        <label class="flex items-center gap-2">
            <input type="hidden" name="{{ $name }}" value="0">
            <input type="checkbox" name="{{ $name }}" id="{{ $name }}" value="1"
                {{ old($name, $value) ? 'checked' : '' }}
                {{ $attributes->merge(['class' => 'rounded border-slate-300 text-onit focus:ring-onit']) }}>
            <span class="text-sm text-slate-600">{{ $label }}</span>
        </label>
    @else
        <input type="{{ $type }}" name="{{ $name }}" id="{{ $name }}" value="{{ old($name, $value) }}"
            {{ $attributes->merge(['class' => 'w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit']) }}>
    @endif
    @error($name)
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
