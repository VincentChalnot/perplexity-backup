<?php
declare(strict_types=1);

namespace App\Processor;

class QuestionProcessor implements BlockProcessorInterface
{
    public function process(array $entry, ConvertContext $context): void
    {
        $query = $entry['query_str'] ?? '';
        $context->addQuestion($query);
    }
}
