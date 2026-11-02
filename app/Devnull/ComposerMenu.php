<?php

namespace App\Devnull;

use App\Icons\Lucide;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * What the + beside a Remotes thread's message field opens: the web's
 * composer tools, which there sit in a row of their own, in its order, with
 * shorter labels than its tooltips, as a menu's are. Attach photos, until
 * there are three; Record a voice note, while the draft has no text or
 * photos, since voice notes go on their own, which it then says in its
 * place; and Ping.
 */
final class ComposerMenu
{
    /**
     * @return list<TopBarAction>
     */
    public static function items(bool $photosFull, bool $voiceNotesGoAlone, string $pingLabel): array
    {
        return [
            self::item('attach-photos', 'Attach photos', Lucide::ImagePlus, 'pickPhotos', disabled: $photosFull),
            self::item('record-voice-note', $voiceNotesGoAlone ? 'Voice notes go on their own' : 'Record a voice note', Lucide::Mic, 'recordVoiceNote', disabled: $voiceNotesGoAlone),
            self::item('ping', $pingLabel, Lucide::Vibrate, 'ping'),
        ];
    }

    private static function item(string $ref, string $label, Lucide $icon, string $method, bool $disabled = false): TopBarAction
    {
        return NavAction::make($ref)
            ->label($label)
            ->icon(ios: $icon->ios(), android: $icon->android())
            ->disabled($disabled)
            ->press($method)
            ->toElement()
            ->ref($ref);
    }
}
