<?php

declare(strict_types=1);

/**
 * Rolls raw events up into monthly aggregates and runs the daily sweeps
 * (cart-abandoned detection + retention prune). Idempotent by construction:
 * each rollup computes the full period bucket from source rows and
 * upserts; re-running produces the same numbers.
 */
class MageAustralia_DemandSignals_Model_Aggregator
{
    private const PERIOD_FORMAT = 'Y-m';

    public function rollupHourly(): void
    {
        /** @var MageAustralia_DemandSignals_Helper_Data $helper */
        $helper = Mage::helper('mageaustralia_demandsignals');
        if (!$helper->isEnabled()) {
            return;
        }

        // Recompute the current and previous period every run. Cheap, and
        // correctly folds in late-arriving events that crossed the month
        // boundary since the last rollup.
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->rollupPeriod($now->format(self::PERIOD_FORMAT));
        $this->rollupPeriod($now->modify('-1 month')->format(self::PERIOD_FORMAT));
    }

    public function runDaily(): void
    {
        /** @var MageAustralia_DemandSignals_Helper_Data $helper */
        $helper = Mage::helper('mageaustralia_demandsignals');
        if (!$helper->isEnabled()) {
            return;
        }

        if ($helper->collects(MageAustralia_DemandSignals_Helper_Data::SIGNAL_CART_ABANDONED)) {
            $this->scanAbandonedCarts($helper->abandonAfterDays());
        }

        $this->pruneRawEvents($helper->retentionDays());
    }

    /**
     * Recompute one period bucket from raw events and upsert into the
     * aggregate table. Safe to re-run: every row is fully recomputed from
     * source, nothing is accumulated incrementally.
     */
    private function rollupPeriod(string $period): void
    {
        /** @var MageAustralia_DemandSignals_Helper_Data $helper */
        $helper   = Mage::helper('mageaustralia_demandsignals');
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $event    = $resource->getTableName('mageaustralia_demandsignals/event');
        $agg      = $resource->getTableName('mageaustralia_demandsignals/aggregate');

        [$start, $end] = $this->periodBounds($period);

        $select = $adapter->select()
            ->from($event, [
                'entity_type' => 'entity_type',
                'entity_id'   => 'entity_id',
                'entity_key'  => 'entity_key',
                'store_id'    => 'store_id',
                'signal_type' => 'signal_type',
                'event_count' => new Varien_Db_Expr('COUNT(*)'),
                'unique_identifier_count' => new Varien_Db_Expr(
                    "COUNT(DISTINCT CONCAT(COALESCE(customer_id, 0), ':', COALESCE(session_id, '')))",
                ),
            ])
            ->where('created_at >= ?', $start)
            ->where('created_at < ?', $end)
            ->group(['entity_type', 'entity_id', 'entity_key', 'store_id', 'signal_type']);

        $rows = $adapter->fetchAll($select);
        $now  = gmdate('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $signalType = (string) $row['signal_type'];
            $weight     = $helper->weightFor($signalType, (int) $row['store_id']);
            $count      = (int) $row['event_count'];

            $adapter->insertOnDuplicate(
                $agg,
                [
                    'period'                  => $period,
                    'entity_type'             => (string) $row['entity_type'],
                    'entity_id'               => $row['entity_id'] !== null ? (int) $row['entity_id'] : null,
                    'entity_key'              => $row['entity_key'] !== null ? (string) $row['entity_key'] : null,
                    'store_id'                => (int) $row['store_id'],
                    'signal_type'             => $signalType,
                    'event_count'             => $count,
                    'unique_identifier_count' => (int) $row['unique_identifier_count'],
                    'score'                   => $count * $weight,
                    'updated_at'              => $now,
                ],
                ['event_count', 'unique_identifier_count', 'score', 'updated_at', 'entity_key'],
            );
        }
    }

