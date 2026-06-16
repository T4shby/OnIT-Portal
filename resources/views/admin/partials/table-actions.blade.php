<div class="flex items-center gap-2">
    <a href="{{ $editRoute }}" class="text-sm text-onit hover:text-onit-hover font-medium">Edit</a>
    <form method="POST" action="{{ $deleteRoute }}" class="inline" onsubmit="return confirm('Are you sure?')">
        @csrf
        @method('DELETE')
        <button type="submit" class="text-sm font-medium text-red-400 hover:text-red-300">Delete</button>
    </form>
</div>
