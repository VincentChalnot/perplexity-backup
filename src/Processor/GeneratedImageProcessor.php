<?php
declare(strict_types=1);

namespace App\Processor;

class GeneratedImageProcessor implements BlockProcessorInterface
{
    private const array ASSET_TYPES = ['GENERATED_IMAGE'];

    public function process(array $entry, ConvertContext $context): void
    {
        $images = $this->collectFromUnifiedAssets($entry);
        $images = $this->supplementFromMediaItems($entry, $images);

        foreach ($images as $img) {
            $context->addGeneratedImage($img);
        }
    }

    /**
     * @return array<int, array>
     */
    private function collectFromUnifiedAssets(array $entry): array
    {
        $images = [];

        foreach (($entry['blocks'] ?? []) as $b) {
            if (($b['intended_usage'] ?? '') !== 'unified_assets') {
                continue;
            }
            foreach (($b['unified_assets_block']['assets'] ?? []) as $asset) {
                if (!in_array($asset['asset_type'] ?? '', self::ASSET_TYPES, true)) {
                    continue;
                }
                $gen = $asset['generated_image'] ?? [];
                $thumbnailUrl = $gen['thumbnail_url'] ?? $gen['url'] ?? '';
                $signedUrl = $gen['url'] ?? null;

                $images[] = [
                    's3_path' => $this->s3KeyFromUrl($thumbnailUrl),
                    'uuid' => $asset['backend_uuid_slug'] ?? $asset['uuid'] ?? '',
                    'name' => $asset['name'] ?? '',
                    'prompt' => '',
                    'model' => '',
                    'thumbnail_url' => $thumbnailUrl,
                    'signed_url' => $signedUrl,
                    'width' => $gen['image_width'] ?? null,
                    'height' => $gen['image_height'] ?? null,
                ];
            }
        }

        return $images;
    }

    /**
     * @param array<int, array> $images
     * @return array<int, array>
     */
    private function supplementFromMediaItems(array $entry, array $images): array
    {
        foreach (($entry['blocks'] ?? []) as $b) {
            $usage = $b['intended_usage'] ?? '';
            $mediaBlock = null;

            if ($usage === 'media_items') {
                $mediaBlock = $b['media_block'] ?? null;
            } elseif ($usage === 'answer_generated_image') {
                $mediaBlock = $b['inline_entity_block']['media_block'] ?? null;
            }

            if ($mediaBlock === null) {
                continue;
            }

            $allItems = array_merge(
                $mediaBlock['media_items'] ?? [],
                $mediaBlock['generated_media_items'] ?? [],
            );

            foreach ($allItems as $mi) {
                $meta = $mi['generated_media_metadata'] ?? [];
                if (empty($meta)) {
                    continue;
                }

                $miThumb = $mi['thumbnail'] ?? $mi['image'] ?? '';
                $miPath = $this->s3KeyFromUrl($miThumb);
                $matched = false;

                foreach ($images as &$img) {
                    if ($img['s3_path'] === $miPath || ($img['s3_path'] !== '' && $miPath !== '' && basename($img['s3_path']) === basename($miPath))) {
                        $img['prompt'] = $img['prompt'] !== '' ? $img['prompt'] : ($meta['prompt'] ?? '');
                        $img['model'] = $img['model'] !== '' ? $img['model'] : ($meta['model_str'] ?? '');
                        $img['name'] = $img['name'] !== '' ? $img['name'] : ($mi['name'] ?? '');
                        $matched = true;
                        break;
                    }
                }
                unset($img);

                if (!$matched && $miPath !== '') {
                    $images[] = [
                        's3_path' => $miPath,
                        'uuid' => '',
                        'name' => $mi['name'] ?? '',
                        'prompt' => $meta['prompt'] ?? '',
                        'model' => $meta['model_str'] ?? '',
                        'thumbnail_url' => $mi['thumbnail'] ?? $mi['image'] ?? '',
                        'signed_url' => $mi['image'] ?? $mi['thumbnail'] ?? null,
                        'width' => $mi['image_width'] ?? null,
                        'height' => $mi['image_height'] ?? null,
                    ];
                }
            }
        }

        return $images;
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
