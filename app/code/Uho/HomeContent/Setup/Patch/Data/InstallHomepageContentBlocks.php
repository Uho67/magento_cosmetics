<?php

declare(strict_types=1);

namespace Uho\HomeContent\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Model\BlockFactory;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory as BlockCollectionFactory;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\Store;

/**
 * Seeds the CMS static blocks the seed-store theme's homepage layout
 * (Magento_Cms::layout/cms_index_index.xml) renders into. Content lives here
 * so it ships in Ukrainian by default while staying editable from
 * Content > Blocks without a deploy - see docs/BRAND_GUIDE.md for tone of
 * voice notes.
 *
 * The hero CTA link points at the store root ({{store url=''}}) as a safe
 * placeholder until a real "shop all" landing page exists - update it in
 * Content > Blocks once one does. The featured-categories tiles are no
 * longer seeded here: home.categories now renders live level-2 categories
 * via Uho\HomeContent\Block\FeaturedCategories instead of CMS content.
 */
class InstallHomepageContentBlocks implements DataPatchInterface
{
    public function __construct(
        private readonly BlockFactory $blockFactory,
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly BlockCollectionFactory $blockCollectionFactory,
    ) {
    }

    public function apply(): self
    {
        foreach ($this->getBlocks() as $data) {
            if ($this->blockExists($data['identifier'])) {
                continue;
            }

            $block = $this->blockFactory->create();
            $block->setData($data + [
                'stores' => [Store::DEFAULT_STORE_ID],
                'is_active' => 1,
            ]);
            $this->blockRepository->save($block);
        }

        return $this;
    }

    private function blockExists(string $identifier): bool
    {
        $collection = $this->blockCollectionFactory->create();
        $collection->addFieldToFilter('identifier', $identifier);

        return $collection->getSize() > 0;
    }

    private function getBlocks(): array
    {
        return [
            [
                'identifier' => 'home_hero_banner',
                'title' => 'Головна — банер (hero)',
                'content' => <<<HTML
                    <div class="home-hero__inner">
                        <h1 class="home-hero__title">Сіємо разом</h1>
                        <p class="home-hero__subtitle">Насіння овочів, квітів і зелені — з гарантією схожості, для кожного сезону.</p>
                        <a class="home-hero__cta action primary" href="{{store url=''}}">Обрати насіння</a>
                    </div>
                    HTML,
            ],
            [
                'identifier' => 'home_trust_badges',
                'title' => 'Головна — чому обирають нас',
                'content' => <<<HTML
                    <div class="trust-badges__item">
                        <span class="trust-badges__title">Гарантія схожості</span>
                        <span class="trust-badges__desc">Перевірене насіння з підтвердженим відсотком схожості</span>
                    </div>
                    <div class="trust-badges__item">
                        <span class="trust-badges__title">Паковання під сезон</span>
                        <span class="trust-badges__desc">Свіже насіння, розфасоване до початку сезону висадки</span>
                    </div>
                    <div class="trust-badges__item">
                        <span class="trust-badges__title">Доставка по всій Україні</span>
                        <span class="trust-badges__desc">Відправляємо Новою поштою у будь-яке місто чи село</span>
                    </div>
                    HTML,
            ],
            [
                'identifier' => 'home_newsletter_intro',
                'title' => 'Головна — розсилка (текст)',
                'content' => <<<HTML
                    <h2 class="home-newsletter__title">Будьте в курсі нового врожаю</h2>
                    <p class="home-newsletter__subtitle">Підпишіться на розсилку — повідомимо про нові сорти та початок сезону висадки.</p>
                    HTML,
            ],
        ];
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
