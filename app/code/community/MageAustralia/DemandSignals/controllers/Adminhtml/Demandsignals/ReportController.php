<?php

declare(strict_types=1);

/**
 * Admin controllers for the two reports surfaced under
 * Reports -> Demand Signals.
 */
class MageAustralia_DemandSignals_Adminhtml_Demandsignals_ReportController extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('report/demandsignals');
    }

    public function productsAction(): void
    {
        $this->loadLayout();
        $this->_setActiveMenu('report/mageaustralia_demandsignals');
        $this->_title($this->__('Reports'))
             ->_title($this->__('Demand Signals'))
             ->_title($this->__('Top Demand-signal Products'));
        $this->_addContent(
            $this->getLayout()->createBlock('mageaustralia_demandsignals/adminhtml_report_products'),
        );
        $this->renderLayout();
    }

    public function searchAction(): void
    {
        $this->loadLayout();
        $this->_setActiveMenu('report/mageaustralia_demandsignals');
        $this->_title($this->__('Reports'))
             ->_title($this->__('Demand Signals'))
             ->_title($this->__('Unmet Search Demand'));
        $this->_addContent(
            $this->getLayout()->createBlock('mageaustralia_demandsignals/adminhtml_report_search'),
        );
        $this->renderLayout();
    }
}
