<?php
declare(strict_types=1);


namespace CSCart\Cart4CatalogImport\Job;


use CSCart\Core\Catalog\Category\Model\Category;
use CSCart\Core\Catalog\Product\Enum\ProductOfferStatusEnum;
use CSCart\Core\Catalog\Product\Enum\ProductStatusEnum;
use CSCart\Core\Catalog\Product\Model\Input\ProductFeatureValueInput;
use CSCart\Core\Catalog\Product\Model\Input\ProductInput;
use CSCart\Core\Catalog\Product\Model\Input\ProductOfferGroupInput;
use CSCart\Core\Catalog\Product\Model\Input\ProductOfferGroupVariableFeatureVariantLinkInput;
use CSCart\Core\Catalog\Product\Model\Input\ProductOfferInput;
use CSCart\Core\Catalog\Product\Model\Input\ProductOfferVariableFeatureVariantLinkInput;
use CSCart\Core\Catalog\Product\Model\Input\ProductTranslationInput;
use CSCart\Core\Catalog\Product\Model\Input\ProductVariableFeatureLinkInput;
use CSCart\Core\Catalog\Product\Model\Product;
use CSCart\Core\Catalog\Product\Model\ProductOffer;
use CSCart\Core\Catalog\ProductBrand\Model\ProductBrand;
use CSCart\Core\Catalog\ProductFeature\Model\ProductFeature;
use CSCart\Core\Log\EventLogger;
use CSCart\Core\Media\Model\Input\ImageInput;
use CSCart\Core\Media\Model\Input\ImageTranslationInput;
use CSCart\Core\Seller\Model\Seller;
use CSCart\Framework\Database\Eloquent\Collection;
use CSCart\Framework\Database\Eloquent\Operation\CreateOperation;
use CSCart\Framework\Database\Eloquent\Repository;
use CSCart\Framework\Enum\ObjectStatusEnum;
use CSCart\Framework\Package\Manifest\PackageManifest;
use CSCart\ProductReviews\Model\Input\ProductReviewInput;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\Mime\MimeTypes;
use function Safe\array_flip;

class ImportProductsJob extends BaseImportJob
{
    protected array $productIds;

    /**
     * @inheritDoc
     */
    public function __construct(array $productIds, array $params, array $connectionConfig)
    {
        parent::__construct($params, $connectionConfig);

        $this->productIds = $productIds;
    }

    /**
     * @param \CSCart\Framework\Database\Eloquent\Repository $repository
     *
     * @return void
     */
    public function handle(Repository $repository): void
    {
        EventLogger::disableLogging();
        $db = $this->getConnection();
        $db->table('products')->whereIn('product_id', $this->productIds)
            ->where('product_type', 'P')
            ->eachById(fn ($productDto) => $this->importProduct($repository, $db, $productDto), 100, 'product_id');
    }

