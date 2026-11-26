{{--
    Each page's bar follows its component, so it is built from what that component has just rendered, and its buttons
    call that component (see Workspace::callbacksOf()). The Log's wears the wordmark, as TabsLayout gives it elsewhere.
--}}
<native:adaptive-feed native:key="feed-workspace" :depth="count($conversations)" :intent-revision="$navigationRevision" @change="hostChanged" class="w-full h-full">
    <native:column native:key="workspace-feed" class="w-full h-full">
        <native:log key="feed" :embedded="true" :selected-commit="$this->selectedCommit()" />
        @php($feed = $this->primary())
        <native:adaptive-bar :title="$feed?->navTitle()" :title-image="\App\NativeComponents\Layouts\TabsLayout::LightLogo" :title-image-dark="\App\NativeComponents\Layouts\TabsLayout::DarkLogo" long-press="playNataNullers" :options="$feed?->navigationOptions()" :callbacks="$this->callbacksOf($feed)" />
    </native:column>
    <native:adaptive-placeholder native:key="workspace-placeholder" class="w-full h-full">
        <native:column class="w-full h-full justify-center bg-theme-surface">
            @include('native.partials.empty', ['ref' => 'no-conversation', 'command' => 'no commit selected', 'message' => 'Choose a commit in the log to read its conversation here.'])
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
