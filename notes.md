# Recommendations

## Messy

1. **Bug: `countMessages()` path has stray braces.** `CreateIndexCommand.php:67`:
   ```php
   "{{$this->conversationsPath}}/conversations/{$uuid}/conversation.json"
   ```
   Double `{{ }}` makes the path literally `{/path/to/var/data}/conversations/...`. `file_exists()` returns false,
   function always returns `0`. Index has been silently showing "0 messages" for every entry. **Fix immediately.**

2. **Command naming inconsistent.** Mix of singular/plural and verb position:
    - `app:conversations:export-list`
    - `app:conversations:export-all`
    - `app:conversations:export-single`
    - `app:conversations:convert`
    - `app:conversation:convert` (singular!)
    - `app:conversation:create-index` (singular!)

   Pick one scheme. Suggest: `app:export:list`, `app:export:all`, `app:export:one {uuid}`, `app:convert:all`,
   `app:convert:one {uuid}`, `app:index`.

3. **Two export commands, two convert commands.** `ExportConversationCommand` vs `ExportConversationsCommand`, ditto for
   convert. Each pair duplicates orchestration. Collapse into one command per verb with an optional `{uuid}` argument:
   present → single, absent → batch.

4. **README's "Setup" leaks two paths to provide auth** (`.env.local` and `cookie.txt`). Pick one as canonical, document
   the other as fallback. Currently both work and the env var binding in `services.yaml` reads the file, while
   `.env.local` sets an unrelated var that nothing reads (`PERPLEXITY_COOKIE` — grep the code, it's not used).

5. **`var/data/` is both code-managed cache and irreplaceable backup.** Same directory holds throwaway intermediate JSON
   and the user's only copy of media. One `rm -rf var/` clears the cache *and* the backup. Split: `var/data/` for cache,
   `backup/` (configurable) for the durable output.

## Unclear

6. **What "backup" means is undefined.** Is the goal: a) faithful raw archive (JSON+media), b) human-readable Markdown
   corpus, or c) both? README implies (c) but the JSON, MD, and media live tangled together. Document the intent so
   users know what to grep/sync/restore.

7. **No incremental mode.** `export-all` re-walks the full list every run. Skips existing files, but still hits the API
   for the listing and iterates everything. No "only new since last run" flag. Users with thousands of threads will
   notice.

8. **Failure mode on stale cookie is silent.** `PerplexityClient` (presumably) gets a 401/redirect; the command exits 0
   with empty data. Should fail loud with a clear "session expired, refresh cookie" message.

9. **`symfony/ai-bundle` in composer.json is unused.** Either explain the intent in a comment or remove. As-is, anyone
   reading composer.json thinks the project does LLM stuff.

10. **`GEMINI_API_KEY` in env, no code reads it.** Same problem. Remove.

11. **The processor pipeline order matters but isn't enforced or documented in code.** `tagged_iterator` order =
    service-registration order = filesystem glob order. Fragile. If `CitationProcessor` needs `WorkflowProcessor` to
    have run first, use tag `priority` and document it on each class.

## Refactor

12. **`ConvertCommandHelper` is a god class.** Orchestrates processors, owns the YAML dumper, builds Markdown, manages
    media index. Split:
    - `MarkdownRenderer` — pure `EntryData[] → string`.
    - `YamlDumper` — the hand-rolled dumper (or, better, delete it and use `symfony/yaml` which is already required; the
      `isSequentialArray` vs `isScalarArray` footgun goes away).
    - `MediaIndex` — load/save/dedup `medias/index.json`.

13. **Replace the custom YAML dumper with `symfony/yaml`.** It's in `composer.json` already. The footgun documented in
    AGENTS.md only exists because of the custom dumper. Less code, no footgun.

14. **`ExportCommandHelper::fetchMedias()` recursive walk.** Walks the entire JSON looking for `AWSAccessKeyId`
    substrings. Fragile (any schema change in a nested field could miss URLs) and slow on large responses. Better: have
    processors declare media URLs they see (they already parse the blocks), then download from a flat list.

15. **`ConvertContext` mixes per-entry state and global state.** Per-entry `EntryData` is conceptually different from
    global `attachments[]` / `generatedImages[]` / media registries. Two objects: `EntryContext` (scoped) and
    `ConversionContext` (global).

16. **Processors return nothing, mutate context.** Hard to test. Make them pure:
    `process(array $entry): EntryContribution`. Helper merges. This also makes ordering questions vanish.

