# Perplexity API schema (as observed)

Reference for the JSON shape returned by Perplexity's private REST API and consumed by the convert pipeline. Reverse-engineered from real responses; undocumented and subject to change upstream.

## Conversation envelope

Top-level keys on a single-thread response:

```
{
  "background_entries": [],
  "entries": [ ... ],
  "has_next_page": bool,
  "next_cursor": "...",
  "status": "...",
  "thread_metadata": { "created_at": "...", ... }
}
```

The list endpoint returns a flat array of summary objects (`uuid`, `title`, `last_query_datetime`, `collection`, ...). The convert pipeline only consumes single-thread responses.

## Entry

Each element in `entries[]` is one user-question / assistant-answer round and has at least:

| Field | Meaning |
|---|---|
| `query_str` | The user's question text. |
| `display_model` | Model id used to answer (e.g. `claude46sonnetthinking`, `pplx_alpha`). |
| `query_language` | ISO language code (`en`, `fr`, ...). |
| `search_mode` | `SEARCH`, `RESEARCH`, `STUDIO`, `STUDY`. |
| `sources.sources` | Source-type tags (`[web]`, `[web, social]`, ...). |
| `updated_datetime` | Last-update timestamp. |
| `entry_created_datetime` | Creation timestamp (first entry seeds `ConvertContext::$createdAt`). |
| `thread_title` | Thread title (first entry seeds `ConvertContext::$title`). |
| `backend_uuid` / `uuid` | Thread UUID (first entry seeds `ConvertContext::$threadUuid`). |
| `blocks[]` | Content blocks (table below). |

## Blocks

Every block has an `intended_usage` discriminator naming the payload key. Processors look it up and ignore everything else.

| `intended_usage` | Payload key | Purpose |
|---|---|---|
| `plan` | `plan_block.goals[]` | Step titles for the workflow. |
| `ask_text` | `markdown_block.answer` | Primary answer text. |
| `ask_text_0_markdown` | `markdown_block.answer` | First answer variant. |
| `ask_text_N_markdown` | `markdown_block.answer` | Numbered answer variants. |
| `workflow_root` | `workflow_block.steps[]` | Workflow steps + nested items. |
| `web_results` | `web_result_block.web_results[]` | All raw search hits (incl. attachments). |
| `answer_tabs` | `answer_tabs_block.tabs[]` | Alternative answer tabs. |
| `sources_answer_mode` | `sources_mode_block.rows[]` | **Selected citations** — the sources actually cited. |
| `finance_widget` | `widget_block.finance_widget_block` | Financial data widgets. |
| `media_items` | `media_block` | Generated-image metadata. |
| `unified_assets` | `unified_assets_block.assets[]` | Generated images with S3 URLs. |
| `pending_followups` | — | Follow-up question suggestions (currently ignored). |

## `workflow_block` substructure

```
workflow_block.steps[]:
  - title: "Step description"
    items[]:
      - type: WORKFLOW_ITEM_QUERIES
        payload.queries_payload.queries: ["query1", "query2"]
      - type: WORKFLOW_ITEM_SOURCES
        payload.sources_payload.sources: [{ name, url, snippet, meta_data, ... }]
```

## `sources_mode_block` substructure

```
sources_mode_block.rows[]:
  - citation: 1                    # Number referenced in answer as [1]
    status: SELECTED               # SELECTED = actually cited
                                   # REVIEWED = retrieved but not cited
    web_result:
      name: "Source title"
      url: "https://..."
      snippet: "..."
      meta_data:
        citation_domain_name: "example.com"
        description: "..."
        published_date: "2024-01-01T00:00:00"
        images: ["https://..."]
      file_metadata:               # Only for attachments / local files
        num_characters: 12345
      is_attachment: true|false
```

## Critical rules

- **Only `status=SELECTED` rows render as citations.** The `[1]`, `[2]`, ... markers in the answer text map 1:1 to these rows by `citation`. `REVIEWED` rows are search noise.
- **`WORKFLOW_ITEM_SOURCES` are not citations.** They are raw search results from the workflow phase. Do **not** push them into `EntryData::$sources`; they would pollute the YAML metadata and break the numbering.
- **Generated images live in two blocks.** `media_items` provides metadata (uuid, prompt, name, model); `unified_assets` provides the S3 URL. The `GeneratedImageProcessor` joins them.
- **Attachments come from `web_results`, not `sources_mode`.** A block with `intended_usage=web_results` whose entry has `is_attachment=true` feeds `AttachmentProcessor` and contributes to the global `attachments[]`.

## S3 host whitelist

URLs from these three hosts are treated as Perplexity-owned media. `ExportCommandHelper` downloads any URL on these hosts that carries a `AWSAccessKeyId` query param; `ConvertCommandHelper` rewrites their links to local `../../medias/{path}` references.

```
ppl-ai-file-upload.s3.amazonaws.com
ppl-ai-code-interpreter-files.s3.amazonaws.com
user-gen-media-assets.s3.amazonaws.com
```

Changing this list affects both download and rewrite behavior; keep the two consts in sync (`ExportCommandHelper::PERPLEXITY_DOMAINS`, `ConvertCommandHelper::KEEP_DOMAINS`).
