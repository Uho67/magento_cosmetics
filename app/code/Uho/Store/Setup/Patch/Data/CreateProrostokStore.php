<?php

declare(strict_types=1);

namespace Uho\Store\Setup\Patch\Data;

use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\GroupFactory;
use Magento\Store\Model\ResourceModel\Group as GroupResource;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\Group;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;
use Magento\Store\Model\WebsiteFactory;

class CreateProrostokStore implements DataPatchInterface
{
    private const string WEBSITE_CODE = 'pr';
    private const string WEBSITE_NAME = 'Проросток';
    private const int WEBSITE_SORT_ORDER = 10;
    private const string GROUP_CODE = 'pr';
    private const string GROUP_NAME = 'Проросток';
    private const int ROOT_CATEGORY_ID = 2;
    private const string STORE_CODE = 'pr_ua';
    private const string STORE_NAME = 'Проросток';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly WebsiteFactory $websiteFactory,
        private readonly GroupFactory $groupFactory,
        private readonly StoreFactory $storeFactory,
        private readonly WebsiteResource $websiteResource,
        private readonly GroupResource $groupResource,
        private readonly StoreResource $storeResource,
        private readonly ConfigResource $configResource,
        private readonly ReinitableConfigInterface $configReinit,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @throws AlreadyExistsException
     */
    public function apply(): self
    {
        if ($this->websiteExists()) {
            return $this;
        }

        $this->moduleDataSetup->startSetup();

        $website = $this->createWebsite();
        $group = $this->createStoreGroup($website);
        $store = $this->createStoreView($website, $group);
        $this->saveStoreConfig((int) $store->getId(), (int) $website->getId());

        $this->moduleDataSetup->endSetup();
        $this->configReinit->reinit();

        return $this;
    }

    private function websiteExists(): bool
    {
        return isset($this->storeManager->getWebsites(false, true)[self::WEBSITE_CODE]);
    }

    /**
     * @throws AlreadyExistsException
     */
    private function createWebsite(): Website
    {
        $website = $this->websiteFactory->create();
        $website->setCode(self::WEBSITE_CODE);
        $website->setName(self::WEBSITE_NAME);
        $website->setSortOrder(self::WEBSITE_SORT_ORDER);
        $this->websiteResource->save($website);

        return $website;
    }

    /**
     * @throws AlreadyExistsException
     */
    private function createStoreGroup(Website $website): Group
    {
        $group = $this->groupFactory->create();
        $group->setCode(self::GROUP_CODE);
        $group->setWebsiteId((int) $website->getId());
        $group->setName(self::GROUP_NAME);
        $group->setRootCategoryId(self::ROOT_CATEGORY_ID);
        $this->groupResource->save($group);

        $website->setDefaultGroupId((int) $group->getId());
        $this->websiteResource->save($website);

        return $group;
    }

    /**
     * @throws AlreadyExistsException
     */
    private function createStoreView(
        Website $website,
        Group $group,
    ): Store {
        $store = $this->storeFactory->create();
        $store->setCode(self::STORE_CODE);
        $store->setName(self::STORE_NAME);
        $store->setWebsiteId((int) $website->getId());
        $store->setGroupId((int) $group->getId());
        $store->setIsActive(1);
        $this->storeResource->save($store);

        $group->setDefaultStoreId((int) $store->getId());
        $this->groupResource->save($group);

        return $store;
    }

    private function saveStoreConfig(int $storeId, int $websiteId): void
    {
        $this->configResource->saveConfig('general/locale/code', 'uk_UA', 'stores', $storeId);
        $this->configResource->saveConfig('currency/options/base', 'UAH', 'stores', $storeId);
        $this->configResource->saveConfig('currency/options/default', 'UAH', 'stores', $storeId);
        $this->configResource->saveConfig('currency/options/allow', 'UAH', 'stores', $storeId);
        $this->configResource->saveConfig('catalog/price/scope', '1', 'websites', $websiteId);
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
