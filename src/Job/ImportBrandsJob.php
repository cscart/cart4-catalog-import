<?php
declare(strict_types=1);


namespace CSCart\Cart4CatalogImport\Job;


use CSCart\Core\Catalog\ProductBrand\Model\Input\ProductBrandInput;
use CSCart\Core\Catalog\ProductBrand\Model\Input\ProductBrandTranslationInput;
use CSCart\Core\Catalog\ProductBrand\Model\ProductBrand;
use CSCart\Core\Log\EventLogger;
use CSCart\Core\Media\Model\Input\ImageInput;
use CSCart\Core\Media\Model\Input\ImageTranslationInput;
use CSCart\Framework\Database\Eloquent\Operation\CreateOperation;
use CSCart\Framework\Database\Eloquent\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use stdClass;

class ImportBrandsJob extends BaseImportJob
{
    /**
     * @param \CSCart\Framework\Database\Eloquent\Repository $repository
     *
     * @return void
     */
    public function handle(Repository $repository): void
    {
        EventLogger::disableLogging();
        $db = $this->getConnection();

        $featureIds = $db->table('product_features')->where('feature_type', 'E')->pluck('feature_id')->all();

        if (empty($featureIds)) {
            return;
        }

        $db->table('product_feature_variants')->whereIn('feature_id', $featureIds)->eachById(fn ($variant) => $this->importBrand($repository, $db, $variant), 100, 'variant_id');
    }

    /**
     * @param \CSCart\Framework\Database\Eloquent\Repository $repository
     * @param \Illuminate\Database\ConnectionInterface       $db
     * @param \stdClass                                      $variantDto
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function importBrand(Repository $repository, ConnectionInterface $db, stdClass $variantDto): void
    {
        $code = $this->getProjectName() . $variantDto->variant_id;

        if (ProductBrand::query()->where('code', $code)->exists()) {
            return;
        }

        /** @var \stdClass $variantTranslationDto */
        $variantTranslationDto = $db->table('product_feature_variant_descriptions')
            ->where([
                'lang_code' => 'en',
                'variant_id' => $variantDto->variant_id
            ])
            ->first();

        if ($variantTranslationDto === null) {
            return;
        }

        /** @var \stdClass|null $variantImageDto */
        $variantImageDto = $db->table('images')->join('images_links', 'images.image_id', '=', 'images_links.image_id')
            ->where([
                'images_links.object_type' => 'feature_variant',
                'images_links.object_id'   => $variantDto->variant_id
            ])
            ->select(['images_links.pair_id', 'images_links.image_id', 'images.image_path'])
            ->first();

        if ($variantImageDto) {
            $variantImageDto->path = $this->getImagesDirPath() . '/feature_variant/' . floor($variantImageDto->image_id / 1000) . '/' . $variantImageDto->image_path;
        }

        $input = new ProductBrandInput();
        $input->code = $code;
        $input->url = $variantDto->url ?: null;
        $input->translation = new ProductBrandTranslationInput();
        $input->translation->name = $variantTranslationDto->variant;
        $input->translation->description = $variantTranslationDto->description;

        if (isset($variantImageDto->path) && file_exists($variantImageDto->path)) {
            $input->image->create = new ImageInput();
            $input->image->create->upload = new UploadedFile($variantImageDto->path, basename($variantImageDto->path), null, null, true);
            $input->image->create->translation = new ImageTranslationInput();
            $input->image->create->translation->alt = $variantTranslationDto->variant;
        }

        $repository->create(new CreateOperation($this->getContext(), $input));
    }
}
