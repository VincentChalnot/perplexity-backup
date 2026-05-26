# Perplexity Backup

A Symfony console application to export and backup your Perplexity conversations.

> **Note:** This is a hack in early development but it works.

## Setup

1. Install dependencies:
   ```bash
   composer install
   ```

2. Add your session cookie to `.env.local`:
   ```
   PERPLEXITY_COOKIE=your_session_cookie_here
   ```

To get your session cookie:
- Open Perplexity in your browser
- Open Developer Tools (F12) → Application → Cookies → https://www.perplexity.ai
- Copy the value of the `ppl_session` cookie

## Usage

Run the commands in sequence:

### 1. Export conversations list

```bash
bin/console app:export-conversations-list
```

Exports all conversations to `var/data/conversations.json`.

### 2. Export individual conversations

```bash
bin/console app:export-individual-conversations
```

Fetches each conversation and saves it as a JSON file in `var/data/conversations/`. Files are named by UUID. Existing conversations are skipped.

### 3. Convert to Markdown

```bash
bin/console app:convert-conversations
```

Converts each JSON file to Markdown format in `var/data/conversations/`. Creates a `.md` file alongside each `.json` file.

### 4. Create Index

```bash
bin/console app:create-index
```

Generates `var/data/00-INDEX.md` with a searchable index of all conversations. The file is prefixed with `00-` to appear at the top of directory listings. Each entry shows:
- Date (YYYY-MM-DD)
- Title (as a link to the Markdown file)
- Collection name (if any)
- Message count

## Output Structure

```
var/data/
├── 00-INDEX.md                      # Index of all conversations
├── conversations.json              # List of all conversations
└── conversations/
    ├── {uuid}.json                 # Individual conversation JSON
    └── {uuid}.md                   # Converted Markdown
```

## Requirements

- PHP 8.4+
- Symfony 7.3
