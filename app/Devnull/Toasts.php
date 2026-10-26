<?php

namespace App\Devnull;

use Victorycodedev\ToastKit\Facades\Toast;
use Victorycodedev\ToastKit\PendingToast;

/**
 * The app's toasts, drawn by ToastKit as native overlays on both phones, in
 * the app's own typefaces (see config/native-ui.php). They come down from the
 * top, so one never covers the composer or the reply bar it may be talking
 * about, and one waits for the one before it to go.
 *
 * A toast that only says something went as asked is neutral, the way the
 * web's are. One that says it didn't is an error, in devnull's words, and can
 * offer a way back: Retry, Edit, View issue. Its button is named by $action
 * and carries $data, and the screen showing when it's pressed hears it (see
 * Concerns\SyncsOutbox::toastActionPressed()).
 */
final class Toasts
{
    /**
     * How long a toast stays, in milliseconds: a quick one, as Android's short
     * toast, and one with more to read, or a button to press.
     */
    public const int Short = 2000;

    public const int Long = 3500;

    public const int WithAction = 5000;

    /**
     * Something went as asked: a commit deleted, a pin moved.
     */
    public static function say(string $message, bool $short = false): string
    {
        return self::styled(Toast::neutral($message))->duration($short ? self::Short : self::Long)->show();
    }

    /**
     * Something didn't go, with why, and a button to try again or go back to it.
     *
     * @param  array{label: string, id: string, data?: array<string, mixed>}|null  $action
     */
    public static function failed(string $message, ?array $action = null): string
    {
        return self::withAction(self::styled(Toast::error($message)), $action)->show();
    }

    /**
     * Something worth knowing that isn't a failure, with a button to follow it up, such as an issue just opened.
     *
     * @param  array{label: string, id: string, data?: array<string, mixed>}|null  $action
     */
    public static function done(string $message, ?array $action = null): string
    {
        return self::withAction(self::styled(Toast::success($message)), $action)->show();
    }

    private static function styled(PendingToast $toast): PendingToast
    {
        return $toast->position('top')->text(font: 'default');
    }

    /**
     * @param  array{label: string, id: string, data?: array<string, mixed>}|null  $action
     */
    private static function withAction(PendingToast $toast, ?array $action): PendingToast
    {
        if ($action === null) {
            return $toast->duration(self::Long);
        }

        return $toast->action($action['label'], $action['id'])->data($action['data'] ?? [])->duration(self::WithAction);
    }
}
