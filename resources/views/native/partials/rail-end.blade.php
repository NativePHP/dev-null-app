{{-- The end of history: a hollow root node on its own, with no tail, and a dry line beside it, as on the web. --}}
<native:row :ref="$ref ?? 'root-commit'" class="w-full bg-theme-surface">
    <native:column :width="64" />
    <native:column class="flex-1 pt-[21] pb-10 pr-4">
        <native:text class="text-sm text-theme-ink-muted">{{ $message }}</native:text>
    </native:column>

    @include('native.partials.rail', ['lane' => $lane ?? 0, 'node' => 'root'])
</native:row>
