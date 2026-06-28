<?php
declare(strict_types=1);

namespace App\Processor;

interface BlockProcessorInterface
{
    /**
     * Process a single entry, extracting relevant data into the context.
     * The context's current entry index is set by the caller before invocation.
     */
    public function process(array $entry, ConvertContext $context): void;
}
