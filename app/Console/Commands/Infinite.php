<?php

namespace App\Console\Commands;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Illuminate\Console\Command;

/**
 * Command to handle interface attribute multi-value conversion.
 */
class Infinite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'infinite:multivalue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make interface attr multi value';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $app_id = 'testing20MRCI4146'; // DEV
            $api_key = 'ebcff7267cce84aac818b8e68e4df7fd'; // DEV
            // $index_name = 'fm_product_en_dev'; // DEV
            $index_name = 'fm_product_en_qa'; // QA
            $chunk_size = 500;
            // Initialize Algolia client.
            $client = SearchClient::create($app_id, $api_key);
            $index = $client->initIndex($index_name);

            $total_processed = 0;
            $update_records = [];

            $records = $index->browseObjects();

            foreach ($records as $hit) {
                if (isset($hit['Interface Type'])) {
                    if (is_string($hit['Interface Type'])) {
                        $hit['Interface Type'] = [$hit['Interface Type']];
                        $update_records[] = $hit;
                    }
                }
            }
            // Update records in chunks.
            if (! empty($update_records)) {
                foreach (array_chunk($update_records, $chunk_size) as $update_chunk) {
                    $index->saveObjects($update_chunk);
                    $total_processed += count($update_chunk);
                    $this->info('Updated ' . $total_processed . ' records');
                }
            }
            $this->info('Successfully processed all records.');
        } catch (\Exception $e) {
            $this->error('Error processing records: ' . $e->getMessage());
            throw $e;
        }
    }
}
