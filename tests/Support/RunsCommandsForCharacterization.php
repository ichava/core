<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs an Artisan command and hands back its whole rendered display.
 *
 * PendingCommand's `expectsOutputToContain()` is satisfied by one write at a
 * time, and Laravel Prompts renders a whole table (or a whole intro block) in a
 * single write -- so two substrings from the same table cannot both be
 * asserted through it. The characterization tests need exactly that, so they
 * drive the command through Symfony's CommandTester and assert against the
 * full display instead.
 *
 * Prompts fall back to Symfony questions under the test harness, so answers
 * are fed through `$inputs` in the order the questions are asked, and each
 * question's label lands in the display where it can be asserted verbatim.
 * A question left unanswered silently takes its default, so a test that cares
 * whether a prompt appeared asserts its label, and one that cares it did NOT
 * appear asserts the label's absence.
 */
trait RunsCommandsForCharacterization
{
    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $inputs
     *
     * @return array{0: int, 1: string} exit code and full display
     */
    protected function runCommand(
        string $name,
        array $arguments = [],
        array $inputs = [],
        int $verbosity = OutputInterface::VERBOSITY_NORMAL,
    ): array {
        $command = app(Kernel::class)->all()[$name] ?? null;

        $this->assertNotNull($command, "Command {$name} is not registered.");

        $tester = new CommandTester($command);
        $tester->setInputs($inputs);

        $exit = $tester->execute($arguments, [
            'interactive' => true,
            'verbosity'   => $verbosity,
            'decorated'   => false,
        ]);

        return [$exit, $tester->getDisplay()];
    }

    /**
     * @param list<string> $needles
     */
    protected function assertDisplayContains(string $display, array $needles): void
    {
        $this->assertNotSame([], $needles, 'Assert at least one needle.');

        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $display, "Display is missing: {$needle}");
        }
    }

    /**
     * @param list<string> $needles
     */
    protected function assertDisplayLacks(string $display, array $needles): void
    {
        $this->assertNotSame([], $needles, 'Assert at least one needle.');

        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $display, "Display unexpectedly contains: {$needle}");
        }
    }
}
