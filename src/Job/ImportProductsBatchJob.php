<?php
declare(strict_types=1);


namespace CSCart\Cart4CatalogImport\Job;


use CSCart\Core\Log\EventLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

class ImportProductsBatchJob extends BaseImportJob
{
    /**
     * @return void
     *
     * @throws \Throwable
     */
    public function handle(): void
    {
        EventLogger::disableLogging();
        $jobs = [];
        $this->getConnection()->table('products')->select(['product_id'])->chunkById(50, function (Collection $collection) use (&$jobs) {
            $jobs[] = new ImportProductsJob($collection->pluck('product_id')->all(), $this->params, $this->connectionConfig);
        }, 'product_id');

        Bus::batch($jobs)->dispatch();
    }
}
