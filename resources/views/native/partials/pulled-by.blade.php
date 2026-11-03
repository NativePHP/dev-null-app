{{--
    Who in an org pulled a message, as the web draws them after "pulled by": up to five 14pt avatars, each ringed in
    the thread's surface and tucked 4pt under the next, then "+N" for the rest. A screen reader hears what's drawn:
    the five handles and "and N others", where the web's label reads out every one of up to a hundred.
    As in stacked-avatars, each avatar sits at a fixed offset; later siblings draw on top, so the last one is on top,
    as on the web.
--}}
@php($shown = array_slice($people, 0, 5))
@php($handles = implode(', ', array_map(fn (\App\Devnull\Data\User $person): string => $person->handle(), $shown)).(count($people) > 5 ? ' and '.\App\Devnull\Display::count(count($people) - 5, 'other', 'others') : ''))
<native:row ref="pulled-by-{{ $key }}" class="gap-[3] items-center" :a11y-label="$handles">
    <native:column ref="pulled-by-avatars-{{ $key }}" :width="(count($shown) - 1) * 10 + 16" :height="16">
        @foreach ($shown as $index => $person)
            <native:column class="absolute top-0 left-[{{ $index * 10 }}]">
                <native:stack :width="16" :height="16" center class="rounded-full bg-theme-surface">
                    @include('native.partials.avatar', ['user' => $person, 'size' => 14])
                </native:stack>
            </native:column>
        @endforeach
    </native:column>
    @if (count($people) > 5)
        <native:text font="mono" class="text-[11px] text-theme-ink-muted">+{{ count($people) - 5 }}</native:text>
    @endif
</native:row>
