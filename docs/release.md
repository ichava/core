[← Docs index](../README.md#documentation)

# Release

*Reference.* How a version of `ichava/core` is cut, what the tag triggers, and what the published release carries.

## The tag is the trigger

`.github/workflows/release.yml` runs on `push` to tags only. Nothing about a release is driven by a merge to `main` — a merge runs the gates, a tag publishes. Releasing is therefore a two-step act: land the change through a pull request, then tag the commit that resulted.

```bash
git tag v0.3.3
git push origin v0.3.3
```

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

So the sequence before tagging is: move the entries out of `[Unreleased]` into a new `## [x.y.z] - YYYY-MM-DD` section, merge that through a pull request, then tag the merge commit.

## Versioning, and why `0.x` minors are load-bearing

Core is below `1.0`, where **a caret pins the minor**: `^0.2.8` matches `0.2.9` and does *not* match `0.3.0`. That makes the choice of bump a compatibility decision rather than a label.

- A **security fix** belongs in a patch. Consumers declaring `^0.2.8` receive `0.2.9` without touching their manifests; a `0.3.0` would reach none of them until every consumer bumped and re-released.
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
