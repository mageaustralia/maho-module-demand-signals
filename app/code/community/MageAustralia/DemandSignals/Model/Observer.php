<?php

declare(strict_types=1);

/**
 * Observers that record raw demand signals. Each is thin: bail early when
 * the signal type is disabled, otherwise write one row to
 * `mageaustralia_demandsignals_event` and return. Storefront latency must
 * not depend on anything this module does.
 */
class MageAustralia_DemandSignals_Model_Observer
{
    public function onWishlistAdd(Varien_Event_Observer $observer): void
    {
        try {
            /** @var MageAustralia_DemandSignals_Helper_Data $helper */
            $helper  = Mage::helper('mageaustralia_demandsignals');
            $storeId = (int) Mage::app()->getStore()->getId();
            $product = $observer->getProduct();
            if (!$product instanceof Mage_Catalog_Model_Product) {
                return;
            }
            $helper->recordEvent(
                MageAustralia_DemandSignals_Helper_Data::SIGNAL_WISHLIST,
                entityType: 'product',
                entityId: (int) $product->getId(),
                entityKey: (string) $product->getSku(),
                storeId: $storeId,
                customerId: $this->currentCustomerId(),
                sessionId: $this->currentSessionId(),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    public function onCartAdd(Varien_Event_Observer $observer): void
    {
        try {
            /** @var MageAustralia_DemandSignals_Helper_Data $helper */
            $helper  = Mage::helper('mageaustralia_demandsignals');
            $storeId = (int) Mage::app()->getStore()->getId();
            $product = $observer->getProduct();
            if (!$product instanceof Mage_Catalog_Model_Product) {
                return;
            }
            $helper->recordEvent(
                MageAustralia_DemandSignals_Helper_Data::SIGNAL_ADDED_TO_CART,
                entityType: 'product',
                entityId: (int) $product->getId(),
                entityKey: (string) $product->getSku(),
                storeId: $storeId,
                customerId: $this->currentCustomerId(),
                sessionId: $this->currentSessionId(),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    public function onSearchQuerySave(Varien_Event_Observer $observer): void
    {
        try {
            /** @var MageAustralia_DemandSignals_Helper_Data $helper */
            $helper = Mage::helper('mageaustralia_demandsignals');
            $query  = $observer->getDataObject() ?: $observer->getEvent()->getDataObject();
            if (!is_object($query)) {
                return;
            }
            if ((int) $query->getNumResults() !== 0) {
                return;
            }
            $text = trim((string) $query->getQueryText());
            if ($text === '') {
                return;
            }
            // Light dedup key: lowercased + whitespace-collapsed. Anything
            // smarter (stemming, semantic dedup) is out of scope for v1.
            $normalised = strtolower(preg_replace('/\s+/u', ' ', $text) ?? '');

            $helper->recordEvent(
                MageAustralia_DemandSignals_Helper_Data::SIGNAL_SEARCH_NO_RESULTS,
                entityType: 'search_term',
                entityId: $query->getId() ? (int) $query->getId() : null,
                entityKey: $normalised,
                storeId: (int) $query->getStoreId() ?: (int) Mage::app()->getStore()->getId(),
                customerId: $this->currentCustomerId(),
                sessionId: $this->currentSessionId(),
                meta: ['raw_query' => $text],
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    public function onProductView(Varien_Event_Observer $observer): void
    {
        try {
            /** @var MageAustralia_DemandSignals_Helper_Data $helper */
            $helper = Mage::helper('mageaustralia_demandsignals');
            $product = $observer->getProduct();
            if (!$product instanceof Mage_Catalog_Model_Product) {
                return;
            }
            // "Unbuyable" view = OOS signal. Three paths lead here:
            //   - stock item marked out of stock (simple products)
            //   - product status disabled (pulled from sale)
            //   - catalog inventory says not salable (composite / configurable
            //     products whose children are all OOS - getStockItem() on
            //     the parent returns null)
            // Any in-stock + enabled + salable view is ordinary noise.
            $stockItem = $product->getStockItem();
            $stockSaysInStock = $stockItem ? (bool) $stockItem->getIsInStock() : null;
            $statusEnabled = ((int) $product->getStatus()) === 1;
            $isSalable = (bool) $product->isSalable();

            if ($stockSaysInStock === true && $statusEnabled && $isSalable) {
                return;
            }
            // If stock item is missing (composite) and the product is both
            // enabled and salable, we have no signal to record.
            if ($stockSaysInStock === null && $statusEnabled && $isSalable) {
                return;
            }

            $helper->recordEvent(
                MageAustralia_DemandSignals_Helper_Data::SIGNAL_OOS_VIEW,
                entityType: 'product',
                entityId: (int) $product->getId(),
                entityKey: (string) $product->getSku(),
                storeId: (int) Mage::app()->getStore()->getId(),
                customerId: $this->currentCustomerId(),
                sessionId: $this->currentSessionId(),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    private function currentCustomerId(): ?int
    {
        try {
            /** @var Mage_Customer_Model_Session $session */
            $session = Mage::getSingleton('customer/session');
            $id = (int) $session->getCustomerId();
            return $id > 0 ? $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function currentSessionId(): ?string
    {
        try {
            /** @var Mage_Core_Model_Session $session */
            $session = Mage::getSingleton('core/session');
            $id = (string) $session->getSessionId();
            return $id !== '' ? substr($id, 0, 64) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
