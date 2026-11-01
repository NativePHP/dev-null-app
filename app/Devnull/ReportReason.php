<?php

namespace App\Devnull;

/**
 * Why a commit or a person is being reported, as the web's Report dialogs ask
 * it. The values are what devnull takes, and the labels are the web's.
 */
enum ReportReason: string
{
    case Spam = 'spam';
    case Harassment = 'harassment';
    case Unsafe = 'unsafe';
    case ChildSafety = 'child_safety';
    case Other = 'other';

    /**
     * The most details a report can carry, as devnull checks it.
     */
    public const int DetailsMaxLength = 500;

    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Spam or misleading links',
            self::Harassment => 'Harassment or hate',
            self::Unsafe => 'Harmful or private information',
            self::ChildSafety => 'Child safety, such as grooming or sexual content involving a minor',
            self::Other => 'Something else',
        };
    }
}
