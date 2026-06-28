# Markdown output format

Spec for the files produced by `app:conversations:convert` per thread. The convert pipeline is judged correct iff the output matches this contract for known threads.

## Files per thread

Under `var/data/conversations/{uuid}/`:

| File | Producer | Contents |
|---|---|---|
| `conversation.json` | export command | Raw API response, untouched. Source of truth. |
| `conversation.md` | convert command | Rendered Markdown (this doc). |
| `conversation_meta.json` | convert command | Sidecar with `thread_uuid`, `title`, `generated_images[]`, `attachments[]`. |

Global, under `var/data/medias/`:

| File | Contents |
|---|---|
| `index.json` | Map keyed by S3 path; entries dedup across all threads with `source_threads[]`, `url_signed`, `expires_at`, `type`, ... See schema below. |
| `{s3_path}` | Actual downloaded binary (image, file, ...). |

## `conversation.md` layout

```
---
thread_uuid: <uuid>
model: <display_model of first entry>
created: <entry_created_datetime of first entry>
source_types: [<union of all entries' source types>]   # if any
---

<for each entry, separated by `\n---\n\n` between entries>

  ```yaml
  query_language: <lang>
  updated_datetime: '<ts>'
  ```

  <question text>

  ```yaml
  display_model: <model>
  sources: [<source types>]
  search_mode: <SEARCH|RESEARCH|STUDIO|STUDY>
  steps:
    - title: <step title>
      queries: [<workflow queries>]
      sources:                          # only on the LAST step, keyed by citation number
        1: { name, snippet, url, domain?, description?, published_date?, images?, num_characters? }
        2: { ... }
  ```

  <answer text, with [1][2]... citation markers>

  <optional widget tables — e.g. ## Finance>

</for each entry>

## Generated images           # if any (global)
- **<name>**
  - uuid: <uuid>
  - prompt: <prompt>
  - model: <model>
  - thumbnail: ![<name>](../../medias/<s3_path>)
  - signed: medias/index.json → `<s3_path>` (Expires: <YYYY-MM-DD HH:MM>)   # if still signed

## Attachments                 # if any (global)
- **[<name>](../../medias/<s3_key>)** (<n> chars)
  snippet: "<first 240 chars>..."

## Local media manifest        # if any media
See [medias/index.json](../../medias/index.json)
```

## Placement rules

- **Frontmatter** is global to the thread. `model` and `created` come from the **first entry**, not from `thread_metadata` (verified in `ConvertContext::__construct`).
- **Per-entry `meta1`** (`query_language`, `updated_datetime`) renders *before* the question.
- **Per-entry `meta2`** (`display_model`, `sources`, `search_mode`, `steps`) renders *after* the question and *before* the answer.
- **Sources attach to the last step.** If the entry has citations but no steps, an empty step is synthesized to host them. Numbers are the `citation` field from `sources_answer_mode`, used as the map key — they must match `[N]` markers in the answer verbatim.
- **Global sections** (`Generated images`, `Attachments`, `Local media manifest`) render once at the end, after all entries.
- **Entry separator** is a Markdown horizontal rule `---` on its own line between entries, never before the first or after the last.

## Source rendering rules

In the `sources:` map under a step, each source object includes only the fields it actually has (no nulls, no empty strings). URL handling:

- **Web source**: keep the `url` as-is and add `domain` if present.
- **Attachment** (`is_attachment=true`) on a whitelisted S3 host: rewrite `url` to `../../medias/{s3-key-from-path}` and **drop the `domain` field** (the S3 hostname is noise). Include `num_characters` if present.

The whitelist is the three hosts in `ConvertCommandHelper::KEEP_DOMAINS` — see `docs/agents/perplexity-schema.md` for the full list.

## `conversation_meta.json` schema

```json
{
  "thread_uuid": "<uuid>",
  "title": "<thread title>",
  "generated_images": [
    { "s3_path": "...", "uuid": "...", "name": "...", "prompt": "...", "model": "..." }
  ],
  "attachments": [
    { "s3_key": "...", "name": "...", "num_characters": 12345 }
  ]
}
```

Written atomically via `writeJsonAtomic()` (tmp file + rename).

## `medias/index.json` schema

```json
{
  "<s3_path_or_key>": {
    "local_path": "medias/<s3_path_or_key>",
    "first_seen": "<created_at>",
    "last_seen": "<created_at>",
    "source_threads": ["<uuid>", ...],
    "url_signed": "<https://... | null>",
    "expires_at": <epoch_seconds | null>,
    "size_bytes": null,
    "sha256": null,
    "type": "generated_image | attachment",
    "name": "<display name>"
  }
}
```

Merge rules (`mergeMediaIndex`): on key collision, union `source_threads`, take the newer `last_seen`, overwrite `url_signed` and `expires_at` if the new entry has them, keep everything else.

## YAML dumper rules

`ConvertCommandHelper` ships its own dumper (`dumpYamlBlock`, `dumpYamlListItem`, `yamlScalar`). Critical detail:

- `isSequentialArray()` must be checked **before** `isScalarArray()`. Otherwise an associative map of scalars (such as the `sources: { 1: {...}, 2: {...} }` block) gets misclassified and rendered as a flat list, silently corrupting the output.
- Strings containing YAML special chars (`:{}[],&*?|>!%@`` ` ``'"\\` `#`) or matching reserved words (`true`, `false`, `null`, `yes`, `no`) are single-quoted with `''` escaping.
- Empty arrays inside list items are skipped (`dumpYamlListItem`).

If you change the dumper, run a convert against a thread with citations and verify the `sources:` block is a map (`1:`, `2:` keys), not a sequence (`-` items).

## Idempotency

The convert command may be run repeatedly. It must produce byte-identical output for the same input, and must merge — not clobber — `medias/index.json`. New writes go to a tmp file and `rename()` into place.
