<?php
declare(strict_types=1);

namespace App\Processor;

/**
 * Extracts file attachments from web_result_block (is_attachment=true).
 */
class AttachmentProcessor implements BlockProcessorInterface
{
    public function process(array $entry, ConvertContext $context): void
    {
        foreach (($entry['blocks'] ?? []) as $b) {
            if (($b['intended_usage'] ?? '') !== 'web_results') {
                continue;
            }
            foreach (($b['web_result_block']['web_results'] ?? []) as $wr) {
                if (empty($wr['is_attachment'])) {
                    continue;
                }

                $url = $wr['url'] ?? '';
                $fm = $wr['file_metadata'] ?? [];
                $rawKey = $fm['raw_file_s3_key'] ?? null;
                $s3Key = $this->s3KeyFrom($url, $rawKey);

                $context->addAttachment([
                    's3_key' => $s3Key,
                    'name' => $wr['name'] ?? '',
                    'url' => $url,
                    'snippet' => $wr['snippet'] ?? '',
                    'num_characters' => $fm['num_characters'] ?? null,
                ]);
            }
        }
    }

    private function s3KeyFrom(string $url, ?string $rawFileS3Key = null): string
    {
        if ($rawFileS3Key !== null && $rawFileS3Key !== '') {
            return ltrim($rawFileS3Key, '/');
        }
        return $this->s3KeyFromUrl($url);
    }

    private function s3KeyFromUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parsed = parse_url($url);
        return ltrim($parsed['path'] ?? '', '/');
    }
}