    /**
     * @param \CSCart\Framework\Database\Eloquent\Repository $repository
     * @param \Illuminate\Database\ConnectionInterface       $db
     * @param \stdClass                                      $productDto
     *
     * @return void
     */
    protected function importProduct(Repository $repository, ConnectionInterface $db, stdClass $productDto): void
    {
        $mimeTypes = new MimeTypes();
        $code = $this->getProductCode($productDto);

        if (Product::query()->where('code', $code)->exists() || ProductOffer::query()->where('code', $code)->exists()) {
            return;
        }

        $groupId = $db->table('product_variation_group_products')->where('product_id', $productDto->product_id)->value('group_id');

        if ($groupId) {
            $id = $db->table('product_variation_group_products')->where('group_id', $groupId)->where('parent_product_id', 0)->min('product_id');

            if ((int) $productDto->product_id > (int) $id) {
                return;
            }
        }

        /** @var \stdClass $productTranslationDto */
        $productTranslationDto = $db->table('product_descriptions')
            ->where([
                'lang_code'   => 'en',
                'product_id' => $productDto->product_id
            ])->first();

        if ($productTranslationDto === null) {
            return;
        }

        $seller = $this->findSeller($db, $productDto);

        if ($seller === null) {
            return;
        }

        $categories = $this->findCategories($db, $productDto);
        $featureValues = $this->findFeatureValues($db, $productDto);
        $brand = $this->findBrand($db, $productDto);
        $productSeoName = $db->table('seo_names')->where(['object_id' => $productDto->product_id, 'type' => 'p', 'lang_code' => 'en'])->value('name');

        if (empty($productSeoName) || !preg_match('/^[\pL\pM\pN_-]+$/u', $productSeoName)) {
            $productSeoName = null;
        }

        /**
         * @var \CSCart\Framework\Database\Eloquent\Collection $variableFeatures
         * @var \CSCart\Framework\Database\Eloquent\Collection $variations
         */
        [$variableFeatures, $variations] = $this->findVariations($db, $productDto->product_id);
        $productIds = [$code => $productDto->product_id];

        $input = new ProductInput();
        $input->code = $code;
        $input->seller_id = $seller->id;
        $input->status = $productDto->status === 'A' ? ProductStatusEnum::ACTIVE : ProductStatusEnum::DISABLE;
        $input->tracking = $productDto->tracking === 'B';
        $input->weight = (float) $productDto->weight;
        $input->length = (int) $productDto->length;
        $input->width = (int) $productDto->width;
        $input->height = (int) $productDto->height;
        $input->shipping_freight = (float) $productDto->shipping_freight;
        $input->seo_name = $productSeoName;
        $input->feature_values = $featureValues;
        $input->categories->setSyncModels($categories);
        $input->translation = new ProductTranslationInput();
        $input->translation->name = (string) $productTranslationDto->product;
        $input->translation->description = (string) $productTranslationDto->full_description;

        if ($brand) {
            $input->brand->setAssociateModel($brand);
        }

        if ($variations && $variableFeatures->isNotEmpty()) {
            $input->variable_feature_links = $variableFeatures;

            foreach ($variations as $variation) {
                $offerInput = new ProductOfferInput();
                $offerInput->code = $this->getProductCode($variation);
                $offerInput->price = $this->findPrice($db, $variation);
                $offerInput->feature_values = $this->findOfferFeatureValues($db, $variation, $featureValues, $variableFeatures);;
                $offerInput->list_price = (float) $variation->list_price;
                $offerInput->status = $variation->status === 'A' ? ProductOfferStatusEnum::ACTIVE : ProductOfferStatusEnum::DISABLE;
                $offerInput->barcode = (string) $variation->product_code;
                $offerInput->quantity = (int) $variation->amount;
                $offerInput->variable_feature_variant_links = $variation->varible_feature_values;

                $group = new ProductOfferGroupInput();
                $group->variable_feature_variant_links = new Collection();

                /** @var \CSCart\Core\Catalog\Product\Model\Input\ProductVariableFeatureLinkInput $variableFeature */
                foreach ($variableFeatures as $variableFeature) {
                    if (!$variableFeature->is_groupable) {
                        continue;
                    }

                    /** @var \CSCart\Core\Catalog\Product\Model\Input\ProductOfferVariableFeatureVariantLinkInput $variantLink */
                    $variantLink = $offerInput->variable_feature_variant_links->get($variableFeature->feature_id);

                    $link = new ProductOfferGroupVariableFeatureVariantLinkInput();
                    $link->feature_id = $variableFeature->feature_id;
                    $link->variant_id = $variantLink->variant_id;

                    $group->variable_feature_variant_links->add($link);
                }

                if (!$input->groups->create->has($group->getHash())) {
                    $images = $this->findImages($db, $variation->product_id);

                    foreach ($images as $image) {
                        $imageInput = new ImageInput();
                        $imageInput->upload = new UploadedFile($image['path'], basename($image['path']), $mimeTypes->guessMimeType($image['path']), null, true);
                        $imageInput->role = $group->images->create->isEmpty() ? 'main' : null;
                        $imageInput->translation = new ImageTranslationInput();
                        $imageInput->translation->alt = (string) $productTranslationDto->product;

                        $group->images->addCreateInput($imageInput);
                    }

                    $input->groups->create->put($group->getHash(), $group);
                }

                $input->offers->addCreateInput($offerInput);
                $productIds[$offerInput->code] = $variation->product_id;
            }
        } else {
            $input->offer = new ProductOfferInput();
            $input->offer->code = $code;
            $input->offer->price = $this->findPrice($db, $productDto);
            $input->offer->list_price = (float) $productDto->list_price;
            $input->offer->status = ProductOfferStatusEnum::ACTIVE;
            $input->offer->barcode = (string) $productDto->product_code;
            $input->offer->quantity = (int) $productDto->amount;

            $images = $this->findImages($db, $productDto->product_id);

            foreach ($images as $image) {
                $imageInput = new ImageInput();
                $imageInput->upload = new UploadedFile($image['path'], basename($image['path']), $mimeTypes->guessMimeType($image['path']), null, true);
                $imageInput->role = $input->images->create->isEmpty() ? 'main' : null;
                $imageInput->translation = new ImageTranslationInput();
                $imageInput->translation->alt = (string) $productTranslationDto->product;

                $input->images->addCreateInput($imageInput);
            }
        }

        try {
            $product = $repository->create(new CreateOperation($this->getContext(), $input))->data;
            $this->importReviews($repository, $product, $db, $productIds);
        } catch (ValidationException $exception) {
            report($exception);
        }
    }

