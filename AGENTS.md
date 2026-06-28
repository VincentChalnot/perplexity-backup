# AGENTS.md — Perplexity Backup

Symfony 7.3 / PHP 8.4 console app that mirrors Perplexity AI conversations (JSON + media) to local disk and renders them
as Markdown with YAML metadata.

## Commands

```bash
composer install
bin/console app:conversations:export-list    # fetch list → var/data/conversations.json
bin/console app:conversations:export-all     # fetch each thread + media → var/data/conversations/{uuid}/
bin/console app:conversations:convert        # JSON → conversation.md
bin/console app:conversation:create-index    # build top-level index
bin/console app:conversations:export-single {uuid}
bin/console app:conversation:convert {uuid}
```

Auth: cookie file at `cookie.txt` (path via `COOKIE_PATH` env). `.env.local` may hold `PERPLEXITY_COOKIE`.

## Verification

No test suite yet. No PHPStan/PHPCS configured. Use:

```bash
bin/console lint:container
bin/console lint:yaml config/
php -l <file>
```

Smoke test: run `app:conversation:convert {uuid}` on an already-exported thread and diff the resulting
`conversation.md`.

## Architecture

- **Client** — single HTTP client wrapping Perplexity's private REST API.
- **Command** — thin Symfony commands; delegate to Helpers.
- **Helper** — orchestrators: one for export (HTTP + media download), one for convert (JSON → MD).
- **Processor** — pluggable extractors, one per block type in the API response, tagged `app.block_processor`.
  ConvertCommandHelper receives them as a `tagged_iterator` and runs every processor against every entry.
- **ConvertContext** — accumulator passed through the processor chain; holds per-entry `EntryData` plus global media
  registries.
- **Output** per thread under `var/data/conversations/{uuid}/`: `conversation.json` (raw), `conversation.md` (rendered),
  `conversation_meta.json` (sidecar). Global `var/data/medias/index.json` deduplicates downloaded files.

Full pipeline + block schema: see [docs/agents/perplexity-schema.md](docs/agents/perplexity-schema.md).
Processor extension guide: see [docs/agents/processors.md](docs/agents/processors.md).
Markdown output spec: see [docs/agents/output-format.md](docs/agents/output-format.md).

## Symfony conventions in this repo

- Autowire + autoconfigure on. All `src/` classes auto-registered.
- `BlockProcessorInterface` and `ConvertContext` excluded from auto-registration.
- Processors tagged via `_instanceof`; do not tag manually.
- Constructor parameter bindings (see `config/services.yaml`):
    - `$conversationsPath` → `%kernel.project_dir%/var/data`
    - `$cookie` → trimmed contents of `COOKIE_PATH` file
- No controllers, no Doctrine, no Messenger, no EventDispatcher use. Commands use `AsCommand` attribute.
- `symfony/ai-bundle` is declared in composer but currently unused — do not introduce dependencies on it without
  confirmation.

## Risk flags

- `var/data/` holds the user's irreplaceable backup. Never delete, truncate, or rewrite in bulk. New code that writes
  there must be idempotent and skip-if-exists.
- `cookie.txt` and `.env.local` contain session credentials. Never log, echo, or commit.
- `ConvertCommandHelper` contains a hand-rolled YAML dumper (not `symfony/yaml`). When touching it: check
  `isSequentialArray` **before** `isScalarArray`, else associative source maps render as flat lists.
- `ExportCommandHelper::fetchMedias()` walks the entire response recursively and downloads any signed S3 URL it sees.
  Changing the URL detection heuristic risks missing media or hammering S3.

## Agent workflow (before editing)

1. Read the target class plus its direct collaborators (Helper ↔ Processor ↔ Context).
2. If adding a block type, check `docs/agents/perplexity-schema.md` for the `intended_usage` and confirm no existing
   processor handles it.
3. Inspect `config/services.yaml` for binding/tag implications.
4. After edit: `bin/console lint:container && bin/console lint:yaml config/`.
5. Run a convert against a known thread under `var/data/conversations/` and diff the output.
