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
     * Hash a session id before persistence. The raw PHPSESSID is a session
     * hijack primitive if the event log leaks, so we store a truncated
     * SHA-256 instead. 32 hex chars = 128 bits of entropy, plenty for the
     * dedup/uniqueness use cases the column is there for and with no
     * realistic collision risk across retention windows.
     */
    public function hashSessionId(?string $sessionId): ?string
    {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }
        return substr(hash('sha256', $sessionId), 0, 32);
    }

    /**
     * Convenience: record a demand-signal event. Thin wrapper over direct
     * INSERT - we prefer raw-SQL speed here because observers fire hot.
     *
     * Swallows exceptions: observers call this on hot storefront paths and
     * MUST NOT raise. A DB hiccup or a bad-bytes search term should never
     * 500 the page. We log and move on.
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
        try {
            if (!$this->collects($signalType, $storeId)) {
                return;
            }
            // JSON_UNESCAPED_UNICODE + SLASHES keep the blob compact and
            // readable. No JSON_THROW_ON_ERROR: a non-UTF-8 byte in a
            // search term would otherwise raise inside the observer.
            $metaJson = null;
            if ($meta !== null) {
                $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($metaJson === false) {
                    // Fall back to a scrubbed pass: strip invalid UTF-8
                    // from string leaves and retry. If still bad, drop meta.
                    $scrubbed = $this->scrubForJson($meta);
                    $metaJson = json_encode($scrubbed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($metaJson === false) {
                        $metaJson = null;
                    }
                }
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
                'session_id'  => $this->hashSessionId($sessionId),
                'weight'      => $this->weightFor($signalType, $storeId),
                'created_at'  => gmdate('Y-m-d H:i:s'),
                'meta'        => $metaJson,
            ]);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    /**
     * Recursively strip invalid UTF-8 from string leaves so json_encode
     * won't fail. Used only on the fallback path after a first-try encode
     * returns false.
     *
     * @param mixed $value
     * @return mixed
     */
    private function scrubForJson($value)
    {
        if (is_string($value)) {
            // mb_convert_encoding with //IGNORE-like substitute strips
            // bad byte sequences. The intermediate ISO-8859-1 round-trip
            // is the widely-portable way to do this on PHP 8.
            $clean = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            return is_string($clean) ? $clean : '';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->scrubForJson($v);
            }
            return $out;
        }
        return $value;
    }
}
