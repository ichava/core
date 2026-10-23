# Release

*Reference.* How a version of `ichava/core` is cut, what the tag triggers, and what the published release carries.

## The tag is the trigger

`.github/workflows/release.yml` runs on `push` to `vX.Y.Z` tags. A merge runs the gates; a tag
publishes. The tag is made by [release-please](https://github.com/googleapis/release-please), not by
hand:

1. Every pull request that changes behaviour adds to the one `## [Unreleased]` section of
   `CHANGELOG.md`, and uses a Conventional Commit subject.
2. Run *Release Please* from the Actions tab (it also runs every Monday). It opens or refreshes one
   release pull request that bumps `.release-please-manifest.json`, and closes `[Unreleased]` as
   `## [X.Y.Z] - date` on that branch. It fails when `[Unreleased]` is empty.
3. Merge the release pull request once CI is green. That run tags `vX.Y.Z` and creates the release;
   the tag starts `release.yml`, which publishes the changelog section as the body.

On 0.x a breaking change (`feat!:` or a `BREAKING CHANGE:` footer) bumps the minor and everything
else bumps the patch. The minor moves in lockstep across the coupled ichava packages: when one of
them needs `0.N.0`, every coupled repository gets a pull request carrying a `Release-As: 0.N.0`
footer alongside its constraint change.
Never edit the release pull request by hand: release-please rebuilds its branch and drops anything
it did not write. Change the notes on `main` instead.

If a tag ever starts nothing, run `release.yml` by hand against it:
`gh workflow run release.yml --ref vX.Y.Z`. It refuses any ref that is not a release tag.

Concurrency is grouped per ref with `cancel-in-progress: false`, so two tags pushed together both complete rather than the second cancelling the first.

## What the workflow publishes

| Step | What it does |
|---|---|
| Install runtime dependencies | resolves fresh, without a lock file |
| Generate SBOM | produces `ichava-core.cdx.json` |
| Back off and retry | waits, then retries the SBOM once |
| Note the missing SBOM | emits a `::warning::` if both attempts failed |
| Extract the changelog section | reads this version's `## [x.y.z]` block out of `CHANGELOG.md` |
| Publish | creates the GitHub release with that block as the body |

### The SBOM deliberately cannot fail the release

Both SBOM steps carry `continue-on-error: true`, and the publish step sets `fail_on_unmatched_files: false` rather than relying on the default. That asymmetry is the design, not an oversight:

> A release missing an attachment is repaired by re-running the workflow, which re-attaches it. **A tag with no release behind it persists silently until a person notices.** The second failure is worse, so the first is accepted.

This was not hypothetical. On 2026-09-21 GitHub's release-asset CDN answered `504` for the Syft checksums for roughly twenty minutes, failing four attempts *before* the publish step and leaving a tag with no release behind it.

## The changelog is the release body

The release body is this version's `CHANGELOG.md` section, extracted by the workflow. That makes the changelog load-bearing rather than decorative: **a version with no section produces a release with no description.**

The file follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/), and a `Changelog order` CI job fails a pull request when `[Unreleased]` is not first or the version sections do not descend.

The release pull request does that move: it closes `[Unreleased]` as `## [x.y.z] - YYYY-MM-DD` on its branch, so the section is in place on the commit that gets tagged.

## The 0.1.0 re-base

Versioning was re-based on 2026-10-23, with every other ichava package. The tags `v0.1.0` to
`v0.4.2` (2026-08-31 to 2026-09-26) and their GitHub releases were removed, and `main` was tagged `v0.1.0`. Every
commit those tags pointed at is on `main`, so no code was lost; their notes are kept in
`CHANGELOG.md` under *Archived pre-reset history*. Version numbers in older changelog entries and
issues refer to that earlier series.

## Versioning, and why `0.x` minors are load-bearing

Core is below `1.0`, where **a caret pins the minor**: `^0.1` matches `0.1.1` and does *not* match `0.2.0`. That makes the choice of bump a compatibility decision rather than a label.

- A **security fix** belongs in a patch. Consumers declaring `^0.1` receive `0.1.1` without touching their manifests; a `0.2.0` would reach none of them until every consumer bumped and re-released.
- A **breaking change** takes the minor, and every consumer's constraint has to widen in the same wave.

There is no `version` field in `composer.json` — the version comes from the git tag.

## Consumers do not always need a floor bump

A release is not finished when the tag lands if it changed code a consumer runs. But reaching for a floor bump reflexively is its own mistake: check what actually changed.

```bash
git diff --name-only <prev>..<new> -- src
```

An empty result means no consumed code moved, and the consumers' constraints stay where they are. A security fix under `src/` is the case that does require raising every consumer's floor, in its own change.

## See also

- [Installation](installation.md)
- [Architecture](architecture.md)
- [Artisan commands](tools/artisan-commands.md)

---

[← Docs index](../README.md#documentation)
