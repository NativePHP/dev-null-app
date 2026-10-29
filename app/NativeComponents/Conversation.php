<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\Conversation as ConversationData;
use App\Devnull\Data\DraftImage;
use App\Devnull\Data\Message;
use App\Devnull\Data\MessageVoice;
use App\Devnull\Data\Thread;
use App\Devnull\Data\User;
use App\Devnull\Display;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\MessageMenu;
use App\Devnull\MuteDuration;
use App\Devnull\Outbox;
use App\Devnull\ReactionEmoji;
use App\Devnull\ResponseCache;
use App\Devnull\SiteLink;
use App\Devnull\Toasts;
use App\Devnull\VoiceNotes;
use App\Icons\Lucide;
use App\Models\PendingWrite;
use App\NativeComponents\Concerns\AttachesPhotos;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\PlaysVoiceNotes;
use App\NativeComponents\Concerns\RecordsVoiceNotes;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\SuggestsMentions;
use App\NativeComponents\Concerns\SyncsOutbox;
use App\NativeComponents\Concerns\TalksToDevnull;
use App\NativeComponents\Concerns\WritesInBackground;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\SharedValue;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Device;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\System;
use NativePHP\Clipboard\Facades\Clipboard;

/**
 * A Remotes thread, with a person or an org, as the web's thread shows it:
 * the messages oldest first, yours on the right in your lane, with a day
 * marker wherever the day changes and HEAD where you left off, then the
 * composer at the bottom.
 *
 * It opens on the thread as it was last time and asks devnull behind it, then
 * polls for what came after the last message, as the web does: every two
 * seconds while anyone has written or typed in the last minute, every four
 * otherwise. The scroll view stays on the latest message while you're there,
 * and leaves you be while you read back. What's new is pulled (marked read)
 * as it arrives, since the thread is on screen.
 *
 * A message you push shows staged at once, then pushed once devnull has it,
 * or rejected with a retry, which sends it again under the same client id so
 * it's never stored twice. It can have up to three photos, as on the web,
 * with or without text (see AttachesPhotos), and they show with it while it
 * goes. It goes through the outbox (see SyncsOutbox), so
 * it's still staged or rejected when you come back to the thread, and one
 * still on its way when the app closed goes again when it next opens. A
 * toast says why devnull didn't take one, wherever you are by then, with a
 * Retry of its own. Dragging a message sideways slides it out of the way
 * with the finger, its React and Reply growing in where it was, and Pin
 * for an org's maintainers; let go far enough and it stays open, or it
 * springs back. A double tap toggles a heart. React shows the web's quick
 * reactions in a bubble beside it, with More for the phone's own emoji
 * picker.
 *
 * An org's pinned messages show as the web shows them: the latest in a bar
 * over the top of the thread, which opens them all in a sheet, and a pin
 * beside each one in the thread. Pinning and unpinning show at once and go
 * back if devnull says no, and pins from the others come with the polls.
 *
 * The composer's microphone records a voice note of up to a minute, which
 * goes on its own, through the outbox like any message (see
 * RecordsVoiceNotes), and voice notes play in the thread as the web's do,
 * one at a time, at the speed you pick (see PlaysVoiceNotes).
 *
 * The composer's Ping pings them, or everyone in an org, as MSN Messenger
 * had: it lands in the thread as a log line. A ping coming in or going out
 * while you're on the thread buzzes the phone, as the web shakes the thread
 * to MSN's sound, unless it's someone else's in a thread you've muted.
 *
 * In an org, typing "@" lists the org's members above the field, as the web
 * does, and typing on filters them (see SuggestsMentions).
 *
 * When the composer can't push (a request to merge, your own request waiting,
 * someone who doesn't follow you, or has Remotes off), the bar says so in the
 * web's words, and a request offers Merge and Drop.
 */
class Conversation extends NativeComponent
{
    use AttachesPhotos;
    use OpensInTab;
    use PlaysVoiceNotes;
    use RecordsVoiceNotes;
    use RefreshesInBackground;
    use SuggestsMentions;
    use SyncsOutbox;
    use TalksToDevnull;
    use WritesInBackground;

    /**
     * The web's poll: every 1.5 seconds within a minute of someone writing or typing, every 4 seconds otherwise. A
     * poll here can only fire every two seconds, so an idle thread skips every other one.
     */
    public const int ActiveWindow = 60;

    public const float IdlePoll = 3.9;

    /**
     * How often the composer tells the others you're typing, at most, as the web's does.
     */
    public const int TypingPing = 3;

    /**
     * The reactions React offers first, before any you use more, as the web's quick bar.
     */
    public const array QuickReactions = ['😂', '✅', '❤️'];

    /**
     * The slot beside a bubble for its actions: React and Reply, and in an org room for Pin as well, whether it shows or
     * not, as the web keeps it, so a pin arriving never rewraps a bubble. Then the gap between the slot and the bubble.
     */
    public const int ActionsWidth = 64;

    public const int OrgActionsWidth = 98;

    public const int ActionsGap = 4;

    /**
     * A drag slides a message out of the way by up to its actions' slot, which then takes the room the bubble left. A
     * short bubble slides less, so all but the last 20 points of it past the thread's edge stay on screen, guessing its
     * width from its longest line: its padding, then an average character of 15-point text.
     */
    public const int BubblePadding = 24;

    public const float BubbleCharacterWidth = 7.5;

    public const int KeptOnScreen = 20;

    public const int LeastSlide = 16;

    /**
     * How long after a swipe brings a message's actions up a tap is taken as part of the swipe (see $actionsShownAt).
     */
    public const float SwipeTapWindow = 0.75;

    /**
     * The alert that asks before pinning, as the web's does, and its answers.
     */
    public const string PinAlert = 'pin-warning';

    public const string PinMessage = 'Pin message';

    public const string PinWithoutAsking = "Pin, and don't ask again";

    /**
     * About how many characters of a pinned message fit on a line of the pinned sheet, to tell whether it runs past the
     * three lines the sheet shows of it, as the web's does.
     */
    public const int PinnedCharactersPerLine = 42;

    public const int PinnedLines = 3;

    /**
     * Every frame goes in full, as on the Log (see Log::$forceFullFrames): messages come in at the bottom and their
     * reactions change in place, which EDGE's subtree reuse would otherwise miss.
     */
    protected bool $forceFullFrames = true;

    /**
     * Who the thread is with, from the route: a username, or an org's id.
     */
    public string $username = '';

    public string $org = '';

    /**
     * Everything about the thread but its messages, as devnull last sent it.
     */
    public ?Thread $thread = null;

    /** @var list<Message> */
    public array $messages = [];

    /**
     * Messages you pushed that devnull hasn't taken yet, staged or rejected, after the rest, as the outbox has them.
     *
     * @var list<Message>
     */
    public array $pending = [];

    public bool $hasOlder = false;

    public int $reactionsVersion = 0;

    /**
     * Where you'd pulled to, and the latest message, when the thread opened, for the HEAD marker.
     */
    public ?int $head = null;

    public int $headUntil = 0;

    public int $readThroughId = 0;

    /**
     * The latest message a load or a poll brought, which the next poll asks for what came after. One you pushed
     * doesn't count: someone else's can land just before it, and a poll after yours would never bring it.
     */
    public int $polledThroughId = 0;

    public bool $loaded = false;

    public string $draft = '';

    /**
     * The photos on the draft, which go with it when you push.
     *
     * @var list<DraftImage>
     */
    public array $photos = [];

    public ?int $replyToId = null;

    /**
     * Mounts the composer's field afresh when it should take focus and raise the keyboard, as the web focuses its
     * field: replying while the field is idle, or attaching photos.
     */
    public int $composerKey = 0;

