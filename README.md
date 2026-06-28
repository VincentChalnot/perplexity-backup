# Perplexity Backup

A Symfony console application to export and back up your Perplexity AI conversations as JSON, downloaded media, and
rendered Markdown.

> **Note:** Early-stage hack, but it works.

## Requirements

- PHP 8.4+
- Composer
- Symfony 7.3 (installed via Composer)

## Setup

1. Install dependencies:
   ```bash
   composer install
   ```

2. Provide your Perplexity session cookie via either:
    - `.env.local`:
      ```
      PERPLEXITY_COOKIE=your_session_cookie_here
      ```
    - or a file at `cookie.txt` in the project root (path overridable with `COOKIE_PATH`).

   To get your session cookie:
    - Open Perplexity in your browser.
    - Open Developer Tools (F12) → Application → Cookies → `https://www.perplexity.ai`.
    - Copy the value of the `ppl_session` cookie.

## Usage

Run the commands in sequence:

### 1. Export conversations list

```bash
bin/console app:conversations:export-list
```

Writes the full conversation list to `var/data/conversations.json`.

### 2. Export individual conversations and media

```bash
bin/console app:conversations:export-all
```

For each conversation in the list, downloads the full JSON plus any associated media (generated images, attachments)
into `var/data/conversations/{uuid}/` and `var/data/medias/`. Existing files are skipped.

Single-thread variant:

```bash
bin/console app:conversations:export-single {uuid}
```

### 3. Convert to Markdown

```bash
bin/console app:conversations:convert
```

Renders each `conversation.json` into `conversation.md` (with a YAML frontmatter + metadata block) and a
`conversation_meta.json` sidecar inside the same `{uuid}` directory.

Single-thread variant:

```bash
bin/console app:conversation:convert {uuid}
```

### 4. Create index

```bash
bin/console app:conversation:create-index
```

Generates `var/data/conversations.md`, a Markdown index grouped by date. Each entry links to the converted conversation
and shows title, optional collection, and message count.

## Output structure

```
var/data/
├── conversations.json              # List of all conversations
├── conversations.md                # Generated index
├── conversations/
│   └── {uuid}/
│       ├── conversation.json       # Raw API response
│       ├── conversation.md         # Rendered Markdown
│       └── conversation_meta.json  # Metadata sidecar (images, attachments)
└── medias/
    ├── index.json                  # Global media registry / dedup
    └── ...                         # Downloaded images and attachments
```

## License

GPL-3.0. See [LICENSE](LICENSE).
