<?php
declare(strict_types=1);

namespace App\Processor;

class AnswerProcessor implements BlockProcessorInterface
{
    public function process(array $entry, ConvertContext $context): void
    {
        $answer = $this->getCanonicalAnswer($entry);
        $context->addAnswer($answer);
    }

    private function getCanonicalAnswer(array $entry): ?string
    {
        $blocks = $entry['blocks'] ?? [];

        $numbered = [];
        $askText0 = null;
        $askText = null;

        foreach ($blocks as $b) {
            $usage = $b['intended_usage'] ?? '';
            if ($usage === 'ask_text_0_markdown') {
                $askText0 = $b['markdown_block']['answer'] ?? null;
            } elseif ($usage === 'ask_text') {
                $askText = $b['markdown_block']['answer'] ?? null;
            } elseif (preg_match('/^ask_text_(\d+)_markdown$/', $usage, $m)) {
                $numbered[(int) $m[1]] = $b['markdown_block']['answer'] ?? '';
            }
        }

        if (count($numbered) >= 1) {
            ksort($numbered);
            $parts = array_filter($numbered, fn($v) => $v !== '');
            if (!empty($parts)) {
                return implode("\n\n", $parts);
            }
        }

        if ($askText0 !== null && $askText0 !== '') {
            return $askText0;
        }

        if ($askText !== null && $askText !== '') {
            return $askText;
        }

        return null;
    }
}