    /**
     * Whether the field is in use, as far as the thread can tell. EDGE sends no focus event, so pressing into the
     * field or typing in it says so. The field keeps the keyboard through presses elsewhere (keep-focus), so a reply
     * then leaves it be instead of mounting it afresh, which would drop the keyboard and raise it again. Leaving the
     * thread lets go of it.
     */
    public bool $composing = false;

    /**
     * The composer bar's height with one line in its field, the same as the reply bar on a commit's page: its top
     * line, then the field, its tools and Push with 8 points above and below.
     */
    public const int ComposerBarHeight = Display::ReplyBarHeight;

    /**
     * The "Replying to …" bar above the field.
     */
    public const int ReplyingBarHeight = 44;

    /**
     * The photos on the draft above the field, and the description of one under them while it's open.
     */
    public const int PhotosHeight = 88;

    public const int DescriptionHeight = 100;

    /**
     * The latest ping the thread has shown, so only one after it buzzes. Null until the thread first loads from
     * devnull, as pings already there when it opened stay still.
     */
    public ?int $seenPingId = null;

    /**
     * The message whose React, Reply and Pin show beside it, as the web shows them on hover. Dragging a message aside
     * shows them, and dragging it back, a tap anywhere in the thread, or opening another message's puts them away.
     */
    public ?int $actionsForId = null;

    /**
     * When a drag last brought a message's actions up. On Android a drag that ends inside the bubble or its row could
     * also land as a tap on them, a moment later once the double tap has had its chance, which would put the actions
     * straight away again; a tap that close behind the drag is the drag's own.
     */
    public float $actionsShownAt = 0;

    /**
     * Where each message sits sideways, shared with the gesture area that drags it (see panFor()). They're kept across
     * renders, so the area moves the same value the row and its buttons follow, and a new resting place from here
     * springs into place rather than jumping.
     *
     * @var array<int, SharedValue>
     */
    private array $pans = [];

    /**
     * Whether the sheet of the org's pinned messages is up.
     */
    public bool $sheetOpen = false;

    /**
     * The message whose React shows the web's quick reactions in a bubble beside it.
     */
    public ?int $reactionsFor = null;

    /**
     * React was pressed in the pinned sheet, so the bubble shows there, and the sheet comes back after the emoji picker
     * has made way for it.
     */
    public bool $reactsFromPinned = false;

    /**
     * The message the phone's emoji picker is up for, if it is.
     */
    public ?int $emojiPickerFor = null;

    /**
     * The org's pinned messages, latest pinned first, which everyone in it sees whatever history they were given.
     *
     * @var list<Message>
     */
    public array $pinned = [];

    /**
     * The reactions version the pinned messages are current with. Pinning and unpinning move it, so a list devnull sent
     * with an older one is from before a change the thread already shows.
     */
    public ?int $pinnedVersion = null;

    /**
     * Pins and unpins devnull hasn't answered yet. Until it has answered them all, the pinned messages showing are
     * newer than any a poll brings.
     */
    public int $pinning = 0;

    /**
     * The message the pin warning is asking about.
     */
    public ?int $pinAskedId = null;

    /**
     * The pinned messages opened past their first three lines with Show more.
     *
     * @var list<int>
     */
    public array $expandedPins = [];

    /**
     * Mute's sheet is open, asking how long for.
     */
    public bool $choosingMute = false;

    /**
     * The messages whose reaction you changed and devnull hasn't answered for yet, which a poll leaves alone.
     *
     * @var array<int, true>
     */
    public array $reacting = [];

    /**
     * The emoji you react with, most used first, which the sheet's quick reactions follow.
     *
     * @var list<array{emoji: string, count: int}>
     */
    public array $usage = [];

    public float $lastActivityAt = 0;

    public float $lastPolledAt = 0;

    public float $lastTypingAt = 0;

    /**
     * Why Merge or Drop didn't go through, shown in the request's bar.
     */
    public ?string $requestError = null;

    /**
     * What the inbox's row knew, to title the thread until devnull answers.
     */
    public ?ConversationData $row = null;

    public function mount(): void
    {
        $row = $this->data('conversation');
        $this->row = $row instanceof ConversationData ? $row : null;
        $this->showKept();
        $this->showPending();
        $this->revalidate();
        $this->refresh('remotes/reactions', [], fn (array $json) => $this->usage = self::usageFrom($json), quiet: true);
    }

    public function onResume(): void
    {
        // You left the org from its members: back to the inbox, which no longer lists it.
        if ($this->isOrg() && app(ResponseCache::class)->takeLeftOrg($this->org)) {
            $this->back();

            return;
        }

        $this->composing = false;
        $this->forgetRefreshes();
        $this->showKept();
        $this->showPending();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return $this->title() ?: 'Remotes';
    }

    public function navigationOptions(): ?NavBarOptions
    {
        $options = NavBarOptions::make()->subtitle($this->subtitle());

        if ($this->thread === null) {
            return $options;
        }

        $items = [];

        $items[] = $this->isOrg()
            ? NavAction::make('members')->label('Members')->icon(ios: Lucide::UsersRound->ios(), android: Lucide::UsersRound->android())->press('viewMembers')
            : NavAction::make('view-profile')->label('View profile')->icon(ios: Lucide::UserRound->ios(), android: Lucide::UserRound->android())->press('viewProfile');

        $items[] = $this->thread->pinnedAt !== null
            ? NavAction::make('unpin')->label('Unpin')->icon(ios: Lucide::PinOff->ios(), android: Lucide::PinOff->android())->press('unpin')
            : NavAction::make('pin')->label('Pin')->icon(ios: Lucide::Pin->ios(), android: Lucide::Pin->android())->press('pin');

        $items[] = $this->thread->isArchived
            ? NavAction::make('pop')->label('Pop')->icon(ios: Lucide::ArchiveRestore->ios(), android: Lucide::ArchiveRestore->android())->press('pop')
            : NavAction::make('stash')->label('Stash')->icon(ios: Lucide::Archive->ios(), android: Lucide::Archive->android())->press('stash');

        $items[] = $this->thread->isMuted()
            ? NavAction::make('unmute')->label('Unmute')->icon(ios: Lucide::Bell->ios(), android: Lucide::Bell->android())->press('unmute')
            : NavAction::make('mute')->label('Mute')->icon(ios: Lucide::BellOff->ios(), android: Lucide::BellOff->android())->press('chooseMute');

        return $options->action(
            NavAction::make('conversation-actions')
                ->icon(ios: Lucide::Ellipsis->ios(), android: Lucide::Ellipsis->android())
                ->a11yLabel('Conversation actions')
                ->items($items),
        );
    }

    /**
     * The web's poll, for what came after the last message and the reactions changed since.
     */
    #[Poll(2000)]
    public function poll(): void
    {
        $now = self::now();
        $active = $now - $this->lastActivityAt < self::ActiveWindow;

        if (! $this->loaded || (! $active && $now - $this->lastPolledAt < self::IdlePoll)) {
            return;
        }

        $this->lastPolledAt = $now;

        // Once a poll's pinned messages were left aside for a pin of yours, they're behind the thread, so the next poll
        // asks from their version and gets them again. Reactions sent twice are whole lists, so taking them again is
        // harmless.
        $this->refresh($this->messagesPath(), [
            'after' => (string) $this->polledThroughId,
            'reactions_after' => (string) min($this->reactionsVersion, $this->pinnedVersion ?? $this->reactionsVersion),
        ], fn (array $json) => $this->takePoll($json), quiet: true);
    }

    protected function revalidate(): void
    {
        $this->refresh($this->messagesPath(), [], fn (array $json) => $this->takeThread($json, fresh: true));
        $this->refreshUnreadNotifications();
    }

    protected function showsContent(): bool
    {
        return $this->loaded;
    }

