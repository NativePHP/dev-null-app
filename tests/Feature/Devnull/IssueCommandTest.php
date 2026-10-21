<?php

use App\Devnull\IssueCommand;

/*
 * The web's own cases for its issue commands (tests/Unit/IssueCommands.test.ts), so the app offers and starts them
 * from the same drafts.
 */

it('suggests commands only while the draft is a slash and the start of a name', function () {
    $names = fn (string $body): array => array_map(fn (IssueCommand $command): string => $command->command(), IssueCommand::suggested($body));

    expect($names('/'))->toBe(['/feature', '/bug'])
        ->and($names('/f'))->toBe(['/feature'])
        ->and($names('/bug'))->toBe(['/bug'])
        ->and($names('/deploy'))->toBe([])
        ->and($names('/bug '))->toBe([])
        ->and($names('Shipped /bug fixes'))->toBe([])
        ->and($names(''))->toBe([]);
});

it('turns a complete command and a space or new line into an issue without the command', function () {
    expect(IssueCommand::typed('/bug '))->toBe([IssueCommand::Bug, ''])
        ->and(IssueCommand::typed('/feature Dark mode'))->toBe([IssueCommand::Feature, 'Dark mode'])
        ->and(IssueCommand::typed("/bug\nSteps"))->toBe([IssueCommand::Bug, 'Steps'])
        ->and(IssueCommand::typed('/bug'))->toBeNull()
        ->and(IssueCommand::typed('/bugs are fixed'))->toBeNull()
        ->and(IssueCommand::typed('Fixed a /bug today'))->toBeNull();
});

it('hints the command a plain draft mentions', function () {
    $name = fn (string $body): ?string => IssueCommand::hinted($body)?->command();

    expect($name('The search has a bug'))->toBe('/bug')
        ->and($name('Bugs everywhere today'))->toBe('/bug')
        ->and($name('Feature idea: dark mode for the CLI'))->toBe('/feature')
        ->and($name('Would love these features'))->toBe('/feature')
        ->and($name('Debugging the parser'))->toBeNull()
        ->and($name('Featured on the front page'))->toBeNull()
        ->and($name('/bug already a command'))->toBeNull()
        ->and($name('Shipped the parser'))->toBeNull();
});

it('words each command as the web does', function () {
    expect(array_map(fn (IssueCommand $command): array => [$command->command(), $command->description(), $command->placeholder(), $command->hint()], IssueCommand::cases()))->toBe([
        ['/feature', 'Request a feature', "# Feature title\n\n## Problem\nWhat is missing?\n\n## Proposal\nHow should it work?", 'Have an idea for /dev/null?'],
        ['/bug', 'Report a bug', "# Bug title\n\n## Steps\n1. How to reproduce it\n\n## Expected vs. actual\nWhat should happen, and what happens instead?", 'Found a bug in /dev/null?'],
    ]);
});
