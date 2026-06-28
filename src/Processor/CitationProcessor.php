<?php
declare(strict_types=1);

namespace App\Processor;

/**
 * Extracts SELECTED sources from sources_answer_mode block.
 * These are the sources actually cited in the answer text ([1], [2], etc.).
 */
class CitationProcessor implements BlockProcessorInterface
{
    public function process(array $entry, ConvertContext $context): void
    {
        foreach (($entry['blocks'] ?? []) as $b) {
            if (($b['intended_usage'] ?? '') !== 'sources_answer_mode') {
                continue;
            }
            $rows = $b['sources_mode_block']['rows'] ?? [];
            usort($rows, fn($a, $c) => ($a['citation'] ?? 9999) <=> ($c['citation'] ?? 9999));

            foreach ($rows as $row) {
                if (($row['status'] ?? '') !== 'SELECTED') {
                    continue;
                }
                $wr = $row['web_result'] ?? [];
                $url = $wr['url'] ?? '';
                $md = $wr['meta_data'] ?? [];
                $fm = $wr['file_metadata'] ?? [];

                $context->addSource([
                    'number' => $row['citation'] ?? null,
                    'name' => $wr['name'] ?? '',
                    'url' => $url,
                    'domain' => $md['citation_domain_name'] ?? $this->extractDomain($url),
                    'snippet' => $wr['snippet'] ?? '',
                    'description' => $md['description'] ?? '',
                    'published_date' => $md['published_date'] ?? '',
                    'images' => $md['images'] ?? [],
                    'num_characters' => $fm['num_characters'] ?? null,
                    'is_attachment' => !empty($wr['is_attachment']),
                ]);
            }
        }
    }

    private function extractDomain(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        return preg_replace('/^www\./', '', $host);
    }
}
