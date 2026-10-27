<?php

namespace App\Icons;

use Native\Mobile\Icon\AndroidSymbol;
use Native\Mobile\Icon\Icon;

/**
 * The Lucide icons the web draws.
 *
 * Most are Lucide's own drawings. Their SVGs, from lucide-react 1.57.0, are in
 * resources/icons, and the NativePHP build turns each one into a template image
 * on iOS and a VectorDrawable on Android, so both platforms draw the web's
 * strokes, tinted like any other icon. `$filled` is the on state of a star or
 * a bookmark, which the web draws as the same shape with `fill-current`.
 *
 * The rest still map to the closest SF Symbol and outlined Material icon. To
 * draw one of them as the web does, add its SVG to resources/icons and name it
 * in custom().
 */
enum Lucide
{
    case GitCommitVertical;
    case Bell;
    case MessagesSquare;
    case UserRound;
    case Settings2;
    case MessageSquareReply;
    case Link;
    case Star;
    case Bookmark;
    case Trash2;
    case CornerDownRight;
    case ChevronRight;
    case ChevronLeft;
    case ChevronDown;
    case ChevronsUp;
    case ArrowUpRight;
    case ArrowUp;
    case Pin;
    case ListOrdered;
    case Building2;
    case MapPin;
    case CalendarDays;
    /** The web draws GitHub's own mark, which neither system has, so this is the code glyph. */
    case Github;
    case Quote;
    case Check;
    case X;
    case UserPlus;
    case UserCheck;
    case AtSign;
    case FoldVertical;
    case Award;
    case Ellipsis;
    case Flag;
    case RefreshCw;
    case LockKeyhole;
    case VolumeX;
    case Volume2;
    case Ban;
    case Archive;
    case ArchiveRestore;
    case SquarePen;
    case UsersRound;
    case Search;
    case Reply;
    case SmilePlus;
    case Lightbulb;
    case Bug;
    case ImagePlus;
    case Camera;
    case ChartBar;
    case Eye;
    case EyeOff;
    case Plus;
    case Maximize2;

    // The achievement badges, named as devnull names their icons (see forBadge()).
    case Terminal;
    case FileCode;
    case Flame;
    case Zap;
    case Server;
    case Box;
    case Shield;
    case Sparkles;
    case GitMerge;
    case Maximize;
    case Key;
    case PinOff;
    case BellOff;
    case LogOut;

    /**
     * The icon the web's inbox draws for a kind of notification.
     */
    public static function forNotification(string $type): self
    {
        return match ($type) {
            'followed' => self::UserPlus,
            'leveled_up' => self::ChevronsUp,
            'quoted' => self::Quote,
            'starred' => self::Star,
            'mentioned' => self::AtSign,
            'approved' => self::Check,
            'rejected' => self::X,
            default => self::MessageSquareReply,
        };
    }

    /**
     * The icon devnull names for an achievement badge, as the web's shelf maps it, or the terminal for one it doesn't know.
     */
    public static function forBadge(string $icon): self
    {
        return match ($icon) {
            'file-code' => self::FileCode,
            'flame' => self::Flame,
            'zap' => self::Zap,
            'server' => self::Server,
            'box' => self::Box,
            'shield' => self::Shield,
            'star' => self::Star,
            'sparkles' => self::Sparkles,
            'git-merge' => self::GitMerge,
            'maximize' => self::Maximize,
            'key' => self::Key,
            default => self::Terminal,
        };
    }

    /**
     * The `custom:<name>` icon drawn from resources/icons/<name>.svg, or null for a system glyph.
     */
    public function custom(bool $filled = false): ?string
    {
        $name = match ($this) {
            self::GitCommitVertical => 'git-commit-vertical',
            self::Bell => 'bell',
            self::MessagesSquare => 'messages-square',
            self::UserRound => 'user-round',
            self::Settings2 => 'settings-2',
            self::MessageSquareReply => 'message-square-reply',
            self::Link => 'link',
            self::Star => $filled ? 'star-filled' : 'star',
            self::Bookmark => $filled ? 'bookmark-filled' : 'bookmark',
            self::Trash2 => 'trash-2',
            self::ArrowUp => 'arrow-up',
            self::ChevronsUp => 'chevrons-up',
            self::Quote => 'quote',
            self::Check => 'check',
            self::X => 'x',
            self::UserPlus => 'user-plus',
            self::UserCheck => 'user-check',
            self::AtSign => 'at-sign',
            self::FoldVertical => 'fold-vertical',
            self::Award => 'award',
            self::Ellipsis => 'ellipsis',
            self::Flag => 'flag',
            self::RefreshCw => 'refresh-cw',
            self::LockKeyhole => 'lock-keyhole',
            self::VolumeX => 'volume-x',
            self::Volume2 => 'volume-2',
            self::Ban => 'ban',
            self::Archive => 'archive',
            self::ArchiveRestore => 'archive-restore',
            self::SquarePen => 'square-pen',
            self::UsersRound => 'users-round',
            self::Search => 'search',
            self::Reply => 'reply',
            self::SmilePlus => 'smile-plus',
            self::Lightbulb => 'lightbulb',
            self::Bug => 'bug',
            self::ImagePlus => 'image-plus',
            self::Camera => 'camera',
            self::ChartBar => 'chart-bar',
            self::Eye => 'eye',
            self::EyeOff => 'eye-off',
            self::Plus => 'plus',
            self::Maximize2 => 'maximize-2',
            self::Terminal => 'terminal',
            self::FileCode => 'file-code',
            self::Flame => 'flame',
            self::Zap => 'zap',
            self::Server => 'server',
            self::Box => 'box',
            self::Shield => 'shield',
            self::Sparkles => 'sparkles',
            self::GitMerge => 'git-merge',
            self::Maximize => 'maximize',
            self::Key => 'key',
            self::Pin => 'pin',
            self::PinOff => 'pin-off',
            self::BellOff => 'bell-off',
            self::LogOut => 'log-out',
            default => null,
        };

        return $name === null ? null : Icon::custom($name);
    }

    public function ios(bool $filled = false): Ios|string
    {
        return $this->custom($filled) ?? match ($this) {
            self::CornerDownRight => Ios::ArrowTurnDownRight,
            self::ChevronRight => Ios::ChevronRight,
            self::ChevronLeft => Ios::ChevronLeft,
            self::ChevronDown => Ios::ChevronDown,
            self::ArrowUpRight => Ios::ArrowUpRight,
            self::ListOrdered => Ios::ListNumber,
            self::Building2 => Ios::Building2,
            self::MapPin => Ios::Mappin,
            self::CalendarDays => Ios::Calendar,
            self::Github => Ios::ChevronLeftForwardslashChevronRight,
            default => Ios::Circle,
        };
    }

    public function android(bool $filled = false): AndroidSymbol|string
    {
        return $this->custom($filled) ?? match ($this) {
            self::CornerDownRight => AndroidOutlined::SubdirectoryArrowRight,
            self::ChevronRight => AndroidOutlined::ChevronRight,
            self::ChevronLeft => AndroidOutlined::ChevronLeft,
            self::ChevronDown => AndroidOutlined::ExpandMore,
            self::ArrowUpRight => AndroidOutlined::ArrowOutward,
            self::ListOrdered => AndroidOutlined::FormatListNumbered,
            self::Building2 => AndroidOutlined::Business,
            self::MapPin => AndroidOutlined::LocationOn,
            self::CalendarDays => AndroidOutlined::CalendarToday,
            self::Github => AndroidOutlined::Code,
            default => AndroidOutlined::Circle,
        };
    }
}
