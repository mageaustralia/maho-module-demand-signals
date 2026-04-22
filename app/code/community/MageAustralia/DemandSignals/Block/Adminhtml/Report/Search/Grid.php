<?php

declare(strict_types=1);

/**
 * Unmet Search Demand grid. Aggregates zero-result search signals by
 * normalised query key across the current month.
 */
class MageAustralia_DemandSignals_Block_Adminhtml_Report_Search_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('mageaustralia_demandsignals_search_grid');
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
                'entity_key'      => 'entity_key',
                'store_id'        => new Varien_Db_Expr('MAX(store_id)'),
                'composite_score' => new Varien_Db_Expr('SUM(score)'),
                'total_events'    => new Varien_Db_Expr('SUM(event_count)'),
                'unique_people'   => new Varien_Db_Expr('SUM(unique_identifier_count)'),
            ])
            ->where('entity_type = ?', 'search_term')
            ->where('signal_type = ?', MageAustralia_DemandSignals_Helper_Data::SIGNAL_SEARCH_NO_RESULTS)
            ->where('period = ?', $period)
            ->where('entity_key IS NOT NULL')
            ->group('entity_key')
            ->order(new Varien_Db_Expr('composite_score DESC'))
            ->limit(500);

        $collection->getSelect()->reset()->from(['t' => new Varien_Db_Expr('(' . $select . ')')]);
        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $helper = Mage::helper('mageaustralia_demandsignals');

        $this->addColumn('entity_key', [
            'header' => $helper->__('Search Query'),
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
            'header' => $helper->__('Unique Searchers'),
            'index'  => 'unique_people',
            'type'   => 'number',
        ]);

        return parent::_prepareColumns();
    }

    public function getRowUrl($row)
    {
        return false;
    }

    public function getGridUrl()
    {
        return $this->getUrl('*/*/searchGrid', ['_current' => true]);
    }
}
