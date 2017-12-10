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
 * Class CRM_Core_Payment_Smartdebit
 *
 * Implementation of the Smartdebit payment processor class
 */
class CRM_Core_Payment_Smartdebit extends CRM_Core_Payment
{
  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * mode of operation: live or test
   *
   * @var object
   */
  protected $_mode = NULL;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   * @param $paymentProcessor
   */
  function __construct($mode, &$paymentProcessor)
  {
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Smart Debit Processor');
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   * @param $paymentProcessor
   * @return object
   * @static
   *
   */
  static function &singleton($mode, &$paymentProcessor, &$paymentForm = NULL, $force = FALSE)
  {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new self($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  /**
   * This function checks to see if we have the right config values
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig()
  {
    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "username" is not set in the Smart Debit Payment Processor settings.');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('The "password" is not set in the Smart Debit Payment Processor settings.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Get Smart Debit User Details
   *
   * @param null $id
   * @param bool $test
   * @param bool $isActive
   *
   * @return array|bool
   */
  public static function getProcessorDetails($id = NULL, $test = FALSE, $isActive = TRUE) {
    $params = array(
      'is_test' => $test,
      'is_active' => $isActive,
      'domain_id' => CRM_Core_Config::domainID(),
    );
    if (!empty($id)) {
      $params['id'] = $id;
    }
    else {
      $paymentProcessorTypeId = CRM_Core_PseudoConstant::getKey('CRM_Financial_BAO_PaymentProcessor', 'payment_processor_type_id', 'Smart_Debit');
      $params['payment_processor_type_id'] = $paymentProcessorTypeId;
      $params['options'] = array('sort' => "id DESC", 'limit' => 1);
    }

    try {
      $paymentProcessorDetails = civicrm_api3('PaymentProcessor', 'getsingle', $params);
    }
    catch (Exception $e) {
      CRM_Core_Session::setStatus(ts('Smart Debit API User Details Missing, Please check the Smart Debit Payment Processor is configured Properly'), 'Smart Debit', 'error');
      return FALSE;
    }

    return $paymentProcessorDetails;
  }

  /**
   * @param CRM_Core_Form $form
   * @return bool|void
   */
  function buildForm(&$form)
  {
    if ($form->isSubmitted()) return;

    // Set ddi_reference
    $defaults = array();
    $defaults['ddi_reference'] = CRM_Smartdebit_Base::getDDIReference();
    // Set preferred collection day default to the first choice.
    $collectionDaysArray = CRM_Smartdebit_Base::getCollectionDaysOptions();
    if (count($collectionDaysArray) > 0) {
      $defaults['preferred_collection_day'] = reset(array_keys($collectionDaysArray));
    }

    // Set default confirmby option
    $confirmBy = CRM_Smartdebit_Base::getConfirmByOptions();
    if (count($confirmBy) > 0) {
      $defaults['confirmation_method'] = reset(array_keys($confirmBy));
    }
    $form->setDefaults($defaults);

    // Add help and javascript
    CRM_Core_Region::instance('billing-block')->add(
      array('template' => 'CRM/Core/Payment/Smartdebit/Smartdebit.tpl', 'weight' => -1));

    return;
  }

  /**
   * Override custom PI validation
   *  to validate payment details with SmartDebit
   * Sets appropriate parameters and calls Smart Debit API to validate a payment (does not setup the payment)
   *
   * @param array $values
   * @param array $errors
   */
  public function validatePaymentInstrument($values, &$errors) {
    parent::validatePaymentInstrument($values, $errors);

    $smartDebitParams = self::preparePostArray($values);

    // Get the API Username and Password
    $username = $this->_paymentProcessor['user_name'];
    $password = $this->_paymentProcessor['password'];

    $url = CRM_Smartdebit_Api::buildUrl($this->_paymentProcessor, 'api/ddi/variable/validate');
    $response = CRM_Smartdebit_Api::requestPost($url, $smartDebitParams, $username, $password);

    $direct_debit_response = array();
    $direct_debit_response['data_type'] = 'recurring';
    $direct_debit_response['entity_type'] = 'contribution_recur';
    $direct_debit_response['first_collection_date'] = $smartDebitParams['variable_ddi[start_date]'];
    $direct_debit_response['preferred_collection_day'] = $values['preferred_collection_day'];
    $direct_debit_response['confirmation_method'] = $values['confirmation_method'];
    $direct_debit_response['ddi_reference'] = $values['ddi_reference'];
    $direct_debit_response['response_status'] = $response['message'];

    // On success an array is returned, last success element is an array of attributes
    if ((is_array($response['success'])) && isset(end($response['success'])['@attributes'])) {
      foreach (end($response['success'])['@attributes'] as $key => $value) {
        $direct_debit_response[$key] = $value;
      }
    }

    // Take action based upon the response status
    if ($response['success']) {
      $direct_debit_response['entity_id'] = isset($values['entity_id']) ? $values['entity_id'] : 0;
      self::recordSmartDebitResponse($direct_debit_response);
    }
    else {
      self::formatErrorsForContributionForm($response['error'], $errors);
    }
  }

  public static function formatErrorsForContributionForm($responseErrors, &$errors) {
    if (!is_array($responseErrors)) {
      $responseErrors = array($responseErrors);
    }
    foreach ($responseErrors as $error) {
      $shortErr = substr($error, 0, 14);
      switch ($shortErr) {
        case 'Sort code is i': // Sort code ..
          $errors['bank_identification_number'] = CRM_Utils_Array::value('bank_identification_number', $errors) . $error . '. ';
          break;
        case 'Account number': // Account number ..
          $errors['bank_account_number'] = CRM_Utils_Array::value('bank_account_number', $errors) . $error . '. ';
          break;
        case 'Account name i': // Account name ..
          $errors['account_holder'] = CRM_Utils_Array::value('account_holder', $errors) . $error . '. ';
          break;
        case 'Start date mus': // Start date ..
          $errors['preferred_collection_day'] = CRM_Utils_Array::value('preferred_collection_day', $errors) . $error . '. ';
          break;
          default:
            $errors['unknown'] = CRM_Utils_Array::value('unknown', $errors) . $error . '. ';
      }
    }
    if (isset($errors['unknown'])) {
      CRM_Core_Session::setStatus($errors['unknown'], 'Payment validation error', 'error');
    }
  }

  /**
   * Override CRM_Core_Payment function
   */
  public function getPaymentTypeName() {
    return 'direct_debit';
  }

  /**
   * Override CRM_Core_Payment function
   */
  public function getPaymentTypeLabel() {
    return 'Direct Debit';
  }

  /**
   * Override CRM_Core_Payment function
   */
  public function getPaymentFormFields() {
    return array(
      'payer_confirmation',
      'preferred_collection_day',
      'confirmation_method',
      'account_holder',
      'bank_account_number',
      'bank_identification_number',
      'ddi_reference',
    );
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata() {
    // Get the collection days options
    $collectionDaysArray = CRM_Smartdebit_Base::getCollectionDaysOptions();
    $confirmBy = CRM_Smartdebit_Base::getConfirmByOptions();

    return array(
      'account_holder' => array(
        'htmlType' => 'text',
        'name' => 'account_holder',
        'title' => ts('Account Holder'),
        'cc_field' => TRUE,
        'attributes' => array('size' => 20
        , 'maxlength' => 18
        , 'autocomplete' => 'on'
        ),
        'is_required' => TRUE
      ),
      //e.g. IBAN can have maxlength of 34 digits
      'bank_account_number' => array(
        'htmlType' => 'text',
        'name' => 'bank_account_number',
        'title' => ts('Bank Account Number'),
        'cc_field' => TRUE,
        'attributes' => array('size' => 20
        , 'maxlength' => 34
        , 'autocomplete' => 'off'
        ),
        'is_required' => TRUE
      ),
      //e.g. SWIFT-BIC can have maxlength of 11 digits
      'bank_identification_number' => array(
        'htmlType' => 'text',
        'name' => 'bank_identification_number',
        'title' => ts('Sort Code'),
        'cc_field' => TRUE,
        'attributes' => array('size' => 20
        , 'maxlength' => 11
        , 'autocomplete' => 'off'
        ),
        'is_required' => TRUE
      ),
      'preferred_collection_day' => array(
        'htmlType' => (count($collectionDaysArray) > 1) ? 'select' : 'hidden',
        'name' => 'preferred_collection_day',
        'title' => ts('Preferred Collection Day'),
        'cc_field' => TRUE,
        'attributes' => $collectionDaysArray, // eg. array('1' => '1st', '8' => '8th', '21' => '21st'),
        'is_required' => TRUE
      ),
      'confirmation_method' => array(
        'htmlType' => (count($confirmBy) > 1) ? 'select' : 'hidden',
        'name' => 'confirmation_method',
        'title' => ts('Confirm By'),
        'cc_field' => TRUE,
        'attributes' => $confirmBy,
        'is_required' => TRUE
      ),
      'payer_confirmation' => array(
        'htmlType' => 'checkbox',
        'name' => 'payer_confirmation',
        'title' => ts('Please confirm that you are the account holder and only person required to authorise Direct Debits from this account'),
        'cc_field' => TRUE,
        'attributes' => '',
        'is_required' => TRUE
      ),
      'ddi_reference' => array(
        'htmlType' => 'hidden',
        'name' => 'ddi_reference',
        'title' => 'DDI Reference',
        'cc_field' => TRUE,
        'attributes' => array('size' => 20
        , 'maxlength' => 64
        , 'autocomplete' => 'off'
        ),
        'is_required' => TRUE,
      )
    );
  }

  /**
   * Get form metadata for billing address fields.
   *
   * @param int $billingLocationID
   *
   * @return array
   *    Array of metadata for address fields.
   */
  public function getBillingAddressFieldsMetadata($billingLocationID = NULL) {
    $metadata = parent::getBillingAddressFieldsMetadata($billingLocationID);
    if (!$billingLocationID) {
      // Note that although the billing id is passed around the forms the idea that it would be anything other than
      // the result of the function below doesn't seem to have eventuated.
      // So taking this as a param is possibly something to be removed in favour of the standard default.
      $billingLocationID = CRM_Core_BAO_LocationType::getBilling();
    }

    // State/county field is not required.
    if (!empty($metadata["billing_state_province_id-{$billingLocationID}"]['is_required'])) {
      $metadata["billing_state_province_id-{$billingLocationID}"]['is_required'] = FALSE;
    }

    return $metadata;
  }

  /**
   * Get an array of the fields that can be edited on the recurring contribution.
   *
   * @return array
   */
  public function getEditableRecurringScheduleFields() {
    return array(
      'amount',
      'frequency_interval',
      'frequency_unit',
      'start_date',
    );
  }

  /**
   * Get contact email for POSTing to Smart Debit API
   * @param $params
   * @return mixed
   */
  private static function getUserEmail(&$params)
  {
    $useremail = NULL;
    // Set email
    if (!empty($params['email-Primary'])) {
      $useremail = $params['email-Primary'];
    }
    elseif (!empty($params['email-5'])) {
      $useremail = $params['email-5'];
    }
    else {
      // Get email from contact ID
      $contactId = CRM_Utils_Array::value('cid', $_REQUEST);
      try {
        $emailResult = civicrm_api3('Email', 'getsingle', array(
          'contact_id' => $contactId,
          'options' => array('limit' => 1, 'sort' => "is_primary DESC"),
        ));
        $useremail = $emailResult['email'];
      }
      catch (CiviCRM_API3_Exception $e) {
        // No email found!
        Civi::log()->warning('Smartdebit getUserEmail: Contact '. $contactId . ' has no email address!');
      }
    }
    return $useremail;
  }

  /**
   * From the selected collection day determine when the actual collection start date could be
   * For direct debit we need to allow 10 working days prior to collection for cooling off
   * We also may need to send them a letter etc
   *
   * @param $params:
   *     preferred_collection_day: integer
   *
   * @return \DateTime
   */
  public static function getCollectionStartDate($params)
  {
    $preferredCollectionDay = $params['preferred_collection_day'];
    return CRM_Smartdebit_Base::firstCollectionDate($preferredCollectionDay);
  }

  /**
   * @param $params
   */
  /**
   * @param $params
   *      collection_start_date: DateTime
   *      collection_frequency: Smartdebit formatted collection frequency
   *      collection_interval: Smartdebit formatted collection interval
   *
   * @return \DateTime|bool
   */
  public static function getCollectionEndDate($params) {
    if (!empty($params['installments'])) {
      $installments = $params['installments'];
    }
    else {
      $installments = 0;
    }
    if (!empty($installments)) {
      // Need to set an end date after final installment
      $plus = array(
        'years' => 0,
        'months' => 0,
        'weeks' => 0
      );
      switch ($params['collection_frequency']) {
        case 'Y':
          $plus['years'] = $installments * $params['collection_interval'];
          break;
        case 'Q':
          $plusQuarters = $installments * $params['collection_interval'];
          $plus['months'] = $plusQuarters * 3;
          break;
        case 'M':
          $plus['months'] = $installments * $params['collection_interval'];
          break;
        case 'W':
          $plus['weeks'] = $installments * $params['collection_interval'];
          break;
        default:
          Civi::log()->debug('Smartdebit getCollectionEndDate: An unknown collection frequency (' . $params['collection_frequency'] . ') was passed!');
      }
      $intervalSpec= 'P' . $plus['years'] . 'Y' . $plus['months'] . 'M' . $plus['weeks'] . 'W';
    }
    elseif (empty($params['collection_interval'])) {
      // If collection_interval == 0 then it's a single payment.
      // Set end date 6 days after start date (min DD freq with Smart Debit is 1 week/7days)
      $intervalSpec = 'P6D';
    }
    else {
      return FALSE;
    }
    $endDate = $params['collection_start_date']->add(new DateInterval($intervalSpec));
    return $endDate;
  }

  /**
   * Determine the frequency based on the recurring params if set
   * Should check the [frequency_unit] and if set use that
   * Smart debit supports frequency intervals of 1-4 for each Y,Q,M,W.
   *
   * @return array (string Y,Q,M,W,O; int frequencyInterval)
   */
  static function getCollectionFrequency($params)
  {
    // Smart Debit supports Y, Q, M, W parameters
    // We return 'O' if the payment is not recurring.  You should then supply an end date to smart debit
    //    to ensure only a single payment is taken.
    // Get frequency unit
    if (!empty($params['frequency_unit'])) {
      $frequencyUnit = $params['frequency_unit'];
    }
    else {
      $frequencyUnit = '';
    }
    // Get frequency interval
    if (!empty($params['frequency_interval'])) {
      $frequencyInterval = $params['frequency_interval'];
    }
    else {
      $frequencyInterval = 1;
    }

    switch (strtolower($frequencyUnit)) {
      case 'year':
        $collectionFrequency = 'Y';
        break;
      case 'month':
        if ($frequencyInterval % 3 != 0) {
          // Monthly
          if ($frequencyInterval > 4) {
            Civi::log()->debug('The maximum monthly collection interval for Smart Debit is 4 months but you specified ' . $frequencyInterval . ' months. 
            Resetting to 4 months. If you meant to select a quarterly interval make sure the collection interval is a multiple of 3.');
            $frequencyInterval = 4;
          }
          $collectionFrequency = 'M';
        } else {
          // Quarterly (frequencyInterval is a multiple of 3)
          if ($frequencyInterval > 12) {
            Civi::log()->debug('The maximum quarterly collection interval for Smart Debit is 4 quarters but you specified ' . $frequencyInterval . ' months. Resetting to 4 quarters');
            $frequencyInterval = 12;
          }
          // Convert frequencyInterval from months to quarters
          $frequencyInterval = ($frequencyInterval / 3);
          $collectionFrequency = 'Q';
        }
        break;
      case 'week':
        // weekly
        if ($frequencyInterval > 4) {
          Civi::log()->debug('The maximum weekly collection interval for Smart Debit is 4 weeks but you specified ' . $frequencyInterval . ' weeks. 
            Resetting to 4 weeks.');
          $frequencyInterval = 4;
        }
        $collectionFrequency = 'W';
        break;
      case 'day':
        // Make sure frequencyInterval is a multiple of 7 days (ie 1 week)
        if ($frequencyInterval % 7 != 0) {
          Civi::log()->debug('The minimum collection interval for Smart Debit is 1 week but you specified ' . $frequencyInterval . ' days. Resetting to 1 week');
          $frequencyInterval = 7;
        }
        if ($frequencyInterval > 28) {
          Civi::log()->debug('The maximum weekly collection interval for Smart Debit is 4 weeks but you specified ' . $frequencyInterval . ' days. Resetting to 4 weeks');
          $frequencyInterval = 28;
        }
        // Convert frequencyInterval from days to weeks
        $frequencyInterval = ($frequencyInterval / 7);
        $collectionFrequency = 'W';
        break;
      default:
        $collectionFrequency = 'Y';
        $frequencyInterval = 0; // Used as a flag that it's a single payment
    }
    return array($collectionFrequency, $frequencyInterval);
  }

  private static function getCollectionFrequencyPostParams($params) {
    $collectionDate = self::getCollectionStartDate($params);
    list($collectionFrequency, $collectionInterval) = self::getCollectionFrequency($params);
    $params['collection_start_date'] = $collectionDate;
    $params['collection_frequency'] = $collectionFrequency;
    $params['collection_interval'] = $collectionInterval;
    $endDate = self::getCollectionEndDate($params);
    if (!empty($endDate)) {
      $smartDebitParams['variable_ddi[end_date]'] = $endDate->format("Y-m-d");
    }
    $smartDebitParams['variable_ddi[frequency_type]'] = $collectionFrequency;
    if (!empty($collectionFrequency)) {
      $smartDebitParams['variable_ddi[frequency_factor]'] = $collectionInterval;
    }
    return $smartDebitParams;
  }

  /**
   * Replace comma with space
   * @param $pString
   * @return mixed
   */
  public static function replaceCommaWithSpace($pString)
  {
    return str_replace(',', ' ', $pString);
  }

  /**
   * Prepare Post Array for POSTing to Smart Debit APi
   *
   * @param $params
   * @param null $self
   *
   * @return array
   */
  private function preparePostArray($params)
  {
    // When passed in from backend forms via AJAX (ie. select from multiple payprocs
    //  $params is not fully set for doDirectPayment, but $_REQUEST has the missing info
    foreach ($_REQUEST as $key => $value) {
      if (!isset($params[$key])) {
        $params[$key] = CRM_Utils_Array::value($key, $_REQUEST);
      }
    }

    $collectionDate = self::getCollectionStartDate($params);
    $serviceUserId = NULL;
    if (!empty($params['amount'])) {
      $amount = $params['amount'];
    }
    elseif (!empty($params['total_amount'])) {
      $amount = $params['total_amount'];
    }
    else {
      $amount = 0;
    }
    $amount = CRM_Smartdebit_Api::encodeAmount($amount);

    if (isset($this->_paymentProcessor['signature'])) {
      $serviceUserId = $this->_paymentProcessor['signature'];
    }

    if (isset($params['contactID'])) {
      $payerReference = $params['contactID'];
    }
    elseif (isset($params['cms_contactID'])) {
      $payerReference = $params['cms_contactID'];
    }
    elseif (isset($_REQUEST['cid'])) {
      $payerReference = CRM_Utils_Array::value('cid', $_REQUEST);
    }
    else {
      $payerReference = 'CIVICRMEXT';
    }

    // Construct params list to send to Smart Debit ...
    $smartDebitParams = array(
      'variable_ddi[service_user][pslid]' => $serviceUserId,
      'variable_ddi[reference_number]' => $params['ddi_reference'],
      'variable_ddi[payer_reference]' => $payerReference,
      'variable_ddi[first_name]' => $params['billing_first_name'],
      'variable_ddi[last_name]' => $params['billing_last_name'],
      'variable_ddi[address_1]' => self::replaceCommaWithSpace($params['billing_street_address-5']),
      'variable_ddi[town]' => self::replaceCommaWithSpace($params['billing_city-5']),
      'variable_ddi[postcode]' => $params['billing_postal_code-5'],
      'variable_ddi[country]' => $params['billing_country_id-5'],
      'variable_ddi[account_name]' => $params['account_holder'],
      'variable_ddi[sort_code]' => $params['bank_identification_number'],
      'variable_ddi[account_number]' => $params['bank_account_number'],
      'variable_ddi[regular_amount]' => $amount,
      'variable_ddi[first_amount]' => $amount,
      'variable_ddi[default_amount]' => $amount,
      'variable_ddi[start_date]' => $collectionDate->format("Y-m-d"),
      'variable_ddi[email_address]' => self::getUserEmail($params),
    );

    $smartDebitParams = array_merge((array)$smartDebitParams, (array)self::getCollectionFrequencyPostParams($params));

    CRM_Smartdebit_Hook::alterCreateVariableDDIParams($params, $smartDebitParams);

    return $smartDebitParams;
  }

  /**
   * Sets appropriate parameters and calls Smart Debit API to create a payment
   *
   * @param array $params name value pair of contribution data
   *
   * @return array $result
   * @access public
   *
   */
  function doDirectPayment(&$params) {
    $smartDebitParams = self::preparePostArray($params);

    // Get the API Username and Password
    $username = $this->_paymentProcessor['user_name'];
    $password = $this->_paymentProcessor['password'];

    $url = CRM_Smartdebit_Api::buildUrl($this->_paymentProcessor, 'api/ddi/variable/create');
    $response = CRM_Smartdebit_Api::requestPost($url, $smartDebitParams, $username, $password);

    // Take action based upon the response status
    if ($response['success']) {
      if (isset($smartDebitParams['variable_ddi[reference_number]'])) {
        $params['trxn_id'] = $smartDebitParams['variable_ddi[reference_number]'];
      }
      if (isset($smartDebitParams['variable_ddi[end_date]'])) {
        $params['end_date'] = $smartDebitParams['variable_ddi[end_date]'];
      }
      $params = self::setRecurTransactionId($params);
      CRM_Smartdebit_Base::completeDirectDebitSetup($params);
      return $params;
    }
    else {
      throw new Exception($response['message'] . ': ' . CRM_Smartdebit_Api::formatResponseError($response['error']));
    }
  }

  /**
   * As the recur transaction is created before payment, we need to update it with our params after payment
   * @param $params
   */
  static function setRecurTransactionId($params) {
    if (!empty($params['trxn_id'])) {
      // Common parameters
      $recurParams = array(
        'trxn_id' => $params['trxn_id'],
      );
      if (!empty($params['end_date'])) {
        $recurParams['end_date'] = $params['end_date'];
      }
      if (!empty($params['preferred_collection_day'])) {
        $recurParams['cycle_day'] = $params['preferred_collection_day'];
      }

      if (!empty($params['contributionRecurID'])) {
        // Recurring transaction, so this is a recurring payment
        $recurParams['id'] = $params['contributionRecurID'];
        $recurParams['contribution_status_id'] = self::getInitialContributionStatus(TRUE);
        // Update the recurring payment
        civicrm_api3('ContributionRecur', 'create', $recurParams);
        // Update the contribution status
        if (!empty($params['contributionID'])) {
          // contributionID not set if we're creating a pledge
          $contributionParams['id'] = $params['contributionID'];
          $contributionParams['contribution_status_id'] = self::getInitialContributionStatus(FALSE);
          civicrm_api3('Contribution', 'create', $contributionParams);
        }
      }
      else {
        // No recurring transaction, assume this is a non-recurring payment (so create a recurring contribution with a single installment
        // Get the financial type ID
        $financialType['name'] = $params['contributionType_name'];
        $financialType=CRM_Financial_BAO_FinancialType::retrieve($financialType,$defaults);
        // Fill recurring transaction parameters
        $recurParams['contact_id'] =  $params['contactID'];
        $recurParams['create_date'] = $params['receive_date'];
        $recurParams['modified_date'] = $params['receive_date'];
        $recurParams['start_date'] = $params['receive_date'];
        $recurParams['amount'] = $params['amount'];
        $recurParams['frequency_unit'] = 'year';
        $recurParams['frequency_interval'] = '1';
        $recurParams['financial_type_id'] = $financialType->id;
        $recurParams['auto_renew'] = '0'; // Make auto renew
        $recurParams['currency'] = $params['currencyID'];
        $recurParams['invoice_id'] = $params['invoiceID'];
        $recurParams['contribution_status_id'] = self::getInitialContributionStatus(TRUE);

        $recur = CRM_Smartdebit_Base::createRecurContribution($recurParams);
        // Record recurring contribution ID in params for return
        $params['contributionRecurID'] = $recur['id'];
        $params['contribution_recur_id'] = $recur['id'];
        // We need to link the recurring contribution and contribution record, as Civi won't do it for us (4.7.21)
        $contributionParams = array(
          'contribution_recur_id' => $params['contribution_recur_id'],
          'contact_id' => $params['contactID'],
          'contribution_status_id' => self::getInitialContributionStatus(FALSE),
        );
        if (empty($params['contributionID'])) {
          Civi::log()->debug('Smartdebit: No contribution ID specified.  Is this a non-recur transaction?');
        }
        else {
          $contributionParams['id'] = $params['contributionID'];
          civicrm_api3('Contribution', 'create', $contributionParams);
        }
        if ($contributionParams['contribution_status_id'] ==
          CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending')) {
          $params['is_recur'] = 1; // Required for CRM_Core_Payment to set contribution status = Pending
        }
      }

      // We need to set this to ensure that contributions are set to the correct status
      if (!empty($contributionParams['contribution_status_id'])) {
        $params['payment_status_id'] = $contributionParams['contribution_status_id'];
      }

      // Check and update membership
      if (!empty($params['membershipID'])) {
        self::updateMembershipStatus($params['membershipID']);
      }
    }
    return $params;
  }

  /**
   * Get the initial (recur) contribution status based on the desired configuration.
   * If initial_completed=TRUE we need to set initial contribution to completed.
   *
   * @param bool $isRecur TRUE if we should return status of recurring contribution instead.
   *
   * @return bool|int|null|string
   */
  static function getInitialContributionStatus($isRecur = FALSE) {
    $initialCompleted = (boolean) CRM_Smartdebit_Settings::getValue('initial_completed');

    if ($initialCompleted) {
      if ($isRecur) {
        return CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'In Progress');
      }
      return CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
    }
    return CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
  }

  /**
   * If we are forcing initial payment status to completed we have to update the membership status as well or it will stay in pending
   * @param $membershipId
   */
  static function updateMembershipStatus($membershipId) {
    $initialCompleted = (boolean) CRM_Smartdebit_Settings::getValue('initial_completed');

    if ($initialCompleted) {
      // Force an update of the membership status
      $membership = civicrm_api3('Membership', 'getsingle', array('membership_id' => $membershipId));
      $dates = CRM_Member_BAO_MembershipType::getDatesForMembershipType($membership['membership_type_id']);

      $membershipParams = array(
        'membership_id' => $membershipId,
        'start_date' => $dates['start_date'],
        'end_date' => $dates['end_date'],
        'join_date' => $dates['join_date'],
        'skipStatusCal' => 0,
      );
      civicrm_api3('Membership', 'create', $membershipParams);
    }
  }

  /**
   * Record the response from SmartDebit after validatePayment()
   * @param $direct_debit_response
   */
  static function recordSmartDebitResponse($direct_debit_response)
  {
    $sql = "
UPDATE civicrm_direct_debit SET
                 created                  = NOW()
          ,      request_counter          = request_counter + 1
    ";
    isset($direct_debit_response['data_type']) ? $sql .= ", data_type                = \"{$direct_debit_response['data_type']}\"" : NULL;
    isset($direct_debit_response['entity_type']) ? $sql .= ", entity_type              = \"{$direct_debit_response['entity_type']}\"" : NULL;
    isset($direct_debit_response['entity_id']) ? $sql .= "  , entity_id                = {$direct_debit_response['entity_id']}" : NULL;
    isset($direct_debit_response['bank_name']) ? $sql .= "  , bank_name                = \"{$direct_debit_response['bank_name']}\"" : NULL;
    isset($direct_debit_response['branch']) ? $sql .= "     , branch                   = \"{$direct_debit_response['branch']}\"" : NULL;
    isset($direct_debit_response['address1']) ? $sql .= "   , address1                 = \"{$direct_debit_response['address1']}\"" : NULL;
    isset($direct_debit_response['address2']) ? $sql .= "   , address2                 = \"{$direct_debit_response['address2']}\"" : NULL;
    isset($direct_debit_response['address3']) ? $sql .= "   , address3                 = \"{$direct_debit_response['address3']}\"" : NULL;
    isset($direct_debit_response['address4']) ? $sql .= "   , address4                 = \"{$direct_debit_response['address4']}\"" : NULL;
    isset($direct_debit_response['town']) ? $sql .= "       , town                     = \"{$direct_debit_response['town']}\"" : NULL;
    isset($direct_debit_response['county']) ? $sql .= "     , county                   = \"{$direct_debit_response['county']}\"" : NULL;
    isset($direct_debit_response['postcode']) ? $sql .= "   , postcode                 = \"{$direct_debit_response['postcode']}\"" : NULL;
    isset($direct_debit_response['first_collection_date']) ? $sql .= "   , first_collection_date    = \"{$direct_debit_response['first_collection_date']}\"" : NULL;
    isset($direct_debit_response['preferred_collection_day']) ? $sql .= ", preferred_collection_day = \"{$direct_debit_response['preferred_collection_day']}\"" : NULL;
    isset($direct_debit_response['confirmation_method']) ? $sql .= "     , confirmation_method      = \"{$direct_debit_response['confirmation_method']}\"" : NULL;
    isset($direct_debit_response['response_status']) ? $sql .= "         , response_status          = \"{$direct_debit_response['response_status']}\"" : NULL;
    isset($direct_debit_response['response_raw']) ? $sql .= "            , response_raw             = \"{$direct_debit_response['response_raw']}\"" : NULL;
    $sql .= " WHERE  ddi_reference           = \"{$direct_debit_response['ddi_reference']}\"";

    CRM_Core_DAO::executeQuery($sql);
  }

  /**
   * Sets appropriate parameters for checking out to UCM Payment Collection
   *
   * @param array $params name value pair of contribution datat
   * @param $component
   * @access public
   *
   */
  function doTransferCheckout(&$params, $component)
  {
    self::doDirectPayment($params);
  }

  /**
   * Change the subscription amount using the Smart Debit API
   * @param string $message
   * @param array $params
   *
   * @return array|bool|object
   */
  public function changeSubscriptionAmount(&$message = '', $params = array()) {
    self::changeSubscription($this->paymentProcessor, $params);
  }

  public static function changeSubscription($paymentProcessor, $params) {
    try {
      $recurRecord = civicrm_api3('ContributionRecur', 'getsingle', array(
        'id' => $params['id'],
        'options' => array('limit' => 1),
      ));
    }
    catch (CiviCRM_API3_Exception $e) {
      CRM_Core_Error::statusBounce('No recurring record! ' . $e->getMessage());
      return FALSE;
    }

    $serviceUserId = $paymentProcessor['signature'];
    $username = $paymentProcessor['user_name'];
    $password = $paymentProcessor['password'];

    $amount = CRM_Smartdebit_Api::encodeAmount(isset($params['amount']) ? $params['amount'] : 0);
    if (!empty($params['end_date'])) {
      $eDate = $params['end_date'];
    }
    else {
      $eDate = $recurRecord['end_date'];
    }

    $endDate = $startDate = NULL;
    if (!empty($eDate)) {
      $endDate = strtotime($eDate);
      $endDate = date("Y-m-d", $endDate);
    }

    $smartDebitParams = array(
      'variable_ddi[service_user][pslid]' => $serviceUserId,
      'variable_ddi[first_amount]' => $amount,
      'variable_ddi[default_amount]' => $amount,
    );
    if (!empty($params['start_date'])) {
      $smartDebitParams['variable_ddi[start_date]'] = $params['start_date'];
    }
    if (!empty($endDate)) {
      $smartDebitParams['variable_ddi[end_date]'] = $endDate;
    }
    if (!isset($params['frequency_unit'])) {
      $params['frequency_unit'] = $recurRecord['frequency_unit'];
    }
    if (!isset($params['frequency_interval'])) {
      $params['frequency_interval'] = $recurRecord['frequency_interval'];
    }
    if (isset($params['frequency_unit']) || isset($params['frequency_interval'])) {
      $smartDebitParams = array_merge($smartDebitParams, self::getCollectionFrequencyPostParams($params));
    }

    $url = CRM_Smartdebit_Api::buildUrl($paymentProcessor, 'api/ddi/variable/' . $recurRecord['trxn_id'] . '/update');
    $response = CRM_Smartdebit_Api::requestPost($url, $smartDebitParams, $username, $password);
    if (!$response['success']) {
      $msg = CRM_Smartdebit_Api::formatResponseError($response['error']);
      $msg .= '<br />Update Subscription Failed.';
      CRM_Core_Session::setStatus(ts($msg), 'Smart Debit', 'error');
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Cancel the Direct Debit Subscription using the Smart Debit API
   * @param string $message
   * @param array $params
   * @return bool
   */
  function cancelSubscription($params = array())
  {
    $serviceUserId = $this->_paymentProcessor['signature'];
    $username = $this->_paymentProcessor['user_name'];
    $password = $this->_paymentProcessor['password'];

    try {
      $contributionRecur = civicrm_api3('ContributionRecur', 'getsingle', array(
        'sequential' => 1,
        'id' => $_GET['crid'],
      ));
    }
    catch (Exception $e) {
      return FALSE;
    }
    if (empty($contributionRecur['trxn_id'])) {
      CRM_Core_Session::setStatus(ts('The recurring contribution cannot be cancelled (No reference (trxn_id) found).'), 'Smart Debit', 'error');
      return FALSE;
    }
    $reference = $contributionRecur['trxn_id'];
    $smartDebitParams = array(
      'variable_ddi[service_user][pslid]' => $serviceUserId,
      'variable_ddi[reference_number]' => $reference,
    );

    $url = CRM_Smartdebit_Api::buildUrl($this->_paymentProcessor, 'api/ddi/variable/' . $reference . '/cancel');
    $response = CRM_Smartdebit_Api::requestPost($url, $smartDebitParams, $username, $password);
    if (!$response['success']) {
      $msg = CRM_Smartdebit_Api::formatResponseError($response['error']);
      $msg .= '<br />Cancel Subscription Failed.';
      CRM_Core_Session::setStatus(ts($msg), 'Smart Debit', 'error');
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Called when
   * @param string $message
   * @param array $params
   * @return bool
   */
  function updateSubscriptionBillingInfo(&$message = '', $params = array())
  {
    $serviceUserId = $this->_paymentProcessor['signature'];
    $username = $this->_paymentProcessor['user_name'];
    $password = $this->_paymentProcessor['password'];
    $reference = $params['subscriptionId'];
    $firstName = $params['first_name'];
    $lastName = $params['last_name'];
    $streetAddress = $params['street_address'];
    $city = $params['city'];
    $postcode = $params['postal_code'];
    $state = $params['state_province'];
    $country = $params['country'];

    $smartDebitParams = array(
      'variable_ddi[service_user][pslid]' => $serviceUserId,
      'variable_ddi[reference_number]' => $reference,
      'variable_ddi[first_name]' => $firstName,
      'variable_ddi[last_name]' => $lastName,
      'variable_ddi[address_1]' => self::replaceCommaWithSpace($streetAddress),
      'variable_ddi[town]' => $city,
      'variable_ddi[postcode]' => $postcode,
      'variable_ddi[county]' => $state,
      'variable_ddi[country]' => $country,
    );

    $url = CRM_Smartdebit_Api::buildUrl($this->_paymentProcessor, 'api/ddi/variable/' . $reference . '/update');
    $response = CRM_Smartdebit_Api::requestPost($url, $smartDebitParams, $username, $password);
    if (!$response['success']) {
      $msg = CRM_Smartdebit_Api::formatResponseError($response['error']);
      CRM_Core_Session::setStatus(ts($msg), 'Smart Debit', 'error');
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Format response error for display to user
   * @param $responseErrors
   * @return string
   */
  static function formatResponseError($responseErrors)
  {
    $errorMsg = '';
    if (!is_array($responseErrors)) {
      $errorMsg = $responseErrors . '<br />';
      $errorMsg .= '<br />';
    } else {
      foreach ($responseErrors as $error) {
        $errorMsg .= $error . '<br />';
      }
      $errorMsg .= '<br />';
    }
    $errorMsg .= 'Please correct the errors and try again';
    return $errorMsg;
  }

  /**
   * Get ID of payment processor with class name "Payment_Smartdebit"
   * @return int
   */
  static function getSmartDebitPaymentProcessorID() {
    $result = civicrm_api3('PaymentProcessor', 'get', array(
      'sequential' => 1,
      'return' => array("id"),
      'class_name' => "Payment_Smartdebit",
      'is_test' => 0,
    ));
    if ($result['count'] > 0) {
      // Return the first one, it's possible there is more than one payment processor of the same type configured
      //  so we'll just return the first one here.
      if (isset($result['values'][0]['id'])) {
        return $result['values'][0]['id'];
      }
    }
    // If we don't have a valid processor id return false;
    return FALSE;
  }

  /**
   * Get the name of the payment processor
   * @param $ppId
   * @return string
   */
  static function getSmartDebitPaymentProcessorName($ppId) {
    $paymentProcessorName = 'Unknown';
    try {
      $paymentProcessor = civicrm_api3('PaymentProcessor', 'getsingle', array(
        'return' => array("name"),
        'id' => $ppId,
      ));
      if (isset($paymentProcessor['name'])) {
        $paymentProcessorName = $paymentProcessor['name'];
      }
    }
    catch (Exception $e) {
      // Payment processor not found, use the default already set above.
    }
    return $paymentProcessorName;
  }

}
