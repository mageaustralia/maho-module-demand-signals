<?php

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();

// ── event (raw signal log) ──────────────────────────────────────────────────
$eventTable = $installer->getTable('mageaustralia_demandsignals/event');
if (!$connection->isTableExists($eventTable)) {
    $ddl = $connection->newTable($eventTable)
        ->addColumn('event_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, [
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ])
        ->addColumn('signal_type', Varien_Db_Ddl_Table::TYPE_VARCHAR, 32, [
            'nullable' => false,
        ], 'wishlist / added_to_cart / cart_abandoned / search_no_results / oos_view / explicit_restock')
        ->addColumn('entity_type', Varien_Db_Ddl_Table::TYPE_VARCHAR, 16, [
            'nullable' => false,
        ], 'product / category / search_term')
        ->addColumn('entity_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true, 'nullable' => true,
        ])
        ->addColumn('entity_key', Varien_Db_Ddl_Table::TYPE_VARCHAR, 255, [
            'nullable' => true,
        ], 'SKU or search query string - stable across reindex')
        ->addColumn('store_id', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 0,
        ])
        ->addColumn('customer_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true, 'nullable' => true,
        ])
        ->addColumn('session_id', Varien_Db_Ddl_Table::TYPE_VARCHAR, 64, [
            'nullable' => true,
        ], 'Truncated SHA-256 of the raw session id (see Helper::hashSessionId). Never the raw PHPSESSID - a leak would otherwise be a session-hijack primitive until the session expires.')
        ->addColumn('weight', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 1,
        ])
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [
            'nullable' => false,
        ])
        ->addColumn('meta', Varien_Db_Ddl_Table::TYPE_TEXT, '4K', [
            'nullable' => true,
        ], 'JSON blob for signal-specific extras')
        ->addIndex(
            $installer->getIdxName('mageaustralia_demandsignals/event', ['entity_type', 'entity_id', 'created_at']),
            ['entity_type', 'entity_id', 'created_at'],
        )
        ->addIndex(
            $installer->getIdxName('mageaustralia_demandsignals/event', ['signal_type', 'created_at']),
            ['signal_type', 'created_at'],
        )
        ->addIndex(
            $installer->getIdxName('mageaustralia_demandsignals/event', ['store_id', 'created_at']),
            ['store_id', 'created_at'],
        )
        ->setComment('Raw demand-signal event log. Retention bounded to configurable days (default 90).');
    $connection->createTable($ddl);
}

// ── aggregate (rolled-up scores) ────────────────────────────────────────────
$aggregateTable = $installer->getTable('mageaustralia_demandsignals/aggregate');
if (!$connection->isTableExists($aggregateTable)) {
    $ddl = $connection->newTable($aggregateTable)
        ->addColumn('aggregate_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, [
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ])
        ->addColumn('period', Varien_Db_Ddl_Table::TYPE_VARCHAR, 10, [
            'nullable' => false,
        ], 'YYYY-MM for monthly rollup, YYYY-Www for weekly')
        ->addColumn('entity_type', Varien_Db_Ddl_Table::TYPE_VARCHAR, 16, ['nullable' => false])
        ->addColumn('entity_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true, 'nullable' => true,
        ])
        ->addColumn('entity_key', Varien_Db_Ddl_Table::TYPE_VARCHAR, 255, ['nullable' => true])
        ->addColumn('store_id', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 0,
        ])
        ->addColumn('signal_type', Varien_Db_Ddl_Table::TYPE_VARCHAR, 32, ['nullable' => false])
        ->addColumn('event_count', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 0,
        ])
        ->addColumn('unique_identifier_count', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 0,
        ], 'distinct customer_id union session_id')
        ->addColumn('score', Varien_Db_Ddl_Table::TYPE_DECIMAL, '12,4', [
            'nullable' => false, 'default' => '0',
        ], 'event_count * current weight')
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [
            'nullable' => false,
        ])
        ->addIndex(
            $installer->getIdxName(
                'mageaustralia_demandsignals/aggregate',
                ['period', 'entity_type', 'entity_id', 'store_id', 'signal_type'],
                Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE,
            ),
            ['period', 'entity_type', 'entity_id', 'store_id', 'signal_type'],
            ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE],
        )
        ->addIndex(
            $installer->getIdxName('mageaustralia_demandsignals/aggregate', ['period', 'score']),
            ['period', 'score'],
        )
        ->setComment('Rolled-up demand signals. Permanent (not pruned).');
    $connection->createTable($ddl);
}

$installer->endSetup();