    /**
     * Pushes what's in the composer, its text, its photos or both, staged at once, as the web does. While a voice
     * note is in its place, the voice note goes.
     */
    public function send(): void
    {
        if ($this->recorder !== null) {
            $this->pushRecording();

            return;
        }

        $body = trim($this->draft);
        $me = $this->me();

        if (($body === '' && $this->photos === []) || mb_strlen($body) > Message::MaxLength || $me === null || $this->thread?->access !== Thread::Open) {
            return;
        }

        $message = Message::staged($me->id, (string) Str::uuid(), $body, $this->findMessage($this->replyToId), $this->photos);
        $write = app(Outbox::class)->add(PendingWrite::Message, $this->threadPath(), [
            'body' => $body,
            'reply_to_id' => $message->replyToId,
            'images' => PendingWrite::imagePayload($this->photos),
        ], $message->clientId);

        if ($write === null) {
            return;
        }

        $this->pending[] = $message;
        $this->draft = '';
        $this->mention = null;
        $this->photos = [];
        $this->describing = null;
        $this->replyToId = null;
        $this->lastActivityAt = self::now();
        $this->lastTypingAt = 0;

        $this->sendFromOutbox($write);
    }

    /**
     * Sends a rejected message again, with the same client id, so devnull stores it once however often it's sent.
     */
    public function retry(string $clientId): void
    {
        $write = app(Outbox::class)->findByClientId($clientId);

        if ($write !== null) {
            $this->resendMessage($write);
        }
    }

    /**
     * A recorded voice note goes as a message of its own, staged at once with whatever it replies to, its recording
     * kept on the phone until devnull has it.
     */
    protected function pushVoiceNote(MessageVoice $voice): void
    {
        $me = $this->me();

        if ($me === null || $this->thread?->access !== Thread::Open) {
            app(VoiceNotes::class)->discard($voice);

            return;
        }

        if ($this->playingVoice === Message::DraftVoice) {
            $this->stopVoiceNotes();
        }

        $message = Message::staged($me->id, (string) Str::uuid(), '', $this->findMessage($this->replyToId), voice: $voice);
        $write = app(Outbox::class)->add(PendingWrite::Message, $this->threadPath(), [
            'body' => '',
            'reply_to_id' => $message->replyToId,
            'voice' => $voice->toOutbox(),
        ], $message->clientId);

        if ($write === null) {
            return;
        }

        $this->pending[] = $message;
        $this->replyToId = null;
        $this->lastActivityAt = self::now();

        $this->sendFromOutbox($write);
    }

    protected function takesVoiceNotes(): bool
    {
        return $this->thread?->access === Thread::Open && trim($this->draft) === '' && $this->photos === [];
    }

    /**
     * Why a voice note couldn't be recorded comes as a toast, from the top, as a photo's does.
     */
    protected function voiceNoteFailed(string $error): void
    {
        Toasts::failed($error);
    }

    protected function voiceNoteFor(string $key): ?MessageVoice
    {
        if ($key === Message::DraftVoice) {
            return $this->recorded;
        }

        foreach ([...$this->messages, ...$this->pending, ...$this->pinned] as $message) {
            if ($message->voice !== null && $message->voiceKey() === $key) {
                return $message->voice;
            }
        }

        return null;
    }

    /**
     * Leaving the thread stops the voice note playing, and throws away one being recorded, as leaving the web's does.
     */
    public function navigate(string $uri, array $data = []): static
    {
        $this->leaveVoiceNotes();

        return parent::navigate($uri, $data);
    }

    public function back(): static
    {
        $this->leaveVoiceNotes();

        return parent::back();
    }

    public function unmount(): void
    {
        $this->leaveVoiceNotes();

        parent::unmount();
    }

    private function leaveVoiceNotes(): void
    {
        $this->stopVoiceNotes();
        $this->letGoOfRecording();
    }

    /**
     * The composer's Ping, as the web's: it lands in the thread as a log line, and buzzes as one coming in does. When
     * devnull won't take it, such as before anyone has said anything or after three in a minute, a toast says why
     * in its words.
     */
    public function ping(): void
    {
        if ($this->thread?->access !== Thread::Open) {
            return;
        }

        $this->lastActivityAt = self::now();

        $this->writeInBackground('POST', $this->threadPath().'/ping', null, function (array $json): void {
            $ping = Message::fromArray(is_array($json['data'] ?? null) ? $json['data'] : []);

            if ($ping->id > 0) {
                $this->messages = self::merged($this->messages, [$ping]);
                $this->readThroughId = max($this->readThroughId, $ping->id);
                $this->noticePings();
            }
        }, function (string $error, ?ApiException $exception): void {
            Toasts::failed($exception === null ? 'Could not ping. Try again.' : $error);
        });
    }

    /**
     * Tells the others you're typing, at most every three seconds, while the composer has something in it.
     */
    public function updatedDraft(): void
    {
        $this->composing = true;
        $now = self::now();

        if (trim($this->draft) === '' || $this->thread?->access !== Thread::Open || $now - $this->lastTypingAt < self::TypingPing || $this->lastId() === 0) {
            return;
        }

        $this->lastTypingAt = $now;
        $this->writeInBackground('PUT', $this->threadPath().'/typing', null, fn () => null, fn () => null);
    }

    /**
     * The messages before the first one showing, as the web loads them when you scroll back.
     */
    public function loadOlder(): void
    {
        $first = $this->messages[0] ?? null;

        if (! $this->hasOlder || $first === null) {
            return;
        }

        $json = $this->attempt(fn (Client $devnull): array => $devnull->olderMessages($this->threadPath(), $first->id));

        if ($json === null) {
            return;
        }

        $older = Thread::fromArray($json);
        $this->messages = self::merged($older->messages, $this->messages);
        $this->hasOlder = $older->hasOlder;
    }

    /**
     * A message let go after a drag, where it came to rest. The gesture area moves it with the finger toward its edge,
     * someone else's to the left and your own to the right, and springs it open or shut on release by how far it went
     * and how fast. Open, its actions stay up and any other message's go away; shut, its own go away.
     */
    public function draggedMessage(int $id, float $x, float $y = 0): void
    {
        $message = $this->findMessage($id);

        if ($message === null || ! $this->takesActions($message)) {
            return;
        }

        $toward = ($message->userId === $this->me()?->id ? 1 : -1) * $x;

        if ($toward >= $this->slideOf($message) / 2) {
            $this->actionsForId = $id;
            $this->actionsShownAt = self::now();
        } elseif ($this->actionsForId === $id) {
            $this->actionsForId = null;
        }
    }

    /**
     * A tap anywhere in the thread puts away the actions showing beside a message.
     */
    public function hideActions(): void
    {
        if (self::now() - $this->actionsShownAt < self::SwipeTapWindow) {
            return;
        }

        $this->actionsForId = null;
    }

    /**
     * React: the web's quick reactions and More, in a bubble beside the React that was pressed, above it unless there's
     * no room. The message's options stay up underneath.
     */
    public function openReactions(int $id): void
    {
        if ($this->findMessage($id)?->isEvent() !== false) {
            return;
        }

        $this->reactsFromPinned = $this->sheetOpen;
        $this->reactionsFor = $id;
    }

    /**
     * A tap outside the bubble closes it.
     */
    public function reactionsClosed(): void
    {
        $this->reactionsFor = null;
        $this->reactsFromPinned = false;
    }

    /**
     * The bar over the thread opens the org's pinned messages in the sheet.
     */
    public function openPinned(): void
    {
        if ($this->pinned === []) {
            return;
        }

        $this->actionsForId = null;
        $this->reactionsFor = null;
        $this->reactsFromPinned = false;
        $this->sheetOpen = true;
    }

