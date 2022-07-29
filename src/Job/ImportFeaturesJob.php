<?php
declare(strict_types=1);


namespace CSCart\Cart4CatalogImport\Job;


use CSCart\Core\Catalog\Category\Model\Category;
use CSCart\Core\Catalog\ProductFeature\Enum\ProductFeatureFilterTypeEnum;
use CSCart\Core\Catalog\ProductFeature\Enum\ProductFeatureSelectorTypeEnum;
use CSCart\Core\Catalog\ProductFeature\Enum\ProductFeatureTypeEnum;
use CSCart\Core\Catalog\ProductFeature\Model\Input\ProductFeatureInput;
use CSCart\Core\Catalog\ProductFeature\Model\Input\ProductFeatureTranslationInput;
use CSCart\Core\Catalog\ProductFeature\Model\Input\ProductFeatureVariantInput;
use CSCart\Core\Catalog\ProductFeature\Model\Input\ProductFeatureVariantTranslationInput;
use CSCart\Core\Catalog\ProductFeature\Model\ProductFeature;
use CSCart\Core\Log\EventLogger;
use CSCart\Framework\Database\Eloquent\Operation\CreateOperation;
use CSCart\Framework\Database\Eloquent\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

class ImportFeaturesJob extends BaseImportJob
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

        $db->table('product_features')->whereNotIn('feature_type', ['E', 'G'])->eachById(fn ($featureDto) => $this->importFeature($repository, $db, $featureDto), 100, 'feature_id');
    }

    /**
     * @param \CSCart\Framework\Database\Eloquent\Repository $repository
     * @param \Illuminate\Database\ConnectionInterface       $db
     * @param \stdClass                                      $featureDto
     *
     * @return void
     */
    protected function importFeature(Repository $repository, ConnectionInterface $db, stdClass $featureDto): void
    {
        $code = $this->getProjectName() . $featureDto->feature_id;

        if (ProductFeature::query()->where('code', $code)->exists()) {
            return;
        }

        $featureType = match ($featureDto->feature_type) {
            'S' => ProductFeatureTypeEnum::TEXT_SELECTBOX,
            'C' => ProductFeatureTypeEnum::CHECKBOX,
            'M' => ProductFeatureTypeEnum::MULTIPLE_CHECKBOX,
            'N' => ProductFeatureTypeEnum::NUMBER_SELECTBOX,
            'T' => ProductFeatureTypeEnum::TEXT,
            default => null
        };

        if ($featureType === null) {
            return;
        }

        /** @var \stdClass|null $featureTranslation */
        $featureTranslation = $db->table('product_features_descriptions')
            ->where([
                'lang_code'  => 'en',
                'feature_id' => $featureDto->feature_id
            ])->first();

        if ($featureTranslation === null) {
            return;
        }

        $isFilterable = $db->table('product_filters')->where('feature_id', $featureDto->feature_id)->exists();

        $filterType = match ($featureDto->filter_style) {
            'checkbox' => ProductFeatureFilterTypeEnum::CHECKBOX_LIST,
            'color' => ProductFeatureFilterTypeEnum::COLOR_LIST,
            default => null
        };

        $selectorType = match ($featureDto->feature_style) {
            'dropdown_images' => ProductFeatureSelectorTypeEnum::IMAGES,
            'dropdown_labels' => ProductFeatureSelectorTypeEnum::LABELS,
            'dropdown' => ProductFeatureSelectorTypeEnum::DROPDOWN,
            default => ProductFeatureSelectorTypeEnum::LABELS
        };

        $input = new ProductFeatureInput();
        $input->code = $code;
        $input->type = $featureType;
        $input->position = $featureDto->position;
        $input->is_filterable = $isFilterable;
        $input->filter_type = $filterType;
        $input->selector_type = $selectorType;
        $input->translation = new ProductFeatureTranslationInput();
        $input->translation->name = $featureTranslation->description;
        $input->translation->internal_name = $featureTranslation->internal_name;

        if ($featureDto->categories_path) {
            $categoryCodes = array_map(fn (string $categoryId) => $this->getProjectName() . $categoryId, explode(',', $featureDto->categories_path));
            $input->categories->setSyncModels(Category::query()->whereIn('code', $categoryCodes)->get());
        } else {
            $input->categories->setSyncModels(Category::query()->where('code', $this->getProjectName())->get());
        }

        $featureVariantsDto = $db->table('product_feature_variants')->where('feature_id', $featureDto->feature_id)->get();

        if ($featureVariantsDto->isNotEmpty()) {
            $variantNames = [];

            foreach ($featureVariantsDto as $variantDto) {
                /** @var \stdClass $variantTranslationDto */
                $variantTranslationDto = $db->table('product_feature_variant_descriptions')
                    ->where([
                        'lang_code' => 'en',
                        'variant_id' => $variantDto->variant_id
                    ])
                    ->first();

                if ($variantTranslationDto === null) {
                    continue;
                }

                $variantName = trim($variantTranslationDto->variant);
                $variantNameKey = Str::lower($variantName);

                if (isset($variantNames[$variantNameKey])) {
                    $variantNames[$variantNameKey]++;
                    $variantName .= " (#{$variantNames[$variantNameKey]})";
                } else {
                    $variantNames[$variantNameKey] = 0;
                }

                $variantInput = new ProductFeatureVariantInput();
                $variantInput->code = $this->getProjectName() . $variantDto->variant_id;
                $variantInput->color = $variantDto->color;
                $variantInput->position = $variantDto->position;
                $variantInput->translation = new ProductFeatureVariantTranslationInput();
                $variantInput->translation->name = $variantName;

                $input->variants->addCreateInput($variantInput);
            }
        }

        $repository->create(new CreateOperation($this->getContext(), $input));
    }
}
