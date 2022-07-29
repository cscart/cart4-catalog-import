<?php
declare(strict_types=1);


namespace CSCart\Cart4CatalogImport\Job;

use CSCart\Core\Catalog\Warehouse\Model\Input\WarehouseInput;
use CSCart\Core\Catalog\Warehouse\Model\Input\WarehouseTranslationInput;
use CSCart\Core\Log\EventLogger;
use CSCart\Core\Media\Model\Input\ImageInput;
use CSCart\Core\Media\Model\Input\ImageTranslationInput;
use CSCart\Core\Seller\Enum\SellerStatusEnum;
use CSCart\Core\Seller\Model\Input\SellerInput;
use CSCart\Core\Seller\Model\Seller;
use CSCart\Core\Shipping\Model\Input\ShippingMethodInput;
use CSCart\Core\Shipping\Model\Input\ShippingMethodRateInput;
use CSCart\Core\Shipping\Model\Input\ShippingMethodTranslationInput;
use CSCart\Core\Shipping\Provider\Custom\CustomShippingProvider;
use CSCart\Core\User\Model\Input\BaseAddressInput;
use CSCart\Framework\Database\Eloquent\Operation\CreateOperation;
use CSCart\Framework\Database\Eloquent\Repository;
use CSCart\Framework\Enum\ObjectStatusEnum;
use Illuminate\Http\UploadedFile;
use stdClass;

class ImportSellersJob extends BaseImportJob
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
        $db->table('companies')->eachById(fn ($seller) => $this->importSeller($repository, $seller), 100, 'company_id');
    }

    /**
     * @param \CSCart\Framework\Database\Eloquent\Repository $repository
     * @param \stdClass                                      $sellerDto
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function importSeller(Repository $repository, stdClass $sellerDto): void
    {
        if (Seller::query()->where('name', $sellerDto->company)->exists()) {
            return;
        }

        $shippingMethodRateInput = new ShippingMethodRateInput();
        $shippingMethodRateInput->shipping_zone_id = 3; //Russia
        $shippingMethodRateInput->base_rate = 100;

        $shippingMethodInput = new ShippingMethodInput();
        $shippingMethodInput->translation = new ShippingMethodTranslationInput();
        $shippingMethodInput->translation->name = 'Custom shipping';
        $shippingMethodInput->provider = CustomShippingProvider::PROVIDER;
        $shippingMethodInput->min_delivery_days = 1;
        $shippingMethodInput->max_delivery_days = 5;
        $shippingMethodInput->status = ObjectStatusEnum::ACTIVE;
        $shippingMethodInput->rates->addCreateInput($shippingMethodRateInput);

        $warehouseInput = new WarehouseInput();
        $warehouseInput->translation = new WarehouseTranslationInput();
        $warehouseInput->translation->name = $sellerDto->company;
        $warehouseInput->address = $sellerDto->address ?: 'address';
        $warehouseInput->post_code = $sellerDto->zipcode ?: 'zipcode';
        $warehouseInput->phone = $sellerDto->phone ?: '+790000000';
        $warehouseInput->code = 'main';
        $warehouseInput->latitude = 1;
        $warehouseInput->longitude = 1;
        $warehouseInput->city->associate = 4; //Moscow
        $warehouseInput->shipping_methods->addCreateInput($shippingMethodInput);
        $warehouseInput->status = ObjectStatusEnum::ACTIVE;

        $input = new SellerInput();
        $input->name = $sellerDto->company;
        $input->status = $sellerDto->status === 'A' ? SellerStatusEnum::ACTIVE : SellerStatusEnum::DISABLE;
        $input->address = new BaseAddressInput();
        $input->address->city_id = 4; //Moscow
        $input->address->address1 = $sellerDto->address;
        $input->address->postal_code = $sellerDto->zipcode;
        $input->warehouses->addCreateInput($warehouseInput);
        $input->rating = rand(100, 500) / 100;
        $input->logo->create = $this->findSellerLogo($sellerDto);

        $repository->create(new CreateOperation($this->getContext(), $input));
    }

    /**
     * @param \stdClass $sellerDto
     *
     * @return \CSCart\Core\Media\Model\Input\ImageInput|null
     */
    protected function findSellerLogo(stdClass $sellerDto): ?ImageInput
    {
        $db = $this->getConnection();

        $logoDto = $db->table('logos')->where([
                'company_id' => $sellerDto->company_id,
                'type'       => 'theme',
                'layout_id'  => 0
            ])->first();

        if ($logoDto === null) {
            return null;
        }

        /** @var \stdClass|null $logoImageDto */
        $logoImageDto = $db->table('images')
            ->join('images_links', 'images.image_id', '=', 'images_links.image_id')
            ->where([
                'images_links.object_type' => 'logos',
                'images_links.object_id' => $logoDto->logo_id
            ])
            ->select(['images_links.pair_id', 'images_links.image_id', 'images.image_path'])
            ->first();

        if ($logoImageDto === null) {
            return null;
        }

        $logoImageDto->path = $this->getImagesDirPath() . '/logos/' . floor($logoImageDto->image_id / 1000) . '/' . $logoImageDto->image_path;

        if (!file_exists($logoImageDto->path)) {
            return null;
        }

        $input = new ImageInput();
        $input->upload = new UploadedFile($logoImageDto->path, basename($logoImageDto->path), null, null, true);
        $input->translation = new ImageTranslationInput();
        $input->translation->alt = $sellerDto->company;

        return $input;
    }
}