    public function messageSheetClosed(): void
    {
        $this->sheetOpen = false;

        // The pinned sheet making way for the emoji picker keeps where React came from.
        if ($this->emojiPickerFor === null) {
            $this->reactionsFor = null;
            $this->reactsFromPinned = false;
        }
    }

    /**
     * More reactions: the phone's own emoji picker, with its search, recents and skin tones, for any message. The
     * bubble closes and the pinned sheet makes way for it.
     */
    public function openEmojiPicker(int $id): void
    {
        if ($this->findMessage($id)?->isEvent() !== false) {
            return;
        }

        $this->actionsForId = null;
        $this->reactionsFor = null;
        $this->sheetOpen = false;
        $this->emojiPickerFor = $id;
    }

    /**
     * Reacts with the emoji picked, as devnull takes it (see ReactionEmoji), and goes back to the pinned messages if
     * React came from there. One devnull doesn't know isn't sent: a toast says so and the picker stays up for another.
     */
    public function emojiPicked(string $emoji): void
    {
        $id = $this->emojiPickerFor;

        if ($id === null) {
            return;
        }

        $reaction = ReactionEmoji::forDevnull($emoji);

        if ($reaction === null) {
            Toasts::failed("devnull doesn't take {$emoji} as a reaction yet. Pick another.");

            return;
        }

        $this->emojiPickerFor = null;
        $this->sheetOpen = $this->reactsFromPinned;
        $this->reactsFromPinned = false;
        $this->toggleReaction($id, $reaction);
    }

    /**
     * Closing the picker without a pick goes back to the pinned messages if React came from there.
     */
    public function emojiPickerClosed(): void
    {
        $this->emojiPickerFor = null;

        if ($this->reactsFromPinned) {
            $this->sheetOpen = true;
            $this->reactsFromPinned = false;
        }
    }

    public function reply(int $id): void
    {
        $this->replyToId = $id;
        $this->actionsForId = null;
        $this->reactionsFor = null;
        $this->sheetOpen = false;
        $this->reactsFromPinned = false;
        $this->focusField(onlyIfIdle: true);
    }

    /**
     * React, from the menu a long press opens: the quick reactions in their bubble when the message's options already
     * show, so the bubble has React to point at, and otherwise the phone's emoji picker.
     */
    public function reactFromMenu(int $id): void
    {
        if ($this->actionsForId === $id) {
            $this->openReactions($id);

            return;
        }

        $this->openEmojiPicker($id);
    }

    /**
     * Copy text, from the menu a long press opens: the message's text, as it was written, on the clipboard. A message
     * still being pushed is named by its client id. iOS says so in a toast, in the web's words. Android says so itself,
     * at the foot of the screen, as it has for every app since Android 13, the oldest the app runs on.
     */
    public function copyText(int|string $id): void
    {
        $message = is_int($id) ? $this->findMessage($id) : $this->findPending($id);

        if ($message === null || $message->body === '') {
            return;
        }

        if (! Clipboard::writeText($message->body)) {
            Toasts::failed('Could not copy the text. Try again.');

            return;
        }

        if (System::isIos()) {
            Toasts::say('Copied.', short: true);
        }
    }

    /**
     * A press into the field: it has the keyboard now.
     */
    public function fieldPressed(): void
    {
        $this->composing = true;
    }

    /**
     * Show more and Show less, on a pinned message longer than the sheet's three lines.
     */
    public function togglePinnedText(int $id): void
    {
        $this->expandedPins = in_array($id, $this->expandedPins, true)
            ? array_values(array_diff($this->expandedPins, [$id]))
            : [...$this->expandedPins, $id];
    }

    /**
     * Pin or Unpin beside a message, for an org's maintainers. Pinning asks first, as the web does, since everyone in
     * the org sees what's pinned, whatever history they were given, until you say not to ask again.
     */
    public function togglePin(int $id): void
    {
        $message = $this->findMessage($id);

        if ($message === null || ! $this->canPin() || ! $this->takesActions($message)) {
            return;
        }

        $this->actionsForId = null;

        if ($this->isPinned($id)) {
            $this->unpinMessage($id);

            return;
        }

        if (app(Account::class)->skipsPinWarning()) {
            $this->pinMessage($id);

            return;
        }

        $this->pinAskedId = $id;

        Dialog::alert('Pin for the whole org?', "Everyone in the org sees pinned messages, including people added without Share history, who can't read anything pushed before they joined.", [
            ['label' => 'Cancel', 'style' => 'cancel'],
            ['label' => self::PinMessage],
            ['label' => self::PinWithoutAsking],
        ])->id(self::PinAlert);
    }

    #[On(ButtonPressed::class)]
    public function onAlertButton(string $label = '', ?string $id = null): void
    {
        if ($id !== self::PinAlert || $this->pinAskedId === null) {
            return;
        }

        $message = $this->pinAskedId;
        $this->pinAskedId = null;

        if ($label === self::PinWithoutAsking) {
            app(Account::class)->rememberSkipsPinWarning();
        }

        if (in_array($label, [self::PinMessage, self::PinWithoutAsking], true)) {
            $this->pinMessage($message);
        }
    }

    /**
     * Unpin, beside a pinned message in the thread or in the pinned sheet. The sheet closes once nothing is left in it.
     */
    public function unpinMessage(int $id): void
    {
        if (! $this->canPin() || ! $this->isPinned($id)) {
            return;
        }

        $before = $this->pinned;
        $this->pinned = array_values(array_filter($this->pinned, fn (Message $pinned): bool => $pinned->id !== $id));

        if ($this->pinned === []) {
            $this->sheetOpen = false;
        }

        $this->sendPin($id, false, $before);
    }

    public function cancelReply(): void
    {
        $this->replyToId = null;
    }

    /**
     * A double tap toggles a heart, as on the web.
     */
    public function heart(int $id): void
    {
        $this->toggleReaction($id, '❤️');
    }

    /**
     * Leaves the emoji on the message, or takes it back, at once. If devnull says no, it goes back as it was. Picked in
     * the bubble, the bubble closes and the message's options go away.
     */
    public function toggleReaction(int $id, string $emoji): void
    {
        $message = $this->findMessage($id);
        $me = $this->me();

        if ($this->reactionsFor !== null) {
            $this->reactionsFor = null;
            $this->reactsFromPinned = false;
            $this->actionsForId = null;
        }

        if ($message === null || $message->isPending() || $message->isEvent() || $me === null || $this->thread?->access !== Thread::Open) {
            return;
        }

        $reacted = $message->hasReacted($me->id, $emoji);
        $reactions = $reacted
            ? array_values(array_filter($message->reactions, fn (array $reaction): bool => ! ($reaction['user_id'] === $me->id && $reaction['emoji'] === $emoji)))
            : [...$message->reactions, ['user_id' => $me->id, 'emoji' => $emoji]];

        $this->replaceMessage($message->withReactions($reactions));
        $this->reacting[$id] = true;
        $this->lastActivityAt = self::now();

        $this->writeInBackground($reacted ? 'DELETE' : 'POST', Client::reactionsPath($id), ['emoji' => $emoji], function (array $json) use ($id): void {
            unset($this->reacting[$id]);

            if (is_array($json['reactions'] ?? null) && ($message = $this->findMessage($id)) !== null) {
                $this->replaceMessage($message->withReactions(Message::reactionsFrom($json['reactions'])));
            }
        }, function () use ($id, $message): void {
            unset($this->reacting[$id]);
            $this->replaceMessage($message);
        });
    }

    /**
     * Merges their request, so you can reply. Done, the composer takes the bar's place.
     */
    public function merge(): void
    {
        $this->answerRequest('merge');
    }

    /**
     * Drops their request, without telling them, and goes back to the inbox.
     */
    public function drop(): void
    {
        $this->answerRequest('drop');
    }

