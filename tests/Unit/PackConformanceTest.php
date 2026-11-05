<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\AssertionFailedError;
use Simtabi\Laranail\Ichava\Testing\PackConformance;

/*
 * P-03. Each rule a pack holds is one PackConformance method. Every method is
 * shown passing on a conforming pack root and failing on a broken one, so no
 * pack's thin test can pass on a check that cannot fail.
 */

function conformingPack(): string
{
    $root = sys_get_temp_dir() . '/ichava-conformance-' . bin2hex(random_bytes(4));
    $sha = str_repeat('a', 40);

    File::ensureDirectoryExists("{$root}/src/Providers");
    File::ensureDirectoryExists("{$root}/.github/workflows");
    File::ensureDirectoryExists("{$root}/resources/assets/svg/files");
    File::put("{$root}/composer.json", json_encode(['type' => 'ichava-icon-set', 'require' => ['php' => '^8.4', 'ichava/core' => '^0.1']]));
    File::put("{$root}/phpstan.neon.dist", "parameters:\n    level: max\n");
    File::put("{$root}/src/Providers/IconsServiceProvider.php", "<?php\nuse Simtabi\\Laranail\\Ichava\\Providers\\IconPackServiceProvider;\n");
    File::put("{$root}/.github/workflows/tests.yml", "permissions:\n  contents: read\n");
    File::put("{$root}/.github/workflows/sync-upstream.yml", "on:\n  schedule:\n    - cron: '0 6 * * 1'\n  workflow_dispatch:\npermissions:\n  contents: read\njobs:\n  sync:\n    permissions:\n      contents: read\n      issues: write\n    uses: ichava/icon-sets-sync/.github/workflows/sync-pack.yml@{$sha}\n    with:\n      toolkit-ref: {$sha}\n");
    File::put("{$root}/resources/assets/svg/files/a.svg", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0"/></svg>');

    return $root;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/ichava-conformance-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

it('passes every rule on a conforming pack', function (): void {
    $root = conformingPack();

    PackConformance::requiresOnlyCore($root);
    PackConformance::declaresPackType($root);
    PackConformance::importsOnlyCore($root, minFiles: 1);
    PackConformance::analysesAtLevelMax($root);
    PackConformance::analysesWithoutBaseline($root);
    PackConformance::siblingConstraintsAdmit($root);
    PackConformance::syncCallerIsPinned($root);
    PackConformance::syncCallerTriggers($root);
    PackConformance::syncCallerPermissions($root);
    PackConformance::workflowsDeclarePermissions($root, minFiles: 2);
    PackConformance::noStrayTextNodes("{$root}/resources/assets/svg/files", minFiles: 1);
    PackConformance::svgPolicyClean("{$root}/resources/assets/svg/files", minFiles: 1);

    expect(true)->toBeTrue();
});

it('fails each rule on the defect it guards', function (Closure $break, Closure $check): void {
    $root = conformingPack();
    $break($root);

    expect(fn () => $check($root))->toThrow(AssertionFailedError::class);
})->with([
    'an extra require'         => [fn ($r) => File::put("{$r}/composer.json", json_encode(['type' => 'ichava-icon-set', 'require' => ['php' => '^8.4', 'ichava/core' => '^0.1', 'laranail/console' => '^0.1']])), fn ($r) => PackConformance::requiresOnlyCore($r)],
    'no pack type'             => [fn ($r) => File::put("{$r}/composer.json", json_encode(['require' => ['php' => '^8.4', 'ichava/core' => '^0.1']])), fn ($r) => PackConformance::declaresPackType($r)],
    'a laranail import'        => [fn ($r) => File::put("{$r}/src/X.php", "<?php\nuse Simtabi\\Laranail\\Package\\Tools\\Package;\n"), fn ($r) => PackConformance::importsOnlyCore($r, minFiles: 1)],
    'too few src files'        => [fn ($r) => null, fn ($r) => PackConformance::importsOnlyCore($r, minFiles: 5)],
    'a lower level'            => [fn ($r) => File::put("{$r}/phpstan.neon.dist", "parameters:\n    level: 8\n"), fn ($r) => PackConformance::analysesAtLevelMax($r)],
    'a baseline'               => [fn ($r) => File::put("{$r}/phpstan-baseline.neon", ''), fn ($r) => PackConformance::analysesWithoutBaseline($r)],
    'a floor above 0.1.0'      => [fn ($r) => File::put("{$r}/composer.json", json_encode(['require' => ['ichava/core' => '^0.1.2']])), fn ($r) => PackConformance::siblingConstraintsAdmit($r)],
    'a floating sync pin'      => [fn ($r) => File::put("{$r}/.github/workflows/sync-upstream.yml", str_replace('sync-pack.yml@' . str_repeat('a', 40), 'sync-pack.yml@main', (string) File::get("{$r}/.github/workflows/sync-upstream.yml"))), fn ($r) => PackConformance::syncCallerIsPinned($r)],
    'a push trigger'           => [fn ($r) => File::put("{$r}/.github/workflows/sync-upstream.yml", "on:\n  push:\n" . substr((string) File::get("{$r}/.github/workflows/sync-upstream.yml"), 4)), fn ($r) => PackConformance::syncCallerTriggers($r)],
    'a workflow with no block' => [fn ($r) => File::put("{$r}/.github/workflows/lint.yml", "on: pull_request\n"), fn ($r) => PackConformance::workflowsDeclarePermissions($r, minFiles: 1)],
    'a stray text node'        => [fn ($r) => File::put("{$r}/resources/assets/svg/files/b.svg", '<svg xmlns="http://www.w3.org/2000/svg"><g> d <path d="M0 0"/></g></svg>'), fn ($r) => PackConformance::noStrayTextNodes("{$r}/resources/assets/svg/files", minFiles: 1)],
    'a script'                 => [fn ($r) => File::put("{$r}/resources/assets/svg/files/b.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>x</script></svg>'), fn ($r) => PackConformance::svgPolicyClean("{$r}/resources/assets/svg/files", minFiles: 1)],
    'too few SVGs'             => [fn ($r) => null, fn ($r) => PackConformance::svgPolicyClean("{$r}/resources/assets/svg/files", minFiles: 2)],
]);
