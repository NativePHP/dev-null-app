{{--
    One tab's page in the organization's pager (see App\NativeComponents\Organization::page()): the tab you're on, or
    the first few commits kept for the other, ready for a swipe. As on a profile, each tab has its own list, a new
    $page['listKey'] puts it back at the top, and only the tab you're on asks for its next page.
--}}
<native:column native:key="page-{{ $page['tab'] }}" class="w-full h-full bg-theme-surface">
    <native:list
        native:key="organization-{{ $page['tab'] }}-{{ $page['listKey'] }}"
        :ref="$page['showing'] ? 'organization' : 'organization-'.$page['tab']"
        class="w-full flex-1 bg-theme-surface"
        on-refresh="load"
        :on-end-reached="$page['showing'] ? 'loadMore' : null"
        :end-reached-buffer="5"
    >
        @if ($page['failed'])
            @include('native.partials.empty', [
                'command' => $page['tab'] === 'mentions' ? '$ git log --grep='.$user->handle() : '$ git log --author='.$user->handle(),
                'message' => $page['tab'] === 'mentions' ? "Its mentions didn't load here." : "Its commits didn't load here.",
                'link' => 'Open it on dev/null',
                'press' => 'openOnWeb',
            ])
        @elseif (! $page['loaded'])
            @foreach (range(1, 3) as $skeleton)
                @include('native.partials.rail-loading', ['ref' => 'loading-'.$skeleton, 'label' => 'Loading commits', 'pulse' => $page['showing']])
            @endforeach
        @elseif ($page['isEmpty'])
            @if ($page['tab'] === 'mentions')
                @include('native.partials.empty', ['command' => '$ git log --grep='.$user->handle(), 'message' => "No one has mentioned {$user->handle()} yet."])
            @else
                @include('native.partials.empty', ['command' => 'fatal: no commits yet', 'message' => "{$user->handle()} hasn't committed anything yet."])
            @endif
        @endif

        @if (! $page['failed'])
            @include('native.partials.feed', ['rows' => $page['rows'], 'threads' => $page['threads'], 'rowCache' => $page['rowCache']])

            @if ($page['loaded'] && ! $page['isEmpty'])
                @if ($page['nextCursor'] !== null)
                    @include('native.partials.rail-loading', ['pulse' => $page['showing']])
                @elseif ($page['tab'] === 'mentions')
                    @include('native.partials.rail-end', ['lane' => $user->lane(), 'message' => "That's the first mention of {$user->handle()}."])
                @else
                    @include('native.partials.rail-end', ['lane' => $user->lane(), 'message' => "That's {$user->handle()}'s root commit."])
                @endif
            @endif
        @endif
    </native:list>
</native:column>