    /**
     * Walk unconverted quotes older than $afterDays and record one
     * cart_abandoned signal per line item. Keyed on quote_id in meta so
     * re-runs are idempotent against the event table's natural dedup
     * (same quote_id never records twice because we filter on a marker).
     */
    private function scanAbandonedCarts(int $afterDays): void
    {
        /** @var MageAustralia_DemandSignals_Helper_Data $helper */
        $helper   = Mage::helper('mageaustralia_demandsignals');
        $resource = Mage::getSingleton('core/resource');
        $read     = $resource->getConnection('core_read');
        $write    = $resource->getConnection('core_write');

        $quoteTable     = $resource->getTableName('sales/quote');
        $quoteItemTable = $resource->getTableName('sales/quote_item');
        $eventTable     = $resource->getTableName('mageaustralia_demandsignals/event');

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($afterDays * 86400));

        // Quotes still marked active, with items, not converted, older
        // than the cutoff, that we haven't already scanned.
        $select = $read->select()
            ->from(['q' => $quoteTable], [
                'entity_id', 'store_id', 'customer_id', 'items_count', 'updated_at',
            ])
            ->where('q.is_active = ?', 1)
            ->where('q.items_count > 0')
            ->where('q.reserved_order_id IS NULL OR q.reserved_order_id = ""')
            ->where('q.updated_at < ?', $cutoff)
            ->limit(200);

        $quotes = $read->fetchAll($select);
        if (!$quotes) {
            return;
        }

        foreach ($quotes as $quote) {
            $quoteId = (int) $quote['entity_id'];

            // Have we already recorded this quote? meta is a TEXT JSON
            // blob so a LIKE keyed on a stable shape is good enough.
            $already = (int) $read->fetchOne(
                $read->select()
                    ->from($eventTable, [new Varien_Db_Expr('COUNT(*)')])
                    ->where('signal_type = ?', MageAustralia_DemandSignals_Helper_Data::SIGNAL_CART_ABANDONED)
                    ->where('meta LIKE ?', '%"quote_id":' . $quoteId . '%'),
            );
            if ($already > 0) {
                continue;
            }

            $items = $read->fetchAll(
                $read->select()
                    ->from($quoteItemTable, ['product_id', 'sku', 'qty'])
                    ->where('quote_id = ?', $quoteId)
                    ->where('parent_item_id IS NULL'),
            );

            foreach ($items as $item) {
                $write->insert($eventTable, [
                    'signal_type' => MageAustralia_DemandSignals_Helper_Data::SIGNAL_CART_ABANDONED,
                    'entity_type' => 'product',
                    'entity_id'   => (int) $item['product_id'],
                    'entity_key'  => (string) $item['sku'],
                    'store_id'    => (int) $quote['store_id'],
                    'customer_id' => $quote['customer_id'] ? (int) $quote['customer_id'] : null,
                    'session_id'  => null,
                    'weight'      => $helper->weightFor(
                        MageAustralia_DemandSignals_Helper_Data::SIGNAL_CART_ABANDONED,
                        (int) $quote['store_id'],
                    ),
                    'created_at'  => gmdate('Y-m-d H:i:s'),
                    'meta'        => json_encode([
                        'quote_id' => $quoteId,
                        'qty'      => (float) $item['qty'],
                    ], JSON_THROW_ON_ERROR),
                ]);
            }
        }
    }

    private function pruneRawEvents(int $retentionDays): void
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $table    = $resource->getTableName('mageaustralia_demandsignals/event');
        $cutoff   = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));
        $adapter->delete($table, ['created_at < ?' => $cutoff]);
    }

    /** @return array{0: string, 1: string} UTC inclusive-start, exclusive-end */
    private function periodBounds(string $period): array
    {
        $start = DateTimeImmutable::createFromFormat(
            '!Y-m',
            $period,
            new DateTimeZone('UTC'),
        );
        if ($start === false) {
            throw new RuntimeException('Invalid period: ' . $period);
        }
        $end = $start->modify('+1 month');
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }
}
