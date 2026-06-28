<?php
declare(strict_types=1);

namespace App\Processor;

class EntryMetadataProcessor implements BlockProcessorInterface
{
    public function process(array $entry, ConvertContext $context): void
    {
        $context->setEntryQueryLanguage($entry['query_language'] ?? '');
        $context->setEntryUpdatedDatetime($entry['updated_datetime'] ?? '');
        $context->setEntryDisplayModel($entry['display_model'] ?? '');
        $context->setEntrySourceTypes($entry['sources']['sources'] ?? []);
        $context->setEntrySearchMode($entry['search_mode'] ?? '');
    }
}
