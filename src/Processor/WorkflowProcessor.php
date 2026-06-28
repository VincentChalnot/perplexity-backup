<?php
declare(strict_types=1);

namespace App\Processor;

/**
 * Extracts workflow steps and queries from workflow_root block.
 */
class WorkflowProcessor implements BlockProcessorInterface
{
    public function process(array $entry, ConvertContext $context): void
    {
        foreach (($entry['blocks'] ?? []) as $b) {
            if (($b['intended_usage'] ?? '') !== 'workflow_root') {
                continue;
            }
            foreach (($b['workflow_block']['steps'] ?? []) as $step) {
                $stepTitle = $step['title'] ?? '';

                foreach (($step['items'] ?? []) as $item) {
                    $type = $item['type'] ?? $item['item_type'] ?? '';

                    if ($type === 'WORKFLOW_ITEM_QUERIES') {
                        foreach (($item['payload']['queries_payload']['queries'] ?? []) as $q) {
                            $context->addStepQuery($stepTitle, $q);
                        }
                    }
                }
            }
        }
    }
}