    public function stash(): void
    {
        $this->writeInBackground('POST', $this->threadPath().'/archive', null, fn () => $this->back(), fn () => null);
    }

    public function pop(): void
    {
        $this->writeInBackground('DELETE', $this->threadPath().'/archive', null, function (): void {
            $this->thread = $this->thread === null ? null : new Thread(...[...get_object_vars($this->thread), 'isArchived' => false]);
        }, fn () => null);
    }

    /**
     * Pin and Unpin, from the menu, as the inbox row's: the thread says so at once, and goes back if devnull says no.
     */
    public function pin(): void
    {
        $this->changeThread(['pinnedAt' => CarbonImmutable::now()], 'pin', ['pinned' => true], 'pinned_at', 'Could not update the pin. Try again.');
    }

    public function unpin(): void
    {
        $this->changeThread(['pinnedAt' => null], 'pin', ['pinned' => false], 'pinned_at', 'Could not update the pin. Try again.');
    }

    /**
     * Mute, from the menu: the sheet asks how long for, as the web's dialog does (see partials/mute-sheet).
     */
    public function chooseMute(): void
    {
        $this->choosingMute = $this->thread !== null;
    }

    public function muteFor(string $duration): void
    {
        $duration = MuteDuration::tryFrom($duration);
        $this->choosingMute = false;

        if ($duration !== null && $duration !== MuteDuration::Off) {
            $this->changeThread(['mutedUntilAt' => $duration->until()], 'mute', ['duration' => $duration->value], 'muted_until_at', 'Could not update the mute. Try again.', 'Muted '.$this->menuTitle().'.');
        }
    }

    public function muteSheetClosed(): void
    {
        $this->choosingMute = false;
    }

    public function unmute(): void
    {
        $this->changeThread(['mutedUntilAt' => null], 'mute', ['duration' => MuteDuration::Off->value], 'muted_until_at', 'Could not update the mute. Try again.', 'Unmuted '.$this->menuTitle().'.');
    }

    public function viewProfile(): void
    {
        if ($this->username !== '') {
            $this->openInTab('/users/'.rawurlencode($this->username), $this->thread?->partner === null ? [] : ['user' => $this->thread->partner]);
        }
    }

    /**
     * The org's members, where a maintainer manages them and anyone can leave.
     */
    public function viewMembers(): void
    {
        if ($this->isOrg()) {
            $this->openInTab('/orgs/'.rawurlencode($this->org).'/members');
        }
    }

    public function openProfile(string $username): void
    {
        $this->openInTab('/users/'.rawurlencode($username));
    }

    /**
     * Opens a mentioned GitHub organization's page.
     */
    public function openOrganization(string $handle): void
    {
        $this->openInTab('/organizations/'.rawurlencode($handle));
    }

    /**
     * Opens a message's link preview: a page on dev/null the app has a screen
     * for here in the app, in this tab, and anything else in the browser.
     */
    public function openLink(int $id): void
    {
        $url = $this->findMessage($id)?->linkPreview?->url;
        $screen = SiteLink::screen($url);

        if ($screen !== null) {
            $this->openInTab($screen);

            return;
        }

        if (SiteLink::isWebUrl($url)) {
            Browser::inApp($url);
        }
    }

    /**
     * Pins the message at once, first in the list as the latest pinned, as the web does.
     */
    private function pinMessage(int $id): void
    {
        $message = $this->findMessage($id);

        if ($message === null || $this->isPinned($id)) {
            return;
        }

        $before = $this->pinned;
        $this->pinned = [$message, ...$this->pinned];

        $this->sendPin($id, true, $before);
    }

    /**
     * Sends a pin or an unpin, with the pinned messages as they were before it, to go back to if devnull says no. Its
     * answer is the org's pinned messages as they are now, which the thread takes once no other pin is on its way. When
     * two maintainers take the org's last place at once, devnull answers the one it turned away without their message,
     * which counts as a no too, as on the web. A refusal says why in devnull's words, as the web's toast does.
     *
     * @param  list<Message>  $before
     */
    private function sendPin(int $id, bool $pinning, array $before): void
    {
        $this->pinning++;
        $this->lastActivityAt = self::now();
        $refused = function (?string $message = null) use ($id, $pinning, $before): void {
            $this->putPinBack($id, $pinning, $before);
            Toasts::failed($message ?? ($pinning ? 'Could not pin. Try again.' : 'Could not unpin. Try again.'));
        };

        $this->writeInBackground($pinning ? 'PUT' : 'DELETE', Client::pinPath($id), null, function (array $json) use ($id, $pinning, $refused): void {
            $this->pinning = max(0, $this->pinning - 1);
            $pinned = Thread::fromArray(['data' => ['pinned' => is_array($json['pinned'] ?? null) ? $json['pinned'] : []]])->pinned ?? [];

            if ($pinning && ! in_array($id, array_map(fn (Message $message): int => $message->id, $pinned), true)) {
                $refused();

                return;
            }

            $this->takePinned($pinned, is_int($json['reactions_version'] ?? null) ? $json['reactions_version'] : $this->reactionsVersion);
        }, function (string $message, ?ApiException $exception = null) use ($refused): void {
            $this->pinning = max(0, $this->pinning - 1);
            $refused($exception?->status === 403 ? $message : null);
        });
    }

    /**
     * Puts one message back as it was before a pin or unpin devnull refused, leaving any other change since alone:
     * a pin comes out again, an unpin goes back where it was.
     *
     * @param  list<Message>  $before
     */
    private function putPinBack(int $id, bool $pinning, array $before): void
    {
        if ($pinning) {
            $this->pinned = array_values(array_filter($this->pinned, fn (Message $pinned): bool => $pinned->id !== $id));

            return;
        }

        foreach ($before as $index => $message) {
            if ($message->id === $id && ! $this->isPinned($id)) {
                array_splice($this->pinned, min($index, count($this->pinned)), 0, [$message]);
            }
        }
    }

    /**
     * The org's pinned messages as devnull sent them, with the reactions version it read just before, unless a pin of
     * yours is still on its way, whose answer is fresher, or they're older than the ones showing. Reactions you're
     * still changing stay as they show.
     *
     * @param  list<Message>  $pinned
     */
    private function takePinned(array $pinned, int $version): void
    {
        if ($this->pinning > 0 || ($this->pinnedVersion !== null && $version < $this->pinnedVersion)) {
            return;
        }

        $this->pinned = array_map(function (Message $message): Message {
            $shown = isset($this->reacting[$message->id]) ? $this->findMessage($message->id) : null;

            return $shown === null ? $message : $message->withReactions($shown->reactions);
        }, $pinned);
        $this->pinnedVersion = $version;

        if ($this->pinned === []) {
            $this->sheetOpen = false;
        }
    }

    private function answerRequest(string $answer): void
    {
        $this->requestError = null;

        $this->writeInBackground('POST', $this->threadPath().'/'.$answer, null, function () use ($answer): void {
            if ($answer === 'drop') {
                $this->back();

                return;
            }

            $this->thread = $this->thread === null ? null : new Thread(...[...get_object_vars($this->thread), 'access' => Thread::Open]);
        }, function (): void {
            $this->requestError = 'Could not update the request. Try again.';
        });
    }

    /**
     * A message you pushed to this thread went, with devnull's answer: the
     * real one shows in its place. Or it didn't, or it's going again, and it
     * shows as the outbox has it now: rejected, or staged.
     *
     * @param  array<string, mixed>|null  $json
     */
    protected function showMessage(PendingWrite $write, ?array $json): void
    {
        if ($write->target !== $this->threadPath()) {
            return;
        }

        if ($json === null) {
            $status = $write->isFailed() ? Message::Rejected : Message::Staged;
            $this->pending = array_map(
                fn (Message $pending): Message => $pending->clientId === $write->client_id ? $pending->withStatus($status) : $pending,
                $this->pending,
            );

            return;
        }

        $pushed = Message::fromArray(is_array($json['data'] ?? null) ? $json['data'] : []);

        $this->pending = array_values(array_filter($this->pending, fn (Message $pending): bool => $pending->clientId !== $write->client_id));

        if ($pushed->id > 0) {
            $this->messages = self::merged($this->messages, [$pushed]);
            $this->readThroughId = max($this->readThroughId, $pushed->id);
        }

        $this->lastActivityAt = self::now();
    }

