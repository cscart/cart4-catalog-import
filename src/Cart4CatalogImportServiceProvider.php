<?php
declare(strict_types=1);


namespace CSCart\Cart4CatalogImport;


use CSCart\Cart4CatalogImport\Console\CloneProductCommand;
use CSCart\Cart4CatalogImport\Console\ImportCommand;
use Illuminate\Support\ServiceProvider;

class Cart4CatalogImportServiceProvider extends ServiceProvider
{
    /**
     * @inheritDoc
     */
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands(ImportCommand::class, CloneProductCommand::class);
        }
    }
}
