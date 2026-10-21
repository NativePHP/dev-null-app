{{--
    The web's follow back on a follow: an icon rather than a full button, since an expanded group of followers repeats it
    on every row, so each is the same size. A muted user-plus, or a user-check in Commit Blue once you follow them. A tap
    flips it.
--}}
@php($followIcon = $isFollowing ? \App\Icons\Lucide::UserCheck : \App\Icons\Lucide::UserPlus)
<native:pressable ref="follow-back-{{ $actor->username }}" @press="toggleFollowBack('{{ $actor->username }}')" class="flex-shrink-0" a11y-label="{{ $isFollowing ? 'Unfollow' : 'Follow' }} {{ $actor->handle() }}">
    <native:stack ref="follow-back-icon-{{ $actor->username }}" :width="32" :height="32" center class="rounded-md">
        <native:icon :ios="$followIcon->ios()" :android="$followIcon->android()" :size="16" class="{{ $isFollowing ? 'text-theme-primary' : 'text-theme-ink-muted' }}" />
    </native:stack>
</native:pressable>