    /**
     * @param \CSCart\Framework\Database\Eloquent\Repository $repository
     * @param \CSCart\Core\Catalog\Product\Model\Product     $product
     * @param \Illuminate\Database\ConnectionInterface       $db
     * @param array                                          $productIds
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function importReviews(Repository $repository, Product $product, ConnectionInterface $db, array $productIds): void
    {
        /** @var \CSCart\Framework\Package\Manifest\PackageManifest $manifest */
        $manifest = app(PackageManifest::class);

        if (!$manifest->isPackageActive('cscart/product-reviews')) {
            return;
        }

        $reviews = $db->table('product_reviews')->whereIn('product_id', $productIds)->get();

        if ($reviews->isEmpty()) {
            return;
        }

        $productIdMap = array_flip($productIds);
        $map = [];

        foreach ($product->offers as $offer) {
            $map[$offer->code] = $offer->id;
        }

        foreach ($reviews as $review) {
            $productCode = $productIdMap[$review->product_id] ?? null;

            if ($productCode === null) {
                continue;
            }

            $offerId = $map[$productCode] ?? null;

            if ($offerId === null) {
                continue;
            }

            $input = new ProductReviewInput();
            $input->status = ObjectStatusEnum::ACTIVE;
            $input->offer_id = $offerId;
            $input->storefront_id = 1;
            $input->reviewer_name = (string) $review->name;
            $input->advantages = (string) $review->advantages;
            $input->disadvantages = (string) $review->disadvantages;
            $input->comment = (string) $review->comment;
            $input->rating_value = (int) $review->rating_value;

            $repository->create(new CreateOperation($this->getContext(), $input));
        }
    }

    /**
     * @param \Illuminate\Database\ConnectionInterface $db
     * @param \stdClass                                $productDto
     *
     * @return \CSCart\Core\Seller\Model\Seller|null
     */
    protected function findSeller(ConnectionInterface $db, stdClass $productDto): ?Seller
    {
        $companyName = $db->table('companies')->where('company_id', $productDto->company_id)->value('company');

        return Seller::query()->where('name', $companyName)->first();
    }

    /**
     * @param \Illuminate\Database\ConnectionInterface $db
     * @param \stdClass                                $productDto
     *
     * @return float
     */
    protected function findPrice(ConnectionInterface $db, stdClass $productDto): float
    {
        return (float) $db->table('product_prices')
            ->where('product_id', $productDto->product_id)
            ->where('lower_limit', 1)
            ->where('usergroup_id', 0)
            ->value('price');
    }

    /**
     * @param \Illuminate\Database\ConnectionInterface $db
     * @param \stdClass                                $productDto
     *
     * @return \CSCart\Core\Catalog\ProductBrand\Model\ProductBrand|null
     */
    protected function findBrand(ConnectionInterface $db, stdClass $productDto): ?ProductBrand
    {
        $featureIds = $db->table('product_features')->where('feature_type', 'E')->pluck('feature_id');
        $query = $db->table('product_features_values')->where(['product_id' => $productDto->product_id, 'lang_code' => 'en']);
        $query->whereIn('feature_id', $featureIds);

        $variantIds = $query->pluck('variant_id');

        foreach ($variantIds as $variantId) {
            /** @var \CSCart\Core\Catalog\ProductBrand\Model\ProductBrand|null $brand */
            $brand = ProductBrand::query()->where('code', $this->getProjectName() . $variantId)->first();

            if ($brand !== null) {
                return $brand;
            }
        }

        return null;
    }

    /**
     * @param \Illuminate\Database\ConnectionInterface $db
     * @param \stdClass                                $productDto
     *
     * @return \CSCart\Framework\Database\Eloquent\Collection
     */
    protected function findCategories(ConnectionInterface $db, stdClass $productDto): Collection
    {
        $categoryIds = $db->table('products_categories')->where(['product_id' => $productDto->product_id])->orderBy('link_type', 'desc')->orderBy('position', 'asc')->pluck('category_id')->all();
        $categoryCodes = array_map(fn (string $categoryId) => $this->getProjectName() . $categoryId, $categoryIds);

        return Category::query()->whereIn('code', $categoryCodes)->get();
    }

    /**
     * @param \Illuminate\Database\ConnectionInterface $db
     * @param \stdClass                                $productDto
     * @param array|null                               $featureIds
     *
     * @return \CSCart\Framework\Database\Eloquent\Collection
     */
    protected function findFeatureValues(ConnectionInterface $db, stdClass $productDto, ?array $featureIds = null): Collection
    {
        $result = new Collection();
        $query = $db->table('product_features_values')->where(['product_id' => $productDto->product_id, 'lang_code' => 'en']);

        if ($featureIds) {
            $query->whereIn('feature_id', $featureIds);
        }

        foreach ($query->get() as $item) {
            $featureCode = $this->getProjectName() . $item->feature_id;
            /** @var \CSCart\Core\Catalog\ProductFeature\Model\ProductFeature|null $feature */
            $feature = ProductFeature::query()->where('code', $featureCode)->first();

            if ($feature === null) {
                continue;
            }

            if ($item->variant_id) {
                $variantCode = $this->getProjectName() . $item->variant_id;
                /** @var \CSCart\Core\Catalog\ProductFeature\Model\ProductFeatureVariant|null $variant */
                $variant = $feature->variants()->where('code', $variantCode)->first();

                if ($variant === null) {
                    continue;
                }

                $input = new ProductFeatureValueInput();
                $input->feature_id = $feature->id;
                $input->variant_id = [$variant->id];

                $result->put($input->feature_id, $input);
            } else {
                $input = new ProductFeatureValueInput();
                $input->feature_id = $feature->id;
                $input->value = $item->value;

                $result->put($input->feature_id, $input);
            }
        }

        return $result;
    }

    /**
     * @param \Illuminate\Database\ConnectionInterface $db
     * @param \stdClass                                $productDto
     * @param                                          $productFeatureValues
     * @param                                          $variableFeatures
     *
     * @return \CSCart\Framework\Database\Eloquent\Collection
     */
    protected function findOfferFeatureValues(ConnectionInterface $db, stdClass $productDto, $productFeatureValues, $variableFeatures): Collection
    {
        $result = $this->findFeatureValues($db, $productDto);

        /**
         * @var int $featureId
         * @var \CSCart\Core\Catalog\Product\Model\Input\ProductFeatureValueInput $item
         */
        foreach ($result as $featureId => $item) {
            if (isset($variableFeatures[$featureId])) {
                unset($result[$featureId]);
                continue;
            }

            if (!isset($productFeatureValues[$featureId])) {
                continue;
            }

            if ($item->variant_id && $item->variant_id === $productFeatureValues[$featureId]->variant_id) {
                unset($result[$featureId]);
                continue;
            }

            if ($item->value && $item->value === $productFeatureValues[$featureId]->value) {
                unset($result[$featureId]);
                continue;
            }
        }

        return $result;
    }


    /**
     * @param \Illuminate\Database\ConnectionInterface $db
     * @param int                                      $productId
     *
     * @return array
     */
    protected function findImages(ConnectionInterface $db, int $productId): array
    {
        $images = [];
        $productImagesDto = $db->table('images')->join('images_links', 'images.image_id', '=', 'images_links.detailed_id')
            ->where([
                'images_links.object_type' => 'product',
                'images_links.object_id'   => $productId
            ])
            ->select(['images_links.pair_id', 'images.image_id', 'images.image_path', 'images_links.position'])
            ->orderBy('type', 'desc')
            ->orderBy('position', 'asc')
            ->get();

        foreach ($productImagesDto as $productImageDto) {
            $image = (array) $productImageDto;
            $image['path'] = $this->getImagesDirPath() . '/detailed/' . floor($productImageDto->image_id / 1000) . '/' . $productImageDto->image_path;

            if (!file_exists($image['path'])) {
                continue;
            }

            $images[] = $image;
        }

        return $images;
    }

    /**
     * @param \Illuminate\Database\ConnectionInterface $db
     * @param int                                      $productId
     *
     * @return array
     */
    protected function findVariations(ConnectionInterface $db, int $productId): array
    {
        $result = new Collection();
        $groupId = $db->table('product_variation_group_products')->where('product_id', $productId)->value('group_id');

        if ($groupId === null) {
            return [$result, $result];
        }

        $variableFeatures = new Collection();
        $features = $db->table('product_variation_group_features')->where('group_id', $groupId)->orderBy('purpose')->get();
        $featureIds = $features->pluck('feature_id')->all();

        foreach ($features as $item) {
            $featureCode = $this->getProjectName() . $item->feature_id;
            /** @var \CSCart\Core\Catalog\ProductFeature\Model\ProductFeature|null $feature */
            $feature = ProductFeature::query()->where('code', $featureCode)->first();

            if ($feature === null) {
                return [$result, $result];
            }

            $variableFeature = new ProductVariableFeatureLinkInput();
            $variableFeature->feature_id = $feature->id;
            $variableFeature->is_groupable = $item->purpose === 'group_catalog_item';
            $variableFeature->variant_id = [];

            $variableFeatures->put($variableFeature->feature_id, $variableFeature);
        }

        $variations = $db->table('products')->join('product_variation_group_products', 'products.product_id', '=', 'product_variation_group_products.product_id')
            ->where('product_variation_group_products.group_id', $groupId)->get();

        foreach ($variations as $variation) {
            $featureValues = $this->findFeatureValues($db, $variation, $featureIds);
            $variation->varible_feature_values = new Collection();

            /** @var \CSCart\Core\Catalog\Product\Model\Input\ProductVariableFeatureLinkInput $variableFeature */
            foreach ($variableFeatures as $variableFeature) {
                /** @var \CSCart\Core\Catalog\Product\Model\Input\ProductFeatureValueInput|null $item */
                $item = $featureValues->get($variableFeature->feature_id);

                if ($item === null || empty($item->variant_id)) {
                    continue;
                }

                $variantIds = $item->variant_id;
                $variantId = (int) reset($variantIds);
                $variableFeature->addVariantId($variantId);

                $link = new ProductOfferVariableFeatureVariantLinkInput();
                $link->feature_id = (int) $variableFeature->feature_id;
                $link->variant_id = (int) $variantId;

                $variation->varible_feature_values->put($link->feature_id, $link);
            }

            if ($variation->varible_feature_values->isEmpty()) {
                continue;
            }

            $result->put($variation->product_id, $variation);
        }

        return [$variableFeatures, $result];
    }

    /**
     * @param \stdClass $product
     *
     * @return string
     */
    protected function getProductCode(stdClass $product): string
    {
        $code = $product->product_code ? $product->product_code . $product->product_id : $this->getProjectName() . $product->product_id;

        if ($this->getProductCodePrefix()) {
            return $this->getProductCodePrefix() . $code;
        }

        return $code;
    }
}
