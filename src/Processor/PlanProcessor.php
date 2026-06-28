<?php
declare(strict_types=1);

namespace App\Processor;

class PlanProcessor implements BlockProcessorInterface
{
    public function process(array $entry, ConvertContext $context): void
    {
        foreach (($entry['blocks'] ?? []) as $b) {
            if (($b['intended_usage'] ?? '') !== 'plan') {
                continue;
            }
            foreach (($b['plan_block']['goals'] ?? []) as $goal) {
                $context->addStep($goal['description'] ?? '');
            }
        }
    }
}
