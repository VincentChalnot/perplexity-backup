# Processor extension guide

How to add or modify a block processor in the convert pipeline.

## Mental model

`ConvertCommandHelper::convertConversation()` iterates entries; for each entry it sets `ConvertContext::setCurrentEntryIndex($i)` then runs every tagged processor against the entry. A processor is a pure extractor — read from `$entry`, write to `$context` via the public API. Order across processors must not matter for correctness; the iterator is the Symfony service registration order and is not a contract.

The context holds two scopes:

- **Per-entry** (`EntryData`, one per entry): questions, answers, plan steps, workflow queries, selected sources, widgets, per-entry metadata (language, model, search mode, ...).
- **Global** (on `ConvertContext` itself): generated images and attachments, deduplicated across the whole thread.

Rendering happens later in `ConvertCommandHelper::buildMarkdown()`. Processors never produce Markdown.

## Add a new processor

1. Create `src/Processor/MyThingProcessor.php` implementing `BlockProcessorInterface::process(array $entry, ConvertContext $context): void`.
2. Inside `process()`, scan `$entry['blocks']` for the `intended_usage` you care about. Tolerate missing keys — the schema is upstream-controlled and shifts.
3. Push extracted data through `ConvertContext`'s public mutators (`addQuestion`, `addAnswer`, `addStep`, `addStepQuery`, `addSource`, `addWidget`, `addGeneratedImage`, `addAttachment`, `setEntry*`). Don't write to `EntryData` directly from outside the context.
4. Auto-registration: any class implementing `BlockProcessorInterface` is tagged `app.block_processor` via `_instanceof` in `config/services.yaml`. Do not tag manually. Do not register manually.
5. If the new data must appear in the output, extend `buildMarkdown()` (frontmatter, per-entry `meta1`/`meta2`, global section) or `conversationMeta` in `convertConversation()`. See `docs/agents/output-format.md` for placement rules.
6. Run `bin/console lint:container` and a convert smoke test against a known thread; diff `conversation.md`.

## Existing processors

| Class | Reads | Writes |
|---|---|---|
| `EntryMetadataProcessor` | entry's top-level fields | `setEntryQueryLanguage`, `setEntryUpdatedDatetime`, `setEntryDisplayModel`, `setEntrySourceTypes`, `setEntrySearchMode` |
| `QuestionProcessor` | `query_str` | `addQuestion` |
| `AnswerProcessor` | `ask_text` / `ask_text_0_markdown` / `ask_text_N_markdown` blocks | `addAnswer` (best variant) |
| `PlanProcessor` | `plan` block | `addStep` |
| `WorkflowProcessor` | `workflow_root` block, `WORKFLOW_ITEM_QUERIES` items | `addStepQuery` (auto-creates step if needed) |
| `CitationProcessor` | `sources_answer_mode` rows where `status=SELECTED` | `addSource` |
| `FinanceWidgetProcessor` | `finance_widget` block | `addWidget` |
| `AttachmentProcessor` | `web_results` block entries with `is_attachment=true` | `addAttachment` (global) |
| `GeneratedImageProcessor` | `unified_assets` + `media_items` joined | `addGeneratedImage` (global) |

## Do / don't

- **Do** deduplicate via context methods — `addStep`, `addStepQuery`, `addSource`, `addGeneratedImage`, `addAttachment` already do dedup on the appropriate key. Don't re-implement it.
- **Do** treat absent or empty payloads as no-ops; never throw.
- **Don't** write `WORKFLOW_ITEM_SOURCES` into `addSource()`. Only `sources_answer_mode` rows with `status=SELECTED` are real citations. See `docs/agents/perplexity-schema.md` for the rationale.
- **Don't** access `EntryData` properties directly from a processor. Go through the context's public API so dedup bookkeeping (`seenStepTitles`, `seenSourceUrls`, ...) stays consistent.
- **Don't** read or mutate state across entries. If you need cross-entry aggregation, add a method on `ConvertContext` and route the data through it.
- **Don't** introduce required constructor arguments without a binding — autowiring will fail silently in the tagged iterator otherwise. Prefer no constructor.

## Extending the data model

Need a new YAML metadata field?

1. Add a typed property to `EntryData` (per-entry) or `ConvertContext` (global, with its own dedup map if needed).
2. Add a public mutator to `ConvertContext` mirroring the existing `addX` / `setEntryX` style.
3. Extract in the relevant processor.
4. Render in `buildMarkdown()` — most per-entry fields land in `$meta1` (top: language, timestamp) or `$meta2` (bottom: model, sources, search_mode, steps).
