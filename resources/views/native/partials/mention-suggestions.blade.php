{{--
    Above an org thread's message field, the members to mention for the "@handle" being typed (see SuggestsMentions),
    as the web lists them: avatar, name, and their handle beside their lane's colour. A bare "@" heads them Members. A
    tap puts "@handle " in the draft in place of what was typed. On iOS they sit on glass of their own, like the reply
    strip.
--}}
@if ($suggestions !== [])
    <native:column ref="mention-suggestions" class="flex-1 py-1 ios:rounded-[18] ios:glass" a11y-label="Mention a member">
        @if ($query === '')
            <native:text ref="mention-heading" class="px-4 pt-1 pb-1 text-xs text-theme-ink-muted">Members</native:text>
        @endif
        @foreach ($suggestions as $user)
            <native:pressable native:key="mention-{{ $user->id }}" ref="mention-{{ $user->username }}" @press="pickMention('{{ $user->username }}')" class="w-full" a11y-label="Mention {{ $user->name }}, {{ $user->handle() }}">
                <native:row class="w-full min-h-[44] px-4 py-1 gap-3 items-center">
                    @include('native.partials.avatar', ['user' => $user, 'size' => 28])
                    <native:column class="flex-1">
                        <native:text font="semibold" class="text-sm text-theme-ink" :max-lines="1">{{ $user->name }}</native:text>
                        <native:row class="gap-[6] items-center">
                            <native:column :width="6" :height="6" class="rounded-full bg-theme-{{ \App\Devnull\Lane::token($user->lane()) }}" />
                            <native:text font="mono" class="text-xs text-theme-ink-muted" :max-lines="1">{{ $user->handle() }}</native:text>
                        </native:row>
                    </native:column>
                </native:row>
            </native:pressable>
        @endforeach
    </native:column>
@endif
