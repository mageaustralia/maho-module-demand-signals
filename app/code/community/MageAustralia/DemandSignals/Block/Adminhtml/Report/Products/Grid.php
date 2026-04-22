<?php

declare(strict_types=1);

/**
 * Top Demand-signal Products grid. Reads the aggregate table directly and
 * computes a per-product composite score as a weighted sum across signal
 * types for the current period bucket (month).
 */
class MageAustralia_DemandSignals_Block_Adminhtml_Report_Products_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('mageaustralia_demandsignals_products_grid');
        $this->setDefaultSort('composite_score');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(false);
        $this->setUseAjax(true);
        $this->setFilterVisibility(false);
    }

    protected function _prepareCollection()
    {
        $resource = Mage::getSingleton('core/resource');
        $agg      = $resource->getTableName('mageaustralia_demandsignals/aggregate');
        $period   = gmdate('Y-m');

        $collection = new Varien_Data_Collection_Db(
            $resource->getConnection('core_read'),
        );

        $select = $resource->getConnection('core_read')->select()
            ->from($agg, [
                'entity_id'       => 'entity_id',
                'entity_key'      => new Varien_Db_Expr('MAX(entity_key)'),
                'store_id'        => new Varien_Db_Expr('MAX(store_id)'),
                'composite_score' => new Varien_Db_Expr('SUM(score)'),
                'total_events'    => new Varien_Db_Expr('SUM(event_count)'),
                'unique_people'   => new Varien_Db_Expr('SUM(unique_identifier_count)'),
            ])
            ->where('entity_type = ?', 'product')
            ->where('period = ?', $period)
            ->where('entity_id IS NOT NULL')
            ->group('entity_id')
            ->order(new Varien_Db_Expr('composite_score DESC'))
            ->limit(500);

        $collection->getSelect()->reset()->from(['t' => new Varien_Db_Expr('(' . $select . ')')]);
        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $helper = Mage::helper('mageaustralia_demandsignals');

        $this->addColumn('entity_id', [
            'header' => $helper->__('Product ID'),
            'index'  => 'entity_id',
            'type'   => 'number',
            'width'  => '80px',
        ]);
        $this->addColumn('entity_key', [
            'header' => $helper->__('SKU'),
            'index'  => 'entity_key',
        ]);
        $this->addColumn('composite_score', [
            'header' => $helper->__('Composite Score'),
            'index'  => 'composite_score',
            'type'   => 'number',
        ]);
        $this->addColumn('total_events', [
            'header' => $helper->__('Events'),
            'index'  => 'total_events',
            'type'   => 'number',
        ]);
        $this->addColumn('unique_people', [
            'header' => $helper->__('Unique Customers/Sessions'),
            'index'  => 'unique_people',
            'type'   => 'number',
        ]);
        $this->addColumn('action', [
            'header'    => $helper->__('View'),
            'type'      => 'action',
            'getter'    => 'getEntityId',
            'actions'   => [[
                'caption' => $helper->__('Open product'),
                'url'     => ['base' => 'adminhtml/catalog_product/edit'],
                'field'   => 'id',
            ]],
            'filter'    => false,
            'sortable'  => false,
            'is_system' => true,
        ]);

        return parent::_prepareColumns();
    }

    public function getRowUrl($row)
    {
        return $this->getUrl('adminhtml/catalog_product/edit', ['id' => $row->getEntityId()]);
    }

    public function getGridUrl()
    {
        return $this->getUrl('*/*/productsGrid', ['_current' => true]);
    }
}
