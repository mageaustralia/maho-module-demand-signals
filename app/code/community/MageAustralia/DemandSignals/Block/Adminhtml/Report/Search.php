<?php

declare(strict_types=1);

class MageAustralia_DemandSignals_Block_Adminhtml_Report_Search extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_controller = 'adminhtml_report_search';
        $this->_blockGroup = 'mageaustralia_demandsignals';
        $this->_headerText = Mage::helper('mageaustralia_demandsignals')->__('Unmet Search Demand');
        parent::__construct();
        $this->_removeButton('add');
    }
}