    /**
     * The messages you pushed to this thread that devnull hasn't taken, from
     * the outbox: staged while they're on their way, rejected once devnull
     * turned them down. Ones devnull has sent back already show as pushed.
     */
    private function showPending(): void
    {
        $me = $this->me();

        if ($me === null) {
            return;
        }

        $this->pending = app(Outbox::class)->pending(PendingWrite::Message, $this->threadPath(), withFailed: true)
            ->map(function (PendingWrite $write) use ($me): Message {
                $message = Message::staged($me->id, $write->client_id, $write->body(), $this->findMessage($write->replyToId()), $write->images(), $write->voice());

                return $write->isFailed() ? $message->withStatus(Message::Rejected) : $message;
            })
            ->values()
            ->all();

        $this->dropPushed();
    }

    /**
     * The thread's first page, kept or fresh. The first fresh one notes where you'd pulled to for HEAD, before this
     * screen pulls what's showing. Messages loaded before the page stay above it.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeThread(array $json, bool $fresh = false): void
    {
        $thread = Thread::fromArray($json);

        if ($thread->partner === null && ! $thread->isOrg()) {
            return;
        }

        $earliest = $thread->messages[0]->id ?? PHP_INT_MAX;
        $older = array_values(array_filter($this->messages, fn (Message $message): bool => $message->id < $earliest));

        $this->thread = $thread;
        $this->messages = self::merged($older, $thread->messages);
        $this->hasOlder = $older === [] ? $thread->hasOlder : $this->hasOlder;

        if ($thread->pinned !== null) {
            $this->takePinned($thread->pinned, $thread->reactionsVersion);
        }

        $this->reactionsVersion = max($this->reactionsVersion, $thread->reactionsVersion);
        $this->readThroughId = max($this->readThroughId, $thread->readThroughId);
        $this->polledThroughId = max($this->polledThroughId, $thread->messages[array_key_last($thread->messages) ?? -1]->id ?? 0);
        $this->dropPushed();

        $this->loaded = true;

        if (! $fresh) {
            return;
        }

        if ($this->head === null) {
            $this->head = $thread->readThroughId;
            $this->headUntil = $this->lastId();
        }

        $this->noticePings();
        $this->markRead();
    }

    /**
     * What a poll brought: messages after the last one, reactions changed since the version seen, an org's pinned
     * messages if they changed, and the rest of the thread as it is now. New messages, or someone typing, keep the poll
     * quick for another minute.
     *
     * @param  array<string, mixed>  $json
     */
    private function takePoll(array $json): void
    {
        $poll = Thread::fromArray($json);
        $shown = array_flip(array_map(fn (Message $message): int => $message->id, $this->messages));
        $new = array_values(array_filter($poll->messages, fn (Message $message): bool => ! isset($shown[$message->id])));

        foreach ($poll->reactionChanges as $id => $reactions) {
            if (! isset($this->reacting[$id]) && ($message = $this->findMessage($id)) !== null) {
                $this->replaceMessage($message->withReactions($reactions));
            }
        }

        if ($poll->pinned !== null) {
            $this->takePinned($poll->pinned, $poll->reactionsVersion);
        }

        $this->thread = new Thread(...[...get_object_vars($poll), 'messages' => [], 'reactionChanges' => [], 'hasOlder' => $this->hasOlder, 'pinned' => null]);
        $this->messages = self::merged($this->messages, $new);
        $this->reactionsVersion = max($this->reactionsVersion, $poll->reactionsVersion);
        $this->polledThroughId = max($this->polledThroughId, $poll->messages[array_key_last($poll->messages) ?? -1]->id ?? 0);
        $this->dropPushed();

        if ($new !== [] || $poll->typing !== []) {
            $this->lastActivityAt = self::now();
        }

        $this->noticePings();
        $this->markRead();
    }

    /**
     * A ping newer than the last one the thread showed buzzes the phone, as the web shakes the thread to MSN's ping
     * sound: always for yours, and for someone else's unless you've muted the thread.
     */
    private function noticePings(): void
    {
        $ping = null;

        foreach (array_reverse($this->messages) as $message) {
            if ($message->isPing()) {
                $ping = $message;

                break;
            }
        }

        $seen = $this->seenPingId;
        $this->seenPingId = max($seen ?? 0, $ping->id ?? 0);

        if ($seen === null || $ping === null || $ping->id <= $seen) {
            return;
        }

        if ($ping->userId === $this->me()?->id || ! ($this->thread?->isMuted() ?? false)) {
            Device::vibrate();
        }
    }

    /**
     * Pulls through the latest message, since the thread is on screen, and asks devnull for the badge again after.
     */
    private function markRead(): void
    {
        $latest = $this->lastId();

        if ($latest <= $this->readThroughId) {
            return;
        }

        $this->readThroughId = $latest;

        $this->writeInBackground('PUT', $this->threadPath().'/read', ['through' => $latest], fn () => $this->refreshUnreadNotifications(), fn () => null);
    }

    /**
     * Staged messages devnull has now sent back, by client id, go: the real one shows in their place.
     */
    private function dropPushed(): void
    {
        $sent = array_flip(array_map(fn (Message $message): string => $message->clientId, $this->messages));

        $this->pending = array_values(array_filter($this->pending, fn (Message $pending): bool => ! isset($sent[$pending->clientId])));
    }

    private function showKept(): void
    {
        if (($json = $this->cached($this->messagesPath())) !== null) {
            $this->takeThread($json);
        }
    }

