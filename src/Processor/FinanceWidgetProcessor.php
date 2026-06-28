<?php
declare(strict_types=1);

namespace App\Processor;

class FinanceWidgetProcessor implements BlockProcessorInterface
{
    public function process(array $entry, ConvertContext $context): void
    {
        foreach (($entry['blocks'] ?? []) as $b) {
            if (($b['intended_usage'] ?? '') !== 'finance_widget') {
                continue;
            }
            $fwBlock = $b['widget_block']['finance_widget_block'] ?? [];
            foreach (($fwBlock['data_json_v2'] ?? []) as $jsonStr) {
                $data = json_decode($jsonStr, true);
                if (!is_array($data)) {
                    continue;
                }

                $context->addWidget([
                    'type' => 'finance',
                    'symbol' => $data['symbol'] ?? '',
                    'name' => $data['name'] ?? '',
                    'price' => $data['price'] ?? null,
                    'change' => $data['change'] ?? null,
                    'changePercent' => $data['changesPercentage'] ?? null,
                    'marketCap' => $data['marketCap'] ?? null,
                    'exchange' => $data['exchange'] ?? '',
                    'currency' => $data['currency'] ?? '',
                    'dayLow' => $data['dayLow'] ?? null,
                    'dayHigh' => $data['dayHigh'] ?? null,
                    'yearHigh' => $data['yearHigh'] ?? null,
                    'yearLow' => $data['yearLow'] ?? null,
                    'volume' => $data['volume'] ?? null,
                    'avgVolume' => $data['avgVolume'] ?? null,
                    'pe' => $data['pe'] ?? null,
                    'eps' => $data['eps'] ?? null,
                    'isEtf' => $data['isEtf'] ?? false,
                    'isCrypto' => $data['isCrypto'] ?? false,
                ]);
            }
        }
    }
}
