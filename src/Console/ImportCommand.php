<?php
declare(strict_types=1);


namespace CSCart\Cart4CatalogImport\Console;


use CSCart\Cart4CatalogImport\Job\ImportBrandsJob;
use CSCart\Cart4CatalogImport\Job\ImportCategoriesJob;
use CSCart\Cart4CatalogImport\Job\ImportFeaturesJob;
use CSCart\Cart4CatalogImport\Job\ImportProductsBatchJob;
use CSCart\Cart4CatalogImport\Job\ImportSellersJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cs:cart4-catalog-import:import {name} {category-name} {db-login} {db-password} {db-host} {db-name} {db-table-prefix} {images-path} {--product-code-prefix=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import catalog';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        $dbConfig = [
            'driver'    => 'mysql',
            'host'      => (string) $this->argument('db-host'),
            'database'  => (string) $this->argument('db-name'),
            'username'  => (string) $this->argument('db-login'),
            'password'  => (string) $this->argument('db-password'),
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'    => (string) $this->argument('db-table-prefix'),
        ];

        config(['database.connections.cart4' => $dbConfig]);
        DB::connection('cart4')->table('products')->limit(1)->first();

        $params = [
            'name'                => (string) $this->argument('name'),
            'category_name'       => (string) $this->argument('category-name'),
            'images_path'         => (string) $this->argument('images-path'),
            'product_code_prefix' => (string) $this->option('product-code-prefix')
        ];

        if (!file_exists($params['images_path'])) {
            throw new RuntimeException('Images dir not found');
        }

        $jobs = [
            new ImportSellersJob($params, $dbConfig),
            new ImportCategoriesJob($params, $dbConfig),
            new ImportBrandsJob($params, $dbConfig),
            new ImportFeaturesJob($params, $dbConfig),
            new ImportProductsBatchJob($params, $dbConfig)
        ];

        Bus::chain($jobs)->dispatch();
    }
}