    /**
     * Changes the thread's pin or mute at once, and in the inbox kept for when you go back, then tells devnull. Its
     * answer, `pinned_at` or `muted_until_at`, has the last word. If it says no, the thread goes back and a toast
     * says so.
     *
     * @param  array<string, mixed>  $change
     * @param  array<string, mixed>  $payload
     */
    private function changeThread(array $change, string $endpoint, array $payload, string $answer, string $failed, ?string $done = null): void
    {
        $before = $this->thread;

        if ($before === null) {
            return;
        }

        $field = array_key_first($change);
        $this->showThreadChange($change);

        $this->writeInBackground('PUT', $this->threadPath().'/'.$endpoint, $payload, function (array $json) use ($field, $answer, $done): void {
            $this->showThreadChange([$field => Commit::date($json[$answer] ?? null)]);

            if ($done !== null) {
                Toasts::say($done);
            }
        }, function () use ($field, $before, $failed): void {
            $this->showThreadChange([$field => $before->{$field}]);
            Toasts::failed($failed);
        });
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function showThreadChange(array $change): void
    {
        if ($this->thread === null) {
            return;
        }

        $this->thread = new Thread(...[...get_object_vars($this->thread), ...$change]);

        if ($this->row !== null) {
            app(ResponseCache::class)->conversationChanged($this->row->id, [
                ['pinnedAt' => 'pinned_at', 'mutedUntilAt' => 'muted_until_at'][array_key_first($change)] => reset($change)?->toJSON(),
            ]);
        }
    }

    /**
     * What the web's menu and toasts call the thread: the partner's handle, or the org's title.
     */
    private function menuTitle(): string
    {
        return $this->isOrg() ? $this->title() : '@'.$this->username;
    }

    /**
     * @return list<DraftImage>
     */
    protected function attachedPhotos(): array
    {
        return $this->photos;
    }

    /**
     * Photos just attached focus the field for a caption, as picking a message to reply to does. The keyboard coming
     * up also brings the end of the thread back above the bar, which the photos have made taller.
     *
     * @param  list<DraftImage>  $photos
     */
    protected function photosChanged(array $photos): void
    {
        if (count($photos) > count($this->photos)) {
            $this->focusField();
        }

        $this->photos = $photos;
    }

    /**
     * Mounts the field afresh so it takes focus and raises the keyboard. A reply leaves a field already in use alone
     * ($onlyIfIdle); photos always remount it, since the library's sheet has taken the keyboard down by then.
     */
    private function focusField(bool $onlyIfIdle = false): void
    {
        if (! $onlyIfIdle || ! $this->composing) {
            $this->composerKey++;
        }

        $this->composing = true;
    }

    protected function takesPhotos(): bool
    {
        return $this->thread?->access === Thread::Open;
    }

    /**
     * Why a photo won't do comes as a toast, from the top, clear of the composer.
     */
    protected function photoFailed(?string $error): void
    {
        if ($error !== null) {
            Toasts::failed($error);
        }
    }

    private function threadPath(): string
    {
        return Client::threadPath($this->username, $this->org === '' ? null : $this->org);
    }

    private function messagesPath(): string
    {
        return $this->threadPath().'/messages';
    }

    private function isOrg(): bool
    {
        return $this->org !== '';
    }

    private function lastId(): int
    {
        return $this->messages[array_key_last($this->messages) ?? -1]->id ?? 0;
    }

    /**
     * A message in the thread, or one the org pinned from before the history you were given, which you can still reply
     * and react to.
     */
    private function findMessage(?int $id): ?Message
    {
        foreach ([...$this->messages, ...$this->pinned] as $message) {
            if ($message->id === $id) {
                return $message;
            }
        }

        return null;
    }

    private function findPending(string $clientId): ?Message
    {
        foreach ($this->pending as $message) {
            if ($message->clientId === $clientId) {
                return $message;
            }
        }

        return null;
    }

    private function replaceMessage(Message $replacement): void
    {
        $replace = fn (Message $message): Message => $message->id === $replacement->id ? $replacement : $message;

        $this->messages = array_map($replace, $this->messages);
        $this->pinned = array_map($replace, $this->pinned);
    }

    private function isPinned(int $id): bool
    {
        foreach ($this->pinned as $message) {
            if ($message->id === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only an org's maintainers pin, as devnull allows, and only while the thread takes messages.
     */
    private function canPin(): bool
    {
        return $this->isOrg() && $this->thread?->role === 'maintainer' && $this->thread->access === Thread::Open;
    }

    /**
     * React, Reply and Pin are for messages devnull has, not an org's events, while the thread takes messages.
     */
    private function takesActions(Message $message): bool
    {
        return ! $message->isEvent() && ! $message->isPending() && $this->thread?->access === Thread::Open;
    }

    /**
     * Now, to the microsecond, as the clock the poll and the typing pings are timed by.
     */
    private static function now(): float
    {
        return (float) CarbonImmutable::now()->format('U.u');
    }

    /**
     * Two lists of messages as one, oldest first, the later list winning where both have a message.
     *
     * @param  list<Message>  $first
     * @param  list<Message>  $second
     * @return list<Message>
     */
    private static function merged(array $first, array $second): array
    {
        $byId = [];

        foreach ([...$first, ...$second] as $message) {
            $byId[$message->id] = $message;
        }

        ksort($byId);

        return array_values($byId);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array{emoji: string, count: int}>
     */
    private static function usageFrom(array $json): array
    {
        $usage = [];

        foreach (is_array($json['usage'] ?? null) ? $json['usage'] : [] as $used) {
            if (is_array($used) && is_string($used['emoji'] ?? null) && is_int($used['count'] ?? null)) {
                $usage[] = ['emoji' => $used['emoji'], 'count' => $used['count']];
            }
        }

        return $usage;
    }

    /**
     * The quick reactions: the web's three, where one you use at least three times more than a slot's own takes
     * that slot, in place.
     *
     * @return list<string>
     */
    private function quickReactions(): array
    {
        $counts = array_column($this->usage, 'count', 'emoji');
        $slots = self::QuickReactions;

        foreach ($this->usage as $used) {
            if (in_array($used['emoji'], $slots, true)) {
                continue;
            }

            $weakest = array_reduce(array_keys($slots), fn (?int $carry, int $slot): int => $carry === null || ($counts[$slots[$slot]] ?? 0) < ($counts[$slots[$carry]] ?? 0) ? $slot : $carry);

            if ($used['count'] >= ($counts[$slots[$weakest]] ?? 0) + 3) {
                $slots[$weakest] = $used['emoji'];
            }
        }

        return $slots;
    }

    private function title(): string
    {
        $viewerId = $this->me()?->id;

        if ($this->thread === null) {
            return $this->row?->title($viewerId) ?? ($this->isOrg() ? '' : '@'.$this->username);
        }

        if (! $this->isOrg()) {
            return $this->thread->partner->name ?? '@'.$this->username;
        }

        $others = array_values(array_filter($this->thread->members, fn (array $member): bool => $member['user']->id !== $viewerId));
        $names = array_map(fn (array $member): string => (string) strtok($member['user']->name, ' '), $others);

        return $this->thread->orgName ?? ($names === [] ? 'Just you' : implode(', ', $names));
    }

    /**
     * Under the title: who's typing while anyone is, as the web shows above its composer, otherwise the handle, or
     * how many are in the org.
     */
    private function subtitle(): ?string
    {
        $typing = self::typingText($this->thread->typing ?? [], $this->isOrg());

        if ($typing !== null) {
            return $typing;
        }

        if (! $this->isOrg()) {
            return '@'.$this->username;
        }

        $count = $this->thread === null ? count($this->row->members ?? []) : count($this->thread->members);

        return $count === 0 ? 'org' : 'org · '.Display::count($count, 'member', 'members');
    }

    public function render(): View
    {
        $this->sampleRecording();

        $viewer = $this->me();
        $thread = $this->thread;

        $authors = [];

        foreach ([$viewer, $thread?->partner, ...array_column($thread->members ?? [], 'user')] as $person) {
            if ($person !== null) {
                $authors[$person->id] = $person;
            }
        }

        return view('native.conversation', [
            'viewer' => $viewer,
            'authors' => $authors,
            'myColour' => $this->myColour(),
            'partner' => $thread?->partner,
            'isOrg' => $this->isOrg(),
            'mentionSuggestions' => $this->isOrg() ? $this->mentionSuggestions($thread->members ?? [], $viewer) : [],
            'access' => $thread->access ?? Thread::Open,
            'items' => $this->items($viewer),
            'composerClearance' => self::composerClearance($this->draft, $this->replyToId !== null, $this->photos !== [], $this->describing !== null),
            'replyTo' => $this->findMessage($this->replyToId),
            'quickReactions' => $this->quickReactions(),
            'canPush' => $thread !== null && $thread->access === Thread::Open,
            'canPin' => $this->canPin(),
            'actionsWidth' => $this->actionsWidth(),
            'pinnedIds' => array_flip(array_map(fn (Message $message): int => $message->id, $this->pinned)),
            'longPins' => $this->longPins(),
            'placeholder' => $this->isOrg() ? 'Message '.($thread->orgName ?? 'the org') : 'Message @'.$this->username,
            'pingLabel' => $this->isOrg() ? 'Ping everyone in the org' : 'Ping @'.$this->username,
            'muting' => $this->choosingMute ? 'thread' : null,
            'mutingTitle' => $this->menuTitle(),
            'player' => $this->voicePlayback(),
            'recordingElapsed' => $this->recordingElapsed(),
        ]);
    }

    private function actionsWidth(): int
    {
        return $this->isOrg() ? self::OrgActionsWidth : self::ActionsWidth;
    }

    /**
     * Where a message rests sideways, as a value the gesture area drags and the row and its buttons follow: slid open
     * toward its edge while its actions show, home otherwise. A message devnull doesn't have yet, or an org's event,
     * doesn't move.
     */
    private function panFor(Message $message, bool $mine): ?SharedValue
    {
        if ($message->isPending() || $message->isEvent()) {
            return null;
        }

        $rest = $this->actionsForId === $message->id ? ($mine ? 1 : -1) * $this->slideOf($message) : 0;
        $pan = $this->pans[$message->id] ??= SharedValue::make($rest);
        $pan->setValue($rest);

        return $pan;
    }

    /**
     * How far a drag slides a message toward its edge: the room its actions take, or for a short bubble only as far as
     * keeps some of it on screen (see BubblePadding). Images are 240 points wide, so they slide the whole way.
     */
    private function slideOf(Message $message): int
    {
        $room = $this->actionsWidth() + self::ActionsGap;

        if ($message->body === '') {
            return $room;
        }

        $longest = max(array_map(mb_strlen(...), explode("\n", $message->body)));
        $bubble = self::BubblePadding + (int) round($longest * self::BubbleCharacterWidth);

        return min($room, max(self::LeastSlide, $bubble - self::KeptOnScreen));
    }

    /**
     * The pinned messages that run past the three lines the sheet shows of them, which get Show more.
     *
     * @return array<int, true>
     */
    private function longPins(): array
    {
        $long = [];

        foreach ($this->pinned as $message) {
            if (Display::lineCount($message->body, self::PinnedCharactersPerLine) > self::PinnedLines) {
                $long[$message->id] = true;
            }
        }

        return $long;
    }

    /**
     * How far the thread pads its end to clear the composer bar: the bar with one line in its field, the lines the
     * draft has grown by, the reply bar while replying, the photos and an open description, and a little room. The
     * scroll view adds the home indicator itself while the keyboard is down, so this is the same either way.
     */
    private static function composerClearance(string $draft, bool $replying, bool $hasPhotos, bool $describing): int
    {
        $grownBy = Display::replyBarClearance($draft) - Display::ReplyBarHeight - Display::HomeIndicatorHeight;

        return self::ComposerBarHeight + $grownBy
            + ($replying ? self::ReplyingBarHeight : 0)
            + ($hasPhotos ? self::PhotosHeight : 0)
            + ($hasPhotos && $describing ? self::DescriptionHeight : 0)
            + 8;
    }

    /**
     * The web's typing line: one person by handle, two by both, more by how many.
     *
     * @param  list<string>  $usernames
     */
    private static function typingText(array $usernames, bool $isOrg): ?string
    {
        return match (count($usernames)) {
            0 => null,
            1 => '@'.$usernames[0].' is typing…',
            2 => '@'.$usernames[0].' and @'.$usernames[1].' are typing…',
            default => count($usernames).' people are typing…',
        };
    }

    /**
     * What the thread shows, top to bottom: a day marker where the day changes, HEAD before the first message you
     * hadn't pulled when it opened, an org's events as log lines, and each message with whether it starts or ends a
     * run of messages by the same person, which is where the web puts the author and the time.
     *
     * @return list<array<string, mixed>>
     */
    private function items(?User $viewer): array
    {
        $all = [...$this->messages, ...$this->pending];
        $items = [];
        $day = null;
        $status = $this->statusMessage($all, $viewer);
        $unread = array_keys(array_filter($all, fn (Message $message): bool => $this->head !== null && ! $message->isPending() && $message->id > $this->head && $message->id <= $this->headUntil && $message->userId !== $viewer?->id && ! $message->isEvent()));

        // As on the web, HEAD only shows when you'd read something above it.
        $headAt = ($unread[0] ?? 0) > 0 ? $unread[0] : null;

        foreach ($all as $index => $message) {
            $label = $message->createdAt === null ? null : Display::dayLabel($message->createdAt);

            if ($label !== null && $label !== $day) {
                $items[] = ['type' => 'day', 'label' => $label];
                $day = $label;
            }

            if ($index === $headAt) {
                $items[] = ['type' => 'head', 'count' => count($unread)];
            }

            if ($message->isEvent()) {
                $items[] = ['type' => 'event', 'message' => $message, 'text' => $message->eventText($viewer?->id)];

                continue;
            }

            $answerable = $this->takesActions($message);
            $pinned = $this->canPin() ? $this->isPinned($message->id) : null;
            $previous = $items[array_key_last($items) ?? -1] ?? null;
            $startsRun = ($previous['type'] ?? null) !== 'message' || $previous['message']->userId !== $message->userId;

            if (! $startsRun) {
                $items[array_key_last($items)]['endsRun'] = false;
            }

            $items[] = [
                'type' => 'message',
                'message' => $message,
                'mine' => $message->userId === $viewer?->id,
                'startsRun' => $startsRun,
                'endsRun' => true,
                'status' => $message === $status ? $this->statusOf($message) : null,
                'author' => $message->user ?? $this->authorOf($message->userId),
                'slide' => $this->slideOf($message),
                'pan' => $this->panFor($message, $message->userId === $viewer?->id),
                'menu' => MessageMenu::items($message, $answerable, $pinned),
                'photosMenu' => $message->images === [] || $message->body === '' ? [] : MessageMenu::items($message, $answerable, $pinned, named: false),
            ];
        }

        return $items;
    }

    /**
     * The message whose status the thread shows, as the web picks it: the latest one you're still pushing, or else
     * your latest message, or in an org the latest message at all.
     *
     * @param  list<Message>  $all
     */
    private function statusMessage(array $all, ?User $viewer): ?Message
    {
        if ($this->pending !== []) {
            return $this->pending[array_key_last($this->pending)];
        }

        foreach (array_reverse($all) as $message) {
            if (! $message->isEvent() && ($this->isOrg() || $message->userId === $viewer?->id)) {
                return $message;
            }
        }

        return null;
    }

    /**
     * What the status line says under the message: staged, pushed or pulled (with when, with one person), or who
     * in an org has pulled it.
     *
     * @return array{state: string, text: string}
     */
    private function statusOf(Message $message): array
    {
        if ($message->isPending()) {
            return ['state' => $message->status, 'text' => $message->status];
        }

        if ($this->isOrg()) {
            $pulled = array_values(array_filter($this->thread->members ?? [], fn (array $member): bool => $member['user']->id !== $message->userId && $member['read_through_id'] >= $message->id));

            return $pulled === []
                ? ['state' => 'pushed', 'text' => $message->time().' · pushed']
                : ['state' => 'pulled', 'text' => $message->time().' · pulled by '.implode(', ', array_map(fn (array $member): string => $member['user']->handle(), array_slice($pulled, 0, 5))).(count($pulled) > 5 ? ' +'.(count($pulled) - 5) : '')];
        }

        $pulledAt = $this->thread?->partnerReadAt;

        return ($this->thread->partnerReadThroughId ?? 0) >= $message->id
            ? ['state' => 'pulled', 'text' => 'pulled'.($pulledAt === null ? '' : ' '.Display::clock($pulledAt))]
            : ['state' => 'pushed', 'text' => 'pushed'];
    }

    private function authorOf(string $userId): ?User
    {
        if ($this->thread?->partner?->id === $userId) {
            return $this->thread->partner;
        }

        foreach ($this->thread->members ?? [] as $member) {
            if ($member['user']->id === $userId) {
                return $member['user'];
            }
        }

        return $userId === $this->me()?->id ? $this->me() : null;
    }
}
