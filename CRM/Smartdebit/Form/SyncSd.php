<?php
/*--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
+--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2017                                |
+--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +-------------------------------------------------------------------*/

/**
 * Class CRM_Smartdebit_Form_SyncSd
 *
 * This form retrieves a list of AUDDIS / ARUDD dates and displays them for selection.
 * This is the second page in the import process (starting at civicrm/smartdebit/syncsd)
 *
 * Path: civicrm/smartdebit/syncsd/select
 */
class CRM_Smartdebit_Form_SyncSd extends CRM_Core_Form {
  private $_auddisArray = NULL;
  private $_aruddArray = NULL;
  private $_auddisProcessor;

  /**
   * Retrieves a list of available AUDDIS / ARUDD dates and displays them for selection
   */
  public function buildQuickForm() {
    // Get date of collection (or set to today if not set)
    $dateOfCollectionEnd = CRM_Utils_Request::retrieve('collection_date', 'String', $this, false);
    if (empty($dateOfCollectionEnd)) {
      $now = new DateTime();
      $dateOfCollectionEnd = date('Y-m-d', (string)$now->getTimestamp()); // Today
    }
    $dateOfCollectionStart = date('Y-m-d', strtotime($dateOfCollectionEnd . CRM_Smartdebit_Sync::COLLECTION_REPORT_AGE));
    $this->assign('dateOfCollectionEnd', $dateOfCollectionEnd);
    $this->assign('dateOfCollectionStart', $dateOfCollectionStart);

    // Get list of Auddis/Arudd
    $this->_auddisProcessor = new CRM_Smartdebit_Auddis();
    if ($this->_auddisProcessor->getSmartdebitAuddisList($dateOfCollectionStart, $dateOfCollectionEnd)) {
      $this->_auddisArray = $this->_auddisProcessor->getAuddisList();
    }
    if ($this->_auddisProcessor->getSmartdebitAruddList($dateOfCollectionStart, $dateOfCollectionEnd)) {
      $this->_aruddArray = $this->_auddisProcessor->getAruddList();
    }

    // get auddis/arudd dates for processing
    if ($this->_auddisProcessor->getAuddisDates()) {
      $auddisDates = $this->_auddisProcessor->getAuddisDatesList();
    }
    else {
      $auddisDates = array();
    }

    if ($this->_auddisProcessor->getAruddDates()) {
      $aruddDates = $this->_auddisProcessor->getAruddDatesList();
    }
    else {
      $aruddDates = array();
    }

    if (count($auddisDates) <= 10) {
      // setting minimum height to 2 since widget looks strange when size (height) is 1
      $groupSize = max(count($auddisDates), 2);
    }
    else {
      $groupSize = 10;
    }

    if (count($aruddDates) <= 10) {
      // setting minimum height to 2 since widget looks strange when size (height) is 1
      $groupSizeArudd = max(count($aruddDates), 2);
    }
    else {
      $groupSizeArudd = 10;
    }

    $inG = &$this->addElement('advmultiselect', 'includeAuddisDate',
      ts('Include Auddis Date(s)') . ' ',
      $auddisDates,
      array(
        'size' => $groupSize,
        'style' => 'width:auto; min-width:240px;',
        'class' => 'advmultiselect',
      )
    );

    $inGarudd = &$this->addElement('advmultiselect', 'includeAruddDate',
      ts('Include Arudd Date(s)') . ' ',
      $aruddDates,
      array(
        'size' => $groupSizeArudd,
        'style' => 'width:auto; min-width:240px;',
        'class' => 'advmultiselect',
      )
    );

    $this->assign('groupCount', count($auddisDates));
    $this->assign('groupCountArudd', count($aruddDates));

    $auddisDatesArray = array('' => ts('- select -'));
    $aruddDatesArray = array('' => ts('- select -'));
    if (!empty($auddisDates)) {
      $auddisDatesArray = $auddisDatesArray + $auddisDates;
    }
    if (!empty($aruddDates)) {
      $aruddDatesArray = $aruddDatesArray + $aruddDates;
    }
    $this->addElement('select', 'auddis_date', ts('Auddis Date'), $auddisDatesArray);
    $this->addElement('select', 'arudd_date', ts('Arudd Date'), $aruddDatesArray);
    $this->assign('dateOfCollectionStart', $dateOfCollectionStart);
    $this->assign('dateOfCollectionEnd', $dateOfCollectionEnd);

    $redirectUrlBack = CRM_Utils_System::url('civicrm/smartdebit/syncsd', 'reset=1');

    $this->addButtons(array(
        array(
          'type' => 'back',
          'js' => array('onclick' => "location.href='{$redirectUrlBack}'; return false;"),
          'name' => ts('Change Date Range'),
        ),
        array(
          'type' => 'submit',
          'name' => ts('Continue'),
          'isDefault' => TRUE,
        ),
      )
    );
    CRM_Utils_System::setTitle('Synchronise CiviCRM with Smart Debit: Choose data');
    parent::buildQuickForm();
  }

  function postProcess() {
    $params = $this->controller->exportValues();
    $auddisDates = $params['includeAuddisDate'];
    $aruddDates = $params['includeAruddDate'];

    // Make the query string to send in the url for the next page
    $queryParams = '';
    // Get IDs for processing
    $auddisIDs = $this->_auddisProcessor->getAuddisIDsForProcessing($auddisDates);
    $aruddIDs = $this->_auddisProcessor->getAruddIDsForProcessing($aruddDates);

    if (!empty($queryParams)) { $queryParams.='&'; }
    if (isset($auddisIDs)) {
      $queryParams .= "auddisID=" . urlencode(implode(',',$auddisIDs));
    }

    if (!empty($queryParams)) { $queryParams.='&'; }
    if (isset($aruddIDs)) {
      $queryParams .= "aruddID=" . urlencode(implode(',',$aruddIDs));
    }

    if (!empty($queryParams)) { $queryParams.='&'; }
    $queryParams .= 'reset=1';

    CRM_Utils_System::redirect(CRM_Utils_System::url( 'civicrm/smartdebit/syncsd/auddis', $queryParams));
    parent::postProcess();
  }
}
