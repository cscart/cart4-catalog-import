<?php
declare(strict_types=1);


namespace CSCart\Cart4CatalogImport\Job;


use CSCart\Core\App\Context\SystemContext;
use CSCart\Framework\Context\Context;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

abstract class BaseImportJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var array
     */
    protected array $connectionConfig = [];

    /**
     * @var array
     */
    protected array $params;

    /**
     * @var \CSCart\Framework\Context\Context
     */
    protected Context $context;

    /**
     * @param string $projectName
     * @param array  $connectionConfig
     */
    public function __construct(array $params, array $connectionConfig)
    {
        $this->params = $params;
        $this->connectionConfig = $connectionConfig;
    }

    /**
     * @return \Illuminate\Database\ConnectionInterface
     */
    protected function getConnection(): ConnectionInterface
    {
        config(['database.connections.cart4' => $this->connectionConfig]);

        return DB::connection('cart4');
    }

    /**
     * @return \CSCart\Framework\Context\Context
     */
    protected function getContext(): Context
    {
        if (isset($this->context)) {
            return $this->context;
        }

        return $this->context = SystemContext::create(true);
    }

    /**
     * @return string
     */
    protected function getProjectName(): string
    {
        return $this->params['name'] ?? 'mve';
    }

    /**
     * @return string
     */
    protected function getImagesDirPath(): string
    {
        return $this->params['images_path'] ?? base_path('/');
    }

    /**
     * @return string
     */
    protected function getProductCodePrefix(): string
    {
        return $this->params['product_code_prefix'] ?? '';
    }
}
