<?php

namespace App\NativeComponents;

use App\Devnull\Client;
use App\Devnull\Data\DraftImage;
use App\Devnull\Data\Guild as GuildData;
use App\Devnull\Data\GuildSaved;
use App\Devnull\DraftImages;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\InvalidDraftImage;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\Exceptions\Unauthenticated;
use App\Devnull\ReactionEmoji;
use App\Devnull\Toasts;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\TalksToDevnull;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Facades\Camera;

/**
 * The web's Create a guild dialog as a screen: a display name, whose letters,
 * numbers and underscores make the guild's hashtag, shown above it as you
 * type, what it's about, and its icon, an emoji from the picker or an image
 * from your photos. Create guild rides above the keyboard in the bottom bar,
 * with devnull's reason beside it when it says no, as New org's does. The new
 * guild opens in this one's place, or, when devnull's moderation holds it for
 * an admin, a toast says so and the screen closes.
 *
 * Opened at ".../guilds/{handle}/edit" from the guild's ⋯ Edit guild, it's
 * the web's Edit guild for the moderator instead: filled in from the guild,
 * its hashtag fixed, so the name has to keep making it. Save changes goes
 * back to the guild, which shows the change when it asks devnull again.
 */
class GuildForm extends NativeComponent
{
    use OpensInTab;
    use TalksToDevnull;

    public const int NameMaxLength = 60;

    public const int DescriptionMaxLength = 500;

    /**
     * The biggest icon devnull takes: 2 MB.
     */
    public const int IconMaxBytes = 2 * 1024 * 1024;

    public const string DefaultEmoji = '💻';

    public const string IconTooBig = 'Choose an image up to 2 MB.';

    public const string EmojiRefused = 'Choose another emoji.';

    public const string NameTooLong = 'The name must not be greater than 60 characters.';

    public const string HashtagRefused = 'Use a name whose hashtag starts with a letter.';

    public const string HashtagChanged = "Guild display name doesn't match hashtag";

    public string $name = '';

    public string $description = '';

    public string $emoji = self::DefaultEmoji;

    /**
     * The image picked to upload as the icon, in place of the emoji.
     */
    public ?DraftImage $icon = null;

    /**
     * The guild's uploaded icon, while editing one that has it.
     */
    public ?string $iconUrl = null;

    /**
     * The uploaded icon comes off, back to the emoji, once an emoji is chosen.
     */
    public bool $removeIcon = false;

    public bool $saving = false;

    public bool $emojiPickerOpen = false;

    /**
     * Why the guild wasn't saved, in the bottom bar beside the button.
     */
    public ?string $formError = null;

    /**
     * Editing: what the guild's page showed, passed along by its Edit guild.
     */
    public function mount(): void
    {
        if (! $this->isEditing()) {
            return;
        }

        $this->name = (string) $this->data('name', '');
        $this->description = (string) $this->data('description', '');
        $this->emoji = (string) $this->data('emoji', '') ?: self::DefaultEmoji;
        $iconUrl = $this->data('iconUrl');
        $this->iconUrl = is_string($iconUrl) && $iconUrl !== '' ? $iconUrl : null;
    }

    public function navTitle(): string
    {
        return $this->isEditing() ? 'Edit guild' : 'Create a guild';
    }

    /**
     * Whether this edits a guild, opened at its handle, rather than creating one.
     */
    public function isEditing(): bool
    {
        return $this->editedHandle() !== '';
    }

