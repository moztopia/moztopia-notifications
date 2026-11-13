# Moztopia Notifications

## Wiki gate before pushes and main merges

The public GitHub wiki is part of the package's consumer contract. Before
pushing any package branch or preparing/merging a promotion to `main`, review
the complete change against the wiki. Update every affected page for public
APIs, preferences, configuration, schema/migrations, installation/upgrade and
rollback, client integration, operations, compatibility and verification limits.
Keep root README, changelog, versioned guides and wiki consistent.

Develop wiki changes through the wiki Git remote in this checkout's ignored
`wiki/` clone, using its actual default branch. GitHub wikis have no PR surface.
Preserve existing edits, reconcile remote updates, review the diff and verify
examples, links and assets. Publish and verify the wiki commit before pushing
the corresponding package branch. Record the exact wiki commit and validation
on the parent issue and existing PR; include it when opening a new PR. Do not
push the package first and defer wiki work.

For unreleased behavior, clearly label the candidate version and pending
promotion; never describe an unmerged version as released. Recheck publication
state and wiki accuracy immediately before a `main` merge. When unavailable
access or a failed wiki push prevents publication, stop the package push/main
merge and report the blocker. For changes with no wiki impact, explicitly
record the reviewed pages and why no update is needed on the issue and any
existing PR before pushing; silence does not satisfy this gate.

Track wiki work in this repository's single owning Project and bounded issue.
Do not commit the nested wiki checkout, credentials or consumer-specific data.
Preserve applied migrations and immutable tags, and distinguish provider-mocked
tests from real database/queue/device verification.

This is an agent workflow requirement; it is not an automated GitHub branch
protection check. Existing PR, version/tag and human-controlled `main` policies
still apply.
