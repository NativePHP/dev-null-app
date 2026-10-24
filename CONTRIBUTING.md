# Contributing

Thanks for helping out. This covers how to pick something up and what we look for in a pull request.

## Picking something up

The [roadmap](README.md#roadmap) lists the bigger things we want next, but it isn't the only place to find work. The [open issues](https://github.com/NativePHP/dev-null-app/issues) cover bugs and smaller requests. And [/dev/null](https://dev-null.sh) itself keeps changing, so look at what's been [merged there recently](https://github.com/dev-null-sh/dev-null.sh/pulls?q=is%3Apr+is%3Amerged) and bring across anything the app doesn't have yet.

Before you start, check that nobody is already working on it. Search the open issues and pull requests, drafts included. Work in progress is often named differently from the roadmap line, so try a few words for the screen or feature rather than the exact wording.

Then say you're taking it. If there's an issue, comment on it. If there isn't, open one saying what you plan to do. Once you have something to show, open a draft pull request so others can see where it's going.

If someone has claimed an item but nothing has moved for a while, ask on the issue before starting your own version.

## Using AI

You're welcome to use AI tools. The repo's `CLAUDE.md` holds the project's conventions for agents, and `.mcp.json` sets up the Laravel Boost MCP server.

You're still the author, though. Read the whole diff before you open the PR, run the app on a simulator or phone, and be ready to answer questions about the code.

## Keep pull requests small

Keep each PR as small as you can. Ideally it's a single, focused change.

Don't mix a feature with refactoring, reformatting or dependency updates. If you spot something unrelated on the way, leave it out and open an issue or a separate PR for it. If a roadmap item is big, split it into PRs that each make sense on their own.

## Before you open the PR

- Run `php artisan test`.
- Run `vendor/bin/pint --dirty` to format the PHP you changed.
- Build screens with native UI, not web views.
- In the description, say what changed and why. Add a screenshot or recording if the change is visible.
