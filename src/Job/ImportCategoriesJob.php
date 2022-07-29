<?php
declare(strict_types=1);


namespace CSCart\Cart4CatalogImport\Job;


use CSCart\Core\Catalog\Category\Model\Category;
use CSCart\Core\Catalog\Category\Model\Input\CategoryInput;
use CSCart\Core\Catalog\Category\Model\Input\CategoryTranslationInput;
use CSCart\Core\Log\EventLogger;
use CSCart\Framework\Database\Eloquent\Operation\CreateOperation;
use CSCart\Framework\Database\Eloquent\Repository;
use CSCart\Framework\Enum\ObjectStatusEnum;
use Illuminate\Database\ConnectionInterface;
use stdClass;

class ImportCategoriesJob extends BaseImportJob
{
    /**
     * @param \CSCart\Framework\Database\Eloquent\Repository $repository
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function handle(Repository $repository): void
    {
        EventLogger::disableLogging();
        $db = $this->getConnection();

        if (Category::query()->where('code', $this->getProjectName())->exists()) {
            return;
        }

        $input = new CategoryInput();
        $input->code = $this->getProjectName();
        $input->status = ObjectStatusEnum::ACTIVE;
        $input->translation = new CategoryTranslationInput();
        $input->translation->name = $this->params['category_name'];
        $input->storefronts->addAttachId(1);

        $result = $repository->create(new CreateOperation($this->getContext(), $input));

        $db->table('categories')->where('parent_id', 0)->eachById(fn ($category) => $this->importCategory($repository, $db, $category, $result->data), 100, 'category_id');
    }

    /**
     * @param \CSCart\Framework\Database\Eloquent\Repository    $repository
     * @param \Illuminate\Database\ConnectionInterface          $db
     * @param \stdClass                                         $categoryDto
     * @param \CSCart\Core\Catalog\Category\Model\Category|null $parentCategoryModel
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function importCategory(Repository $repository, ConnectionInterface $db, stdClass $categoryDto, ?Category $parentCategoryModel = null): void
    {
        if ($categoryDto->category_type !== 'C') {
            return;
        }

        $code = $this->getProjectName() . $categoryDto->category_id;

        if (Category::query()->where('code', $code)->exists()) {
            return;
        }

        /** @var \stdClass|null $categoryTranslation */
        $categoryTranslation = $db->table('category_descriptions')
            ->where([
                'lang_code'   => 'en',
                'category_id' => $categoryDto->category_id
            ])->first();

        if ($categoryTranslation === null) {
            return;
        }

        $categorySeoName = $db->table('seo_names')->where(['object_id' => $categoryDto->category_id, 'type' => 'c', 'lang_code' => 'en'])->value('name');

        $input = new CategoryInput();
        $input->code = $code;
        $input->status = $categoryDto->status === 'A' ? ObjectStatusEnum::ACTIVE : ObjectStatusEnum::DISABLE;
        $input->translation = new CategoryTranslationInput();
        $input->translation->name = $categoryTranslation->category;
        $input->translation->description = $categoryTranslation->description;
        $input->seo_name = $categorySeoName;

        if ($parentCategoryModel === null) {
            $input->storefronts->addAttachId(1);
        } else {
            $input->parent->setAssociateModel($parentCategoryModel);
        }

        $result = $repository->create(new CreateOperation($this->getContext(), $input));

        /** @var \CSCart\Core\Catalog\Category\Model\Category $categoryModel */
        $categoryModel = $result->data;

        $db->table('categories')->where('parent_id', $categoryDto->category_id)->eachById(fn ($categoryDto) => $this->importCategory($repository, $db, $categoryDto, $categoryModel), 100, 'category_id');
    }
}