    /**
     * The hashtag the name makes, as the web makes it: its letters, numbers and underscores, lowercased.
     */
    public function hashtag(): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{M}\p{N}_]+/u', '', $this->name));
    }

    public function openEmojiPicker(): void
    {
        $this->emojiPickerOpen = true;
    }

    /**
     * An emoji from the picker becomes the icon, in place of any picked image. One devnull doesn't know is refused.
     */
    public function emojiPicked(string $emoji): void
    {
        $this->emojiPickerOpen = false;
        $known = ReactionEmoji::forDevnull($emoji);

        if ($known === null) {
            $this->formError = self::EmojiRefused;

            return;
        }

        $this->emoji = $known;
        $this->formError = null;
        $this->removeIcon = true;
        $this->dropIcon();
    }

    public function emojiPickerClosed(): void
    {
        $this->emojiPickerOpen = false;
    }

    /**
     * Upload image: one photo from the library.
     */
    public function pickIcon(): void
    {
        Camera::pickImages('images', false, 1)->start();
    }

    /**
     * The photo the library handed back becomes the icon, unless devnull wouldn't take it: over 2 MB, or not a
     * JPEG, PNG or WebP.
     *
     * @param  list<mixed>  $files
     */
    #[On(MediaSelected::class)]
    public function photosPicked(array $files = []): void
    {
        $path = is_array($files[0] ?? null) && is_string($files[0]['path'] ?? null) ? $files[0]['path'] : '';

        // The picker was cancelled, or handed back nothing: the icon stays as it was, with nothing to say.
        if ($path === '') {
            return;
        }

        try {
            $image = app(DraftImages::class)->keep($path);
        } catch (InvalidDraftImage) {
            $this->formError = self::IconTooBig;

            return;
        }

        if ((int) filesize($image->path) > self::IconMaxBytes) {
            app(DraftImages::class)->discard([$image]);
            $this->formError = self::IconTooBig;

            return;
        }

        $this->dropIcon();
        $this->icon = $image;
        $this->formError = null;
    }

    /**
     * Create guild: checked here first as the web checks it, then sent once.
     */
    public function save(): void
    {
        if ($this->saving || trim($this->name) === '' || trim($this->description) === '') {
            return;
        }

        $this->formError = $this->refusal();

        if ($this->formError !== null) {
            return;
        }

        $this->saving = true;

        try {
            $saved = $this->isEditing()
                ? app(Client::class)->updateGuild($this->editedHandle(), trim($this->name), trim($this->description), $this->emoji, $this->removeIcon && $this->icon === null, $this->icon)
                : app(Client::class)->createGuild(trim($this->name), trim($this->description), $this->emoji, $this->icon);
        } catch (Unauthenticated $exception) {
            $this->signOut($exception);

            return;
        } catch (ApiException $exception) {
            // No longer this guild's moderator, or no such guild any more: nothing here to fix, so back to it.
            if ($this->isEditing() && $exception->status === 404) {
                Toasts::failed($exception->firstError());
                $this->back();

                return;
            }

            $this->formError = $exception->firstError();
            $this->saving = false;

            return;
        } catch (KeychainUnavailable $exception) {
            $this->formError = $exception->getMessage();
            $this->saving = false;

            return;
        }

        $this->dropIcon();
        $this->saved($saved);
    }

    public function render(): View
    {
        $hashtag = $this->isEditing() ? $this->editedHandle() : $this->hashtag();

        return view('native.guild-form', [
            'hashtag' => $hashtag,
            'isEditing' => $this->isEditing(),
            'mark' => new GuildData(handle: $hashtag, name: $this->name, emoji: $this->emoji, iconUrl: $this->icon?->path ?? ($this->removeIcon ? null : $this->iconUrl)),
            'myColour' => $this->myColour(),
        ]);
    }

    /**
     * Why the web wouldn't send this name, or null when it would.
     */
    private function refusal(): ?string
    {
        if (mb_strlen(trim($this->name)) > self::NameMaxLength) {
            return self::NameTooLong;
        }

        if ($this->isEditing() && $this->hashtag() !== mb_strtolower($this->editedHandle())) {
            return self::HashtagChanged;
        }

        if (preg_match('/^\p{L}[\p{L}\p{M}\p{N}_]{0,59}$/u', $this->hashtag()) !== 1) {
            return self::HashtagRefused;
        }

        return null;
    }

    /**
     * The new guild opens in this screen's place, or a held one says so and the screen goes.
     */
    private function saved(GuildSaved $saved): void
    {
        if ($saved->isHeld()) {
            Toasts::say((string) $saved->heldMessage);
        }

        if ($this->isEditing() || $saved->guild === null) {
            $this->back();

            return;
        }

        $this->replace($this->tabRoot().'/guilds/'.rawurlencode($saved->guild->handle));
    }

    private function editedHandle(): string
    {
        return (string) $this->param('handle', '');
    }

    /**
     * The picked image comes off the form, and off the phone.
     */
    private function dropIcon(): void
    {
        if ($this->icon !== null) {
            app(DraftImages::class)->discard([$this->icon]);
            $this->icon = null;
        }
    }
}
