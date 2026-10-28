{{--
    Someone in an org, as the web's members dialog lists them: their avatar ringed in their lane, their name, "(you)"
    on your own row, their handle, and their role. A tap opens their profile. For a maintainer, a slot on the right
    holds More, which opens the platform's own menu of what they can do to them (see OrgMemberMenu), or a spinner
    while a change to them is on its way, and stays empty on your own row so the roles line up.
--}}
<native:row native:key="member-{{ $user->id }}" ref="member-{{ $user->username }}" class="w-full pl-4 pr-2 py-3 gap-2 items-center">
    <native:pressable @press="viewProfile('{{ $user->username }}')" class="flex-1" a11y-label="{{ $user->name }}{{ $isMe ? ', you' : '' }}, {{ $user->handle() }}, {{ $role }}">
        <native:row class="w-full gap-3 items-center">
            <native:stack :width="40" :height="40" center class="rounded-full bg-theme-{{ \App\Devnull\Lane::token($user->lane()) }} flex-shrink-0">
                <native:stack :width="36" :height="36" center class="rounded-full bg-theme-surface">
                    @include('native.partials.avatar', ['user' => $user, 'size' => 32])
                </native:stack>
            </native:stack>
            <native:column class="flex-1 gap-[2]">
                <native:row class="w-full gap-1 items-center">
                    <native:text font="semibold" class="text-[15px] text-theme-ink flex-shrink" :max-lines="1">{{ $user->name }}</native:text>
                    @if ($isMe)
                        <native:text class="text-[15px] text-theme-ink-muted flex-shrink-0">(you)</native:text>
                    @endif
                </native:row>
                <native:text font="mono" class="text-[13px] text-theme-ink-muted" :max-lines="1">{{ $user->handle() }}</native:text>
            </native:column>
            <native:column ref="role-{{ $user->username }}" class="px-2 py-[2] rounded flex-shrink-0 {{ $role === \App\NativeComponents\OrgMembers::Maintainer ? 'bg-theme-primary-soft' : 'bg-theme-wash' }}">
                <native:text font="mono" class="text-[11px] {{ $role === \App\NativeComponents\OrgMembers::Maintainer ? 'text-theme-primary' : 'text-theme-ink-muted' }}">{{ $role }}</native:text>
            </native:column>
        </native:row>
    </native:pressable>
    @if ($showsSlot)
        <native:stack :width="36" :height="36" center class="flex-shrink-0">
            @if ($isChanging)
                <native:activity-indicator size="small" />
            @elseif ($menu !== [])
                <native:pressable ref="manage-{{ $user->username }}" :menu="$menu" a11y-label="Manage {{ $user->handle() }}">
                    <native:stack :width="36" :height="36" center class="rounded-full">
                        <native:icon :ios="\App\Icons\Lucide::Ellipsis->ios()" :android="\App\Icons\Lucide::Ellipsis->android()" :size="18" class="text-theme-ink-muted" />
                    </native:stack>
                </native:pressable>
            @endif
        </native:stack>
    @endif
</native:row>
