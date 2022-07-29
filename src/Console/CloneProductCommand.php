<?php
declare(strict_types=1);


namespace CSCart\Cart4CatalogImport\Console;


use CSCart\Core\App\Context\Context;
use CSCart\Core\App\Context\SystemContext;
use CSCart\Core\Catalog\Product\Enum\ProductOfferStatusEnum;
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
use CSCart\Core\Media\Model\Input\ImageInput;
use CSCart\Core\Media\Model\Input\ImageTranslationInput;
use CSCart\Framework\Database\Eloquent\Operation\CreateOperation;
use CSCart\Framework\Database\Eloquent\Repository;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class CloneProductCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cs:cart4-catalog-import:clone {max_offers_count} {code_prefix}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clone products';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(Repository $repository): void
    {
        $lastProductId = 0;
        $context = SystemContext::create(true);


        while (true) {
            if (ProductOffer::query()->count() >= (int) $this->argument('max_offers_count')) {
                $this->info('Success');
                return;
            }

            /** @var \CSCart\Core\Catalog\Product\Model\Product|null $product */
            $product = Product::query()->where('id', '>', $lastProductId)->orderBy('id', 'asc')->limit(1)->first();

            if ($product === null && $lastProductId === 0) {
                break;
            }
            if ($product === null) {
                $lastProductId = 0;
                continue;
            }

            $this->cloneProduct($product, $context, $repository);

            $lastProductId = $product->id;
        }
    }

    protected function cloneProduct(Product $product, Context $context, Repository $repository): void
    {
        $code = (string) $this->argument('code_prefix') . $product->code;

        $input = new ProductInput();
        $input->code = $code;
        $input->seller_id = $product->seller_id;
        $input->status = $product->status;
        $input->tracking = $product->tracking;
        $input->weight = $product->weight;
        $input->length = $product->length;
        $input->width = $product->width;
        $input->height = $product->height;
        $input->shipping_freight = $product->shipping_freight;
        $input->seo_name = $product->seo_name;
        $input->categories->setSyncModels($product->categories);
        $input->translation = new ProductTranslationInput();
        $input->translation->name = $product->translation->name;
        $input->translation->description = $product->translation->description;

        if ($product->brand) {
            $input->brand->setAssociateModel($product->brand);
        }

        if ($product->feature_values->isNotEmpty()) {
            $input->feature_values = new Collection();

            foreach ($product->feature_values as $feature_value) {
                $value = new ProductFeatureValueInput();
                $value->feature_id = $feature_value->feature_id;

                if ($feature_value->variant_links->isNotEmpty()) {
                    $value->variant_id = $feature_value->variant_links->pluck('variant_id')->all();
                } elseif ($feature_value->string) {
                    $value->value = $feature_value->string->value;
                } else {
                    continue;
                }

                $input->feature_values->add($value);
            }
        }

        if ($product->variable_feature_links->isNotEmpty()) {
            foreach ($product->variable_feature_links as $variable_feature_links) {
                $link = new ProductVariableFeatureLinkInput();
                $link->feature_id = $variable_feature_links->feature_id;
                $link->variant_id = $variable_feature_links->variant_links->pluck('variant_id')->all();
                $link->is_groupable = $variable_feature_links->is_groupable;

                $input->variable_feature_links->add($link);
            }
        }

        $groups = [];

        foreach ($product->offers as $offer) {
            $groupInput = new ProductOfferGroupInput();
            $group = $offer->group;

            if ($group->variable_feature_variant_links->isNotEmpty()) {
                $groupInput->variable_feature_variant_links = new Collection();

                foreach ($group->variable_feature_variant_links as $feature_variant_link) {
                    $link = new ProductOfferGroupVariableFeatureVariantLinkInput();
                    $link->feature_id = $feature_variant_link->feature_id;
                    $link->variant_id = $feature_variant_link->variant_id;

                    $groupInput->variable_feature_variant_links->add($link);
                }
            }

            if ($group->image) {
                $imageInput = new ImageInput();
                $imageInput->upload = new UploadedFile(base_path('/storage/app/images/' . $group->image->path), basename($group->image->path), $group->image->type, null, true);
                $imageInput->role = 'main';
                $imageInput->translation = new ImageTranslationInput();
                $imageInput->translation->alt = $product->translation->name;

                $groupInput->images->addCreateInput($imageInput);
            }

            $offerInput = new ProductOfferInput();
            $offerInput->code = (string) $this->argument('code_prefix') . $offer->code;
            $offerInput->price = $offer->price;
            $offerInput->list_price = $offer->list_price;
            $offerInput->status = $offer->status;
            $offerInput->barcode = $offer->barcode;
            $offerInput->quantity = $offer->quantity;

            if ($offer->feature_values->isNotEmpty()) {
                $offerInput->feature_values = new Collection();

                foreach ($offer->feature_values as $feature_value) {
                    $value = new ProductFeatureValueInput();
                    $value->feature_id = $feature_value->feature_id;

                    if ($feature_value->variant_links->isNotEmpty()) {
                        $value->variant_id = $feature_value->variant_links->pluck('variant_id')->all();
                    } elseif ($feature_value->string) {
                        $value->value = $feature_value->string->value;
                    } else {
                        continue;
                    }

                    $offerInput->feature_values->add($value);
                }
            }

            if ($offer->variable_feature_variant_links->isNotEmpty()) {
                $offerInput->variable_feature_variant_links = new Collection();

                foreach ($offer->variable_feature_variant_links as $variable_feature_variant_link) {
                    $link = new ProductOfferVariableFeatureVariantLinkInput();
                    $link->feature_id = $variable_feature_variant_link->feature_id;
                    $link->variant_id = $variable_feature_variant_link->variant_id;

                    $offerInput->variable_feature_variant_links->add($link);
                }
            }


            if (!isset($groups[$groupInput->getHash()])) {
                if ($product->is_variable) {
                    $input->groups->addCreateInput($groupInput);
                } elseif ($input->images->isEmpty() && $group->image) {
                    $input->images->addCreateInput($groupInput->images->create->first());
                }

                $groups[$groupInput->getHash()] = true;
            }

            if ($product->is_variable) {
                $input->offers->addCreateInput($offerInput);
            } else {
                $input->offer = $offerInput;
            }
        }

        $repository->create(new CreateOperation($context, $input));
    }
}
