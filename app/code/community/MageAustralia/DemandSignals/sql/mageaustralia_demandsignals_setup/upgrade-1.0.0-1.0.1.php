<?php

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$eventTable = $installer->getTable('mageaustralia_demandsignals/event');

// quote_id: dedicated indexed column for cart-abandoned dedup. Earlier
// versions LIKE-matched '%"quote_id":N%' on the meta JSON blob, which
// falsely matched 10 against 100/1000. A typed, nullable, indexed column
// lets us do an exact equality check.
if ($connection->isTableExists($eventTable) && !$connection->tableColumnExists($eventTable, 'quote_id')) {
    $connection->addColumn($eventTable, 'quote_id', [
        'type'     => Varien_Db_Ddl_Table::TYPE_INTEGER,
        'unsigned' => true,
        'nullable' => true,
        'comment'  => 'Quote id for cart_abandoned dedup; null for other signal types',
    ]);
    $connection->addIndex(
        $eventTable,
        $installer->getIdxName('mageaustralia_demandsignals/event', ['signal_type', 'quote_id']),
        ['signal_type', 'quote_id'],
    );
}

$installer->endSetup();
