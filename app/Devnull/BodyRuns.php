<?php

namespace App\Devnull;

use App\Devnull\Data\BodyBlock;
use App\Devnull\Data\Commit;
use Devnull\CommitRow\ColorPair;
use Devnull\CommitRow\Run;

/**
 * A commit's paragraphs as the native commit row's runs, styled as
 * partials/commit-body draws them with nested text: mentions in semibold
 * Commit Blue, inline code in mono on a wash, links in Commit Blue (a named
 * one in mono with its owner muted), and the wordmark in tight semibold with
 * its middle slash regular in lane-0. Where commit-body nests text, each piece
 * here is a run of its own with the style it ends up with.
 *
 * Code blocks aren't paragraphs: a body with one is drawn by the Blade row.
 */
final class BodyRuns
{
    /**
     * @param  list<BodyBlock>  $blocks
     * @return list<list<Run>>
     */
    public static function for(Commit $commit, array $blocks): array
    {
        $primary = ColorPair::theme('primary');
        $muted = ColorPair::theme('ink-muted');
        $linkIndex = 0;
        $paragraphs = [];

        foreach ($blocks as $block) {
            $runs = [];

            foreach ($block->runs as $index => $run) {
                if ($run->isMention()) {
                    $runs[] = new Run($run->text, font: 'semibold', weight: 5, color: $primary, press: "openProfile('{$run->username}')");
                } elseif ($run->isCode) {
                    $runs[] = new Run($run->text, font: 'mono', size: 13, background: ColorPair::theme('wash'));
                } elseif ($run->link?->isNamed()) {
                    $press = "openLink('{$commit->id}', ".$linkIndex++.')';
                    $runs[] = new Run($run->link->prefix, font: 'mono', size: 13, color: $muted, press: $press);
                    $runs[] = new Run("\u{2060}", font: 'mono', size: 13, color: $primary, press: $press);
                    $runs[] = new Run($run->link->shown, font: 'mono', size: 13, color: $primary, press: $press);
                } elseif ($run->link !== null) {
                    $runs[] = new Run($run->link->shown, color: $primary, press: "openLink('{$commit->id}', ".$linkIndex++.')');
                } elseif ($run->isWordmark) {
                    $afterSpace = $index > 0 && ctype_space(substr($block->runs[$index - 1]->text, -1));
                    $runs[] = new Run(($afterSpace ? "\u{200B}" : '')."/\u{2060}dev", font: 'semibold', weight: 5, tracking: -0.025);
                    $runs[] = new Run('/', font: 'default', weight: 3, color: ColorPair::theme('lane-0'), tracking: -0.025);
                    $runs[] = new Run("\u{2060}null", font: 'semibold', weight: 5, tracking: -0.025);
                } else {
                    $runs[] = new Run($run->text);
                }
            }

            $paragraphs[] = $runs;
        }

        return $paragraphs;
    }
}
