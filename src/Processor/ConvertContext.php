<?php
declare(strict_types=1);

namespace App\Processor;

/**
 * Per-entry data container for conversation conversion.
 */
class EntryData
{
    public string $queryLanguage = '';
    public string $updatedDatetime = '';
    public string $displayModel = '';
    /** @var string[] */
    public array $sourceTypes = [];
    public string $searchMode = '';

    public array $questions = [];
    public array $answers = [];
    /** @var array{title: string, queries: string[]}[] */
    public array $steps = [];
    /** @var array[] Selected sources (from sources_answer_mode, status=SELECTED) */
    public array $sources = [];
    public array $generatedImages = [];
    public array $attachments = [];
    public array $widgets = [];

    /** @var array<string, true> */
    public array $seenStepTitles = [];
    /** @var array<string, int> title→index map */
    public array $stepIndexByTitle = [];
    /** @var array<string, true> */
    public array $seenSourceUrls = [];
}

class ConvertContext
{
    public readonly string $title;
    public readonly string $threadUuid;
    public readonly string $model;
    public readonly string $createdAt;

    /** @var EntryData[] */
    public array $entries = [];

    /** @var array[] */
    public array $generatedImages = [];
    /** @var array[] */
    public array $attachments = [];

    /** @var array<string, true> */
    private array $seenGlobalImagePaths = [];
    /** @var array<string, true> */
    private array $seenGlobalAttachmentKeys = [];

    private int $currentIndex = -1;

    /**
     * @param array<string, mixed> $conversation
     */
    public function __construct(array $conversation)
    {
        $entries = $conversation['entries'] ?? [$conversation];
        $first = $entries[0] ?? [];

        $this->title = $first['thread_title'] ?? '';
        $this->threadUuid = $first['backend_uuid'] ?? $first['uuid'] ?? 'unknown';
        $this->model = $first['display_model'] ?? '';
        $this->createdAt = $first['entry_created_datetime']
            ?? ($conversation['thread_metadata']['created_at'] ?? '')
            ?? ($conversation['thread_metadata']['createdat'] ?? '');

        foreach ($entries as $entry) {
            $this->entries[] = new EntryData();
        }
    }

    public function setCurrentEntryIndex(int $index): void
    {
        $this->currentIndex = $index;
    }

    private function currentEntry(): EntryData
    {
        return $this->entries[$this->currentIndex];
    }

    /**
     * @return string[]
     */
    public function getAllSourceTypes(): array
    {
        $types = [];
        foreach ($this->entries as $entry) {
            foreach ($entry->sourceTypes as $t) {
                $types[$t] = true;
            }
        }
        return array_keys($types);
    }

    // ─── Per-entry metadata ───────────────────────────────────────

    public function setEntryQueryLanguage(string $lang): void
    {
        $this->currentEntry()->queryLanguage = $lang;
    }

    public function setEntryUpdatedDatetime(string $dt): void
    {
        $this->currentEntry()->updatedDatetime = $dt;
    }

    public function setEntryDisplayModel(string $model): void
    {
        $this->currentEntry()->displayModel = $model;
    }

    /**
     * @param string[] $types
     */
    public function setEntrySourceTypes(array $types): void
    {
        $this->currentEntry()->sourceTypes = $types;
    }

    public function setEntrySearchMode(string $mode): void
    {
        $this->currentEntry()->searchMode = $mode;
    }

    // ─── Per-entry content ────────────────────────────────────────

    public function addQuestion(string $query): void
    {
        if ($query !== '') {
            $this->currentEntry()->questions[] = $query;
        }
    }

    public function addAnswer(?string $answer): void
    {
        if ($answer !== null && $answer !== '') {
            $this->currentEntry()->answers[] = $answer;
        }
    }

    /**
     * Add a plan step (title from plan_block goal).
     * If a step with the same title already exists, it's a no-op.
     */
    public function addStep(string $title): void
    {
        if ($title === '') {
            return;
        }
        $entry = $this->currentEntry();
        if (isset($entry->seenStepTitles[$title])) {
            return;
        }
        $entry->seenStepTitles[$title] = true;
        $entry->stepIndexByTitle[$title] = count($entry->steps);
        $entry->steps[] = ['title' => $title, 'queries' => []];
    }

    /**
     * Add a workflow query to the step matching $stepTitle.
     * If no matching step exists, creates one. Deduplicates queries within the step.
     */
    public function addStepQuery(string $stepTitle, string $query): void
    {
        if ($query === '') {
            return;
        }
        $entry = $this->currentEntry();
        $key = $stepTitle !== '' ? $stepTitle : '__orphan__';

        if (!isset($entry->stepIndexByTitle[$key])) {
            $entry->seenStepTitles[$key] = true;
            $entry->stepIndexByTitle[$key] = count($entry->steps);
            $entry->steps[] = ['title' => $stepTitle, 'queries' => []];
        }

        $idx = $entry->stepIndexByTitle[$key];
        if (!in_array($query, $entry->steps[$idx]['queries'], true)) {
            $entry->steps[$idx]['queries'][] = $query;
        }
    }

    /**
     * Add a selected source (from sources_answer_mode).
     * Deduplicates by URL. Numbers come from citation row.
     */
    public function addSource(array $source): void
    {
        $url = $source['url'] ?? '';
        $entry = $this->currentEntry();
        if ($url !== '' && isset($entry->seenSourceUrls[$url])) {
            return;
        }
        if ($url !== '') {
            $entry->seenSourceUrls[$url] = true;
        }
        $entry->sources[] = $source;
    }

    public function addWidget(array $widget): void
    {
        $this->currentEntry()->widgets[] = $widget;
    }

    // ─── Global media (cross-entry dedup) ─────────────────────────

    public function addGeneratedImage(array $image): void
    {
        $path = $image['s3_path'] ?? '';
        if ($path !== '' && isset($this->seenGlobalImagePaths[$path])) {
            return;
        }
        if ($path !== '') {
            $this->seenGlobalImagePaths[$path] = true;
        }
        $this->generatedImages[] = $image;
    }

    public function addAttachment(array $attachment): void
    {
        $key = $attachment['s3_key'] ?? '';
        if ($key !== '' && isset($this->seenGlobalAttachmentKeys[$key])) {
            return;
        }
        if ($key !== '') {
            $this->seenGlobalAttachmentKeys[$key] = true;
        }
        $this->attachments[] = $attachment;
    }
}