17. **No DTOs.** Everything is `array`. `$entry['blocks']`, `$block['intended_usage']`,
    `$block['markdown_block']['answer']`. PHP 8.4 readonly classes are cheap. Wrap the API response.

18. **Commands construct paths inline.** Both `CreateIndexCommand` and others build
    `"{$this->conversationsPath}/conversations/{$uuid}/conversation.json"` by hand. Centralize in a `BackupPaths`
    service. Eliminates the brace-bug class of error (#1).

## Remove / simplify

19. **Drop `symfony/ai-bundle`, `GEMINI_API_KEY`, `APP_SECRET`** (no sessions, no CSRF, no auth — console app),
    `public/` directory (no HTTP), `config/routes*` (no controllers), `src/Controller/` (empty).

20. **Drop `composer.json` post-install `assets:install %PUBLIC_DIR%`.** No public assets.

21. **Drop `framework-bundle` overhead you don't use** — actually keep it, it's the autowiring + console glue. But
    review `config/packages/*` and prune anything HTTP-shaped.

22. **The `Helper/` namespace is a code smell** ("Helper" = "I couldn't name it"). Rename `ExportCommandHelper` →
    `ConversationExporter`, `ConvertCommandHelper` → `ConversationConverter`. Move under `src/Export/` and
    `src/Convert/`.

## Make it look professional

23. **README sections to add:**
    - Screenshot or short asciinema of a generated `conversation.md` so people see the output before installing.
    - "Why" paragraph: Perplexity has no official export; this is your only path to durable backup.
    - Troubleshooting: stale cookie, rate limits, missing media.
    - "Roadmap / known limits" so the "early hack" caveat is concrete instead of apologetic.

24. **Add a `Makefile` or `composer` scripts:**
    ```json
    "scripts": {
      "backup": ["@export-list", "@export-all", "@convert", "@index"],
      "export-list": "bin/console app:conversations:export-list",
      ...
    }
    ```
    Users run `composer backup`. One verb.

25. **Add minimal CI.** GitHub Actions:
    - `composer install`
    - `bin/console lint:container`
    - `bin/console lint:yaml config/`
    - `vendor/bin/phpstan analyse` (add level 6)
      Green badge on the README signals "this is maintained."

26. **Add PHPStan.** `phpstan/phpstan` + `phpstan.neon` at level 6. Will immediately catch the `countMessages()` brace
    bug (well, no — that's a runtime path issue. But it'll catch the next class of bug.)

27. **Add a tiny test suite.** Even three tests buy a lot:
    - One full conversion against a fixture JSON checked into `tests/fixtures/`.
    - One YAML dump test.
    - One media-URL detection test.
      Run on every commit. The fixture also doubles as documentation of the API shape.

28. **Add a `--dry-run` flag** to every command that writes. Lets users preview before clobbering. Especially important
    given `var/data/` is precious (Risk flag in AGENTS.md).

29. **Add progress bars.** `SymfonyStyle::progressIterate()`. Free, looks professional, makes large exports tolerable.

30. **Add a `LICENSE` header** to each PHP file or at least a `.editorconfig` + `php-cs-fixer` config so contributions
    stay consistent.

31. **Publish a single example `conversation.md`** under `docs/examples/`. Best advertisement.

32. **Rename the project's `composer.json` `"type": "project"` is fine, but `"license": "proprietary"`** while the repo
    says GPL-3.0. Fix to `"license": "GPL-3.0-or-later"`.

33. **Tag a `v0.1.0` release** once the brace bug is fixed and CI is green. "It works and it's tagged" >> "early hack."

## Bonus: things I'd want as a user

34. **Resumable export.** Crash on thread 847/2000 → next run continues from 847, not 0.
35. **Diff mode.** "What's new since last backup?" prints a list of new/changed thread UUIDs.
36. **Search.** `bin/console app:search "query"` greps converted Markdown and prints hits with thread links. Trivial to
    add; huge UX win because it's the actual use-case ("I had a conversation about X six months ago").
37. **Export a single thread by URL**, not just UUID. Copy-paste from browser, done.

---

## Priority order if you only do five

1. **Fix the `countMessages()` brace bug** (#1) — actual data corruption in the index.
2. **Collapse the duplicate single/batch commands** (#3) — halves the CLI surface.
3. **Replace custom YAML dumper with `symfony/yaml`** (#13) — removes a documented footgun.
4. **Split `var/data/` cache vs durable backup** (#5) — protects the user from the risk flag in AGENTS.md.
5. **Add fixture-based tests + CI + PHPStan** (#25–27) — turns "hack" into "small, maintained tool."

---

