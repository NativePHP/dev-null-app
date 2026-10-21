{{--
    The people in a notification group, stacked as the web stacks them: up to three 32pt avatars, each ringed in the
    surface colour and tucked 8pt under the one before, the newest on top, then a mono "+N" disc for the rest ($more).
    EDGE has no z-index and draws later siblings on top, so the avatars sit at fixed offsets and are drawn oldest first,
    with the disc drawn first of all, since it sits under the oldest.
--}}
@php($step = 24)
@php($more ??= 0)
<native:column ref="actors-{{ $id }}" :width="(count($actors) - 1 + ($more > 0 ? 1 : 0)) * $step + 36" :height="36">
    @if ($more > 0)
        <native:column ref="actors-more-{{ $id }}" class="absolute top-0 left-[{{ count($actors) * $step }}]">
            <native:stack :width="36" :height="36" center class="rounded-full bg-theme-surface">
                <native:stack :width="32" :height="32" center class="rounded-full bg-theme-wash">
                    <native:text font="mono" class="text-xs text-theme-ink-muted">+{{ $more }}</native:text>
                </native:stack>
            </native:stack>
        </native:column>
    @endif
    @foreach (array_reverse($actors, true) as $index => $actor)
        <native:column class="absolute top-0 left-[{{ $index * $step }}]">
            <native:pressable ref="actor-avatar-{{ $id }}-{{ $actor->username }}" @press="openActor('{{ $actor->username }}')" a11y-label="{{ $actor->handle() }}'s profile">
                <native:stack :width="36" :height="36" center class="rounded-full bg-theme-surface">
                    @include('native.partials.avatar', ['user' => $actor, 'size' => 32])
                </native:stack>
            </native:pressable>
        </native:column>
    @endforeach
</native:column>
