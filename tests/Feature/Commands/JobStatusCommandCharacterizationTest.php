<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\JobProgressTracker;
use Simtabi\Laranail\Ichava\Tests\Support\RunsCommandsForCharacterization;

/*
|--------------------------------------------------------------------------
| Characterization: `ichava::ichava-core.job-status`
|--------------------------------------------------------------------------
|
| Pins what the command prints and asks TODAY, before the console refactor.
| Progress rows are written through JobProgressTracker, the same API the
| seeding jobs use.
|
*/

uses(RunsCommandsForCharacterization::class);

const JOB_STATUS_COMMAND = 'ichava::ichava-core.job-status';
const JOB_STATUS_PACK = 'ichava/test-icons';

it('reports no progress data for any registered pack', function (): void {
    [$exit, $display] = $this->runCommand(JOB_STATUS_COMMAND);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '📊 Ichava Icon Seeding Job Status',
        'No job progress data found.',
        '💡 Jobs are tracked after running: php artisan ichava::ichava-core.database seed',
    ]);
});

it('reports an empty registry', function (): void {
    app(IconRegistry::class)->unregister(JOB_STATUS_PACK);

    [$exit, $display] = $this->runCommand(JOB_STATUS_COMMAND);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['No icon packages registered.']);
});

it('lists packs without progress under --all, with the summary table', function (): void {
    [$exit, $display] = $this->runCommand(JOB_STATUS_COMMAND, ['--all' => true]);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'Package',
        'Status',
        'Progress',
        'Icons',
        'Updated',
        JOB_STATUS_PACK,
        'No data',
        '📊 Summary:',
        'Metric',
        'Count',
        'Active jobs',
        'Completed',
        'Failed',
        'Total icons in DB',
    ]);
});

it('tabulates a pack with progress', function (): void {
    JobProgressTracker::start(JOB_STATUS_PACK, 10, 'job-1');
    JobProgressTracker::update(JOB_STATUS_PACK, 5);

    [$exit, $display] = $this->runCommand(JOB_STATUS_COMMAND);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        JOB_STATUS_PACK,
        '◉ Processing',
        '50%',
        '5/10',
        '📊 Summary:',
        'Active jobs',
    ]);
});

it('reports no progress data for a single pack', function (): void {
    [$exit, $display] = $this->runCommand(JOB_STATUS_COMMAND, ['package' => JOB_STATUS_PACK]);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '📊 Job Status: ichava/test-icons',
        'No progress data found for: ichava/test-icons',
        '💡 This package may not have been seeded yet, or progress data has expired.',
    ]);
});

it('details a single pack in progress', function (): void {
    JobProgressTracker::start(JOB_STATUS_PACK, 10, 'job-1');
    JobProgressTracker::update(JOB_STATUS_PACK, 5);

    [$exit, $display] = $this->runCommand(JOB_STATUS_COMMAND, ['package' => JOB_STATUS_PACK]);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'Property',
        'Value',
        'Job ID',
        'job-1',
        '◉ Processing',
        'Progress:',
        '50%',
        'Icons: 5 / 10',
        'Started: ',
        'Icons in database: 0',
    ]);
});

it('details a failed pack with its error and exception class', function (): void {
    JobProgressTracker::start(JOB_STATUS_PACK, 10, 'job-1');
    JobProgressTracker::fail(JOB_STATUS_PACK, new RuntimeException('kaput'));

    [$exit, $display] = $this->runCommand(JOB_STATUS_COMMAND, ['package' => JOB_STATUS_PACK]);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['✗ Failed', '✗ kaput', 'Exception: RuntimeException']);
});

it('asks before clearing progress and clears on yes', function (): void {
    JobProgressTracker::start(JOB_STATUS_PACK, 10, 'job-1');

    [$exit, $display] = $this->runCommand(JOB_STATUS_COMMAND, ['--clear' => JOB_STATUS_PACK], ['yes']);

    $this->assertSame(0, $exit);
    $this->assertNull(JobProgressTracker::get(JOB_STATUS_PACK));
    $this->assertDisplayContains($display, [
        "Clear progress data for 'ichava/test-icons'? (yes/no) [no]",
        '✅ Progress cleared for: ichava/test-icons',
    ]);
});

it('keeps progress when clearing is declined', function (): void {
    JobProgressTracker::start(JOB_STATUS_PACK, 10, 'job-1');

    [$exit, $display] = $this->runCommand(JOB_STATUS_COMMAND, ['--clear' => JOB_STATUS_PACK], ['no']);

    $this->assertSame(0, $exit);
    $this->assertNotNull(JobProgressTracker::get(JOB_STATUS_PACK));
    $this->assertDisplayContains($display, ['Operation cancelled.']);
});

it('still asks under --force, and clears even when declined', function (): void {
    // characterization: prompts even under --force; changes in the refactor
    JobProgressTracker::start(JOB_STATUS_PACK, 10, 'job-1');

    [$exit, $display] = $this->runCommand(
        JOB_STATUS_COMMAND,
        ['--clear' => JOB_STATUS_PACK, '--force' => true],
        ['no'],
    );

    $this->assertSame(0, $exit);
    $this->assertNull(JobProgressTracker::get(JOB_STATUS_PACK));
    $this->assertDisplayContains($display, [
        "Clear progress data for 'ichava/test-icons'?",
        '✅ Progress cleared for: ichava/test-icons',
    ]);
});
