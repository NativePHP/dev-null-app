# Screenshots in pull requests

- A pull request that changes anything a user can see needs before and after screenshots on iOS and Android, each in light and dark mode. Anything that moves (animations, transitions, sheets, scroll behaviour) needs recordings as well.
- Activate the `ui-screenshots` skill as soon as you start a change like that. The before shots are taken before you edit anything, so don't leave it until the PR.
- Never commit screenshots or recordings to the pull request branch. `php artisan screenshots:publish` puts them on an orphan branch and prints the table for the description.
- Open pull requests with the sections in `.github/pull_request_template.md`. `gh pr create --body` skips the template, so copy its headings yourself.
- If nothing visible changed, tick "No visible change" in the template instead of leaving the section empty. A CI check fails ready-for-review PRs that touch screens without either.
