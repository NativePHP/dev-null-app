<?php

namespace App\NativeComponents\Concerns;

use App\NativeComponents\Layouts\TabsLayout;

/**
 * Opens commits and profiles inside the tab they were tapped in, and never
 * the screen that's already showing.
 *
 * Native tabs give each tab its own navigation stack, but only push a URI
 * that starts with the tab's own (PerTabNavigationCoordinator.swift). So
 * /log/commits/{id} slides in from the right with the system back gesture,
 * where /commits/{id} would swap the whole tabs screen for a stack screen
 * with no animation.
 *
 * navigate() pushes even when the URI is the current one. The native stack
 * treats that as a re-render of the top level, so the router ends up a copy
 * deeper than what's on screen and back needs a tap per copy. Every commit,
 * profile and mention tap comes through here, so it's checked once.
 */
trait OpensInTab
{
    /**
     * Resume the existing feed instead of mounting another one over it.
     */
    public function returnToLog(): void
    {
        $this->popTo(TabsLayout::Tabs[0]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function openInTab(string $path, array $data = []): void
    {
        $uri = $this->tabRoot().$path;

        if ($this->isShowing($uri)) {
            return;
        }

        $this->navigate($uri, $data);
    }

    /**
     * Whether this screen is already the one at $uri: the same route with the
     * same parameters, whatever the trailing slash.
     */
    protected function isShowing(string $uri): bool
    {
        return self::path($uri) === self::path($this->nativeRouter?->currentUri() ?? '');
    }

    private static function path(string $uri): string
    {
        return '/'.trim((string) parse_url($uri, PHP_URL_PATH), '/');
    }

    /**
     * The tab this screen sits in: the first segment of its URI, or the log.
     */
    protected function tabRoot(): string
    {
        $root = '/'.strtok(ltrim($this->nativeRouter?->currentUri() ?? '', '/'), '/');

        return in_array($root, TabsLayout::Tabs, true) ? $root : TabsLayout::Tabs[0];
    }
}
