{{--
    By a field that suggests mentions ($field), the people to mention for the "@handle" being typed, then the
    organizations (see SuggestsMentions), as the web lists them: avatar, name, and a person's handle beside their lane's
    colour. $heading, when there is one, heads them: Members for a bare "@" in an org thread. A tap puts "@handle " in
    the field in place of what was typed. Above a bar's field on iOS they sit on glass of their own, like the reply
    strip; $class says otherwise.
--}}
@if (($mention['field'] ?? null) === $field && ($mentionPeople !== [] || $mentionOrganizations !== []))
    <native:column ref="mention-suggestions" class="{{ $class ?? 'flex-1 py-1 ios:rounded-[18] ios:glass' }}" a11y-label="{{ $label ?? 'Mention a member' }}">
        @if ($heading ?? null)
            <native:text ref="mention-heading" class="px-4 pt-1 pb-1 text-xs text-theme-ink-muted">{{ $heading }}</native:text>
        @endif
        @foreach ([...array_map(fn ($user) => [$user, true], $mentionPeople), ...array_map(fn ($user) => [$user, false], $mentionOrganizations)] as [$user, $person])
            <native:pressable native:key="mention-{{ $user->id }}" ref="mention-{{ $user->username }}" @press="pickMention('{{ $field }}', '{{ $user->username }}')" class="w-full" a11y-label="Mention {{ $user->name }}, {{ $user->handle() }}">
                <native:row class="w-full min-h-[44] px-4 py-1 gap-3 items-center">
                    @include('native.partials.avatar', ['user' => $user, 'size' => 28])
                    <native:column class="flex-1">
                        <native:text font="semibold" class="text-sm text-theme-ink" :max-lines="1">{{ $user->name }}</native:text>
                        <native:row class="gap-[6] items-center">
                            @if ($person)
                                <native:column :width="6" :height="6" class="rounded-full bg-theme-{{ \App\Devnull\Lane::token($user->lane()) }}" />
                            @endif
                            <native:text font="mono" class="text-xs text-theme-ink-muted" :max-lines="1">{{ $user->handle() }}</native:text>
                        </native:row>
                    </native:column>
                </native:row>
            </native:pressable>
        @endforeach
    </native:column>
@endif
