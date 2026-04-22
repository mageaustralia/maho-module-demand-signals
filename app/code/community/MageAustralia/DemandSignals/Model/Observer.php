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
    }

    public function onCartAdd(Varien_Event_Observer $observer): void
    {
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
    }

    public function onSearchQuerySave(Varien_Event_Observer $observer): void
    {
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
    }

    public function onProductView(Varien_Event_Observer $observer): void
    {
        /** @var MageAustralia_DemandSignals_Helper_Data $helper */
        $helper = Mage::helper('mageaustralia_demandsignals');
        $product = $observer->getProduct();
        if (!$product instanceof Mage_Catalog_Model_Product) {
            return;
        }
        // Only an "OOS view" counts as a signal. In-stock views are
        // ordinary noise.
        $stockItem = $product->getStockItem();
        if ($stockItem && (bool) $stockItem->getIsInStock()) {
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
