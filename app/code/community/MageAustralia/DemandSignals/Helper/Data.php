<?php

declare(strict_types=1);

class MageAustralia_DemandSignals_Helper_Data extends Mage_Core_Helper_Abstract
{
    protected $_moduleName = 'MageAustralia_DemandSignals';

    public const SIGNAL_WISHLIST          = 'wishlist';
    public const SIGNAL_ADDED_TO_CART     = 'added_to_cart';
    public const SIGNAL_CART_ABANDONED    = 'cart_abandoned';
    public const SIGNAL_SEARCH_NO_RESULTS = 'search_no_results';
    public const SIGNAL_OOS_VIEW          = 'oos_view';
    public const SIGNAL_EXPLICIT_RESTOCK  = 'explicit_restock';

    /** @return list<string> */
    public function getAllSignalTypes(): array
    {
        return [
            self::SIGNAL_WISHLIST,
            self::SIGNAL_ADDED_TO_CART,
            self::SIGNAL_CART_ABANDONED,
            self::SIGNAL_SEARCH_NO_RESULTS,
            self::SIGNAL_OOS_VIEW,
            self::SIGNAL_EXPLICIT_RESTOCK,
        ];
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return (bool) Mage::getStoreConfig('mageaustralia_demandsignals/general/enabled', $storeId);
    }

    /**
     * Whether collection of a specific signal type is on. Used by observers
     * to bail early when a merchant has disabled a category of signals
     * (e.g. not interested in cart-abandoned scanning).
     */
    public function collects(string $signalType, ?int $storeId = null): bool
    {
        if (!$this->isEnabled($storeId)) {
            return false;
        }
        $path = 'mageaustralia_demandsignals/signals/collect_' . $signalType;
        return (bool) Mage::getStoreConfig($path, $storeId);
    }

    public function weightFor(string $signalType, ?int $storeId = null): int
    {
        $v = (int) Mage::getStoreConfig('mageaustralia_demandsignals/weights/' . $signalType, $storeId);
        return $v > 0 ? $v : 1;
    }

    public function retentionDays(?int $storeId = null): int
    {
        $v = (int) Mage::getStoreConfig('mageaustralia_demandsignals/general/retention_days', $storeId);
        return $v > 0 ? $v : 90;
    }

    public function abandonAfterDays(?int $storeId = null): int
    {
        $v = (int) Mage::getStoreConfig('mageaustralia_demandsignals/cart/abandon_after_days', $storeId);
        return $v > 0 ? $v : 7;
    }

    /**
     * Convenience: record a demand-signal event. Thin wrapper over direct
     * INSERT - we prefer raw-SQL speed here because observers fire hot.
     *
     * @param array<string, mixed>|null $meta
     */
    public function recordEvent(
        string $signalType,
        string $entityType,
        ?int $entityId,
        ?string $entityKey,
        int $storeId,
        ?int $customerId = null,
        ?string $sessionId = null,
        ?array $meta = null,
    ): void {
        if (!$this->collects($signalType, $storeId)) {
            return;
        }
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $adapter->insert($resource->getTableName('mageaustralia_demandsignals/event'), [
            'signal_type' => $signalType,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'entity_key'  => $entityKey !== null ? substr($entityKey, 0, 255) : null,
            'store_id'    => $storeId,
            'customer_id' => $customerId,
            'session_id'  => $sessionId !== null ? substr($sessionId, 0, 64) : null,
            'weight'      => $this->weightFor($signalType, $storeId),
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'meta'        => $meta !== null ? json_encode($meta, JSON_THROW_ON_ERROR) : null,
        ]);
    }
}
