{{--
    Notifications beside the commit one opens (see NotificationsWorkspace), as the Log lays out its commits. Each page's
    bar follows its component, so it is built from what that component has just rendered, and its buttons call it.
--}}
<native:adaptive-feed native:key="notifications-workspace" :depth="count($conversations)" :intent-revision="$navigationRevision" @change="hostChanged" class="w-full h-full">
    <native:column native:key="workspace-notifications" class="w-full h-full">
        <native:notifications key="notifications" :embedded="true" :selected-commit="$this->selectedCommit()" />
        @php($list = $this->primary())
        <native:adaptive-bar :title="$list?->navTitle()" :options="$list?->navigationOptions()" :callbacks="$this->callbacksOf($list)" />
    </native:column>
    <native:adaptive-placeholder native:key="workspace-placeholder" class="w-full h-full">
        <native:column class="w-full h-full justify-center bg-theme-surface">
            @include('native.partials.empty', ['ref' => 'no-conversation', 'command' => 'no commit selected', 'message' => 'Choose a notification to read its commit here.'])
        </native:column>
    </native:adaptive-placeholder>
    @foreach ($conversations as $conversation)
        <native:adaptive-detail native:key="conversation-{{ $conversation['slot'] }}" :identity="'conversation-'.$conversation['slot']" class="w-full h-full">
            <native:commit-detail :key="'conversation-'.$conversation['slot']" :id="$conversation['id']" :embedded="true" :conversation-data="$conversation['data']" />
            @php($pane = $this->paneAt($conversation['slot']))
            <native:adaptive-bar :title="$pane?->navTitle()" :options="$pane?->navigationOptions()" :callbacks="$this->callbacksOf($pane)" />
        </native:adaptive-detail>
    @endforeach
</native:adaptive-feed>
