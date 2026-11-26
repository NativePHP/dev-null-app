{{--
    The Remotes inbox beside the thread you open (see RemotesWorkspace), as the Log lays out its commits. Each page's bar
    follows its component, so it is built from what that component has just rendered, and its buttons call it: the
    thread's call and its menu among them. With Remotes off the inbox keeps one column.
--}}
<native:adaptive-feed native:key="remotes-workspace" :depth="count($conversations)" :intent-revision="$navigationRevision" :split="! $this->remotesOff()" @change="hostChanged" class="w-full h-full">
    <native:column native:key="workspace-remotes" class="w-full h-full">
        <native:remotes key="remotes" :embedded="true" :selected-path="$this->selectedPath()" />
        @php($inbox = $this->primary())
        <native:adaptive-bar :title="$inbox?->navTitle()" :options="$inbox?->navigationOptions()" :callbacks="$this->callbacksOf($inbox)" />
    </native:column>
    <native:adaptive-placeholder native:key="workspace-placeholder" class="w-full h-full">
        <native:column class="w-full h-full justify-center bg-theme-surface">
            @include('native.partials.empty', ['ref' => 'no-conversation', 'command' => 'no remote selected', 'message' => 'Choose a conversation to read its thread here.'])
        </native:column>
    </native:adaptive-placeholder>
    @foreach ($conversations as $conversation)
        @php($thread = $this->threadOf($conversation['id']))
        <native:adaptive-detail native:key="conversation-{{ $conversation['slot'] }}" :identity="'conversation-'.$conversation['slot']" class="w-full h-full">
            <native:conversation :key="'conversation-'.$conversation['slot']" :username="$thread['username']" :org="$thread['org']" :embedded="true" :conversation-data="$conversation['data']" />
            @php($pane = $this->paneAt($conversation['slot']))
            <native:adaptive-bar :title="$pane?->navTitle()" :options="$pane?->navigationOptions()" :callbacks="$this->callbacksOf($pane)" />
        </native:adaptive-detail>
    @endforeach
</native:adaptive-feed>
