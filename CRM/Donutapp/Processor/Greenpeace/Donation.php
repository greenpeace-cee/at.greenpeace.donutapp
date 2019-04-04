<?php

class CRM_Donutapp_Processor_Greenpeace_Donation extends CRM_Donutapp_Processor_Greenpeace_Base {

  /**
   * @var array
   */
  protected $membershipTypes = NULL;

  /**
   * Should we defer importing this donation?
   *
   * Import should be deferred if:
   *  - Donation is on hold OR
   *  - Donation was added in the last hour and welcome email is still
   *    queued or being retried
   *
   * @param \CRM_Donutapp_API_Donation $donation
   *
   * @throws \Exception
   *
   * @return bool
   */
  private function isDeferrable(CRM_Donutapp_API_Donation $donation) {
    // Welcome email is in queue or being retried
    $pending = in_array($donation->welcome_email_status, ['queued', 'retrying']);
    $created = new DateTime($donation->createtime);
    $created->setTimezone(new DateTimeZone(date_default_timezone_get()));
    // Welcome email was created in the last hour
    $recent = new DateTime() < $created->modify('-1 hour');
    return $donation->on_hold || ($pending && $recent);
  }

  /**
   * Fetch and process donations
   *
   * @throws \CRM_Donutapp_API_Error_Authentication
   * @throws \CRM_Donutapp_API_Error_BadResponse
   * @throws \CiviCRM_API3_Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function process() {
    $this->loadMembershipTypes();
    CRM_Donutapp_API_Client::setClientId($this->params['client_id']);
    CRM_Donutapp_API_Client::setClientSecret($this->params['client_secret']);
    $importedDonations = CRM_Donutapp_API_Donation::all([
      'limit' => $this->params['limit']
    ]);
    foreach ($importedDonations as $donation) {
      try {
        // preload PDF outside of transaction
        $donation->fetchPdf();
        $this->processWithTransaction($donation);
      }
      catch (Exception $e) {
        CRM_Core_Error::debug_log_message(
          'Uncaught Exception in CRM_Donutapp_Processor_Donation::process'
        );
        CRM_Core_Error::debug_var('Exception Details', [
          'message' => $e->getMessage(),
          'exception' => $e
        ]);
        // Create Import Error Activity
        CRM_Donutapp_Util::createImportError('Donation', $e, $donation);
      }
    }
  }

  /**
   * Process a donation within a database transaction
   *
   * @param \CRM_Donutapp_API_Donation $donation
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function processWithTransaction(CRM_Donutapp_API_Donation $donation) {
    $tx = new CRM_Core_Transaction();
    try {
      $this->processDonation($donation);
    }
    catch (Exception $e) {
      $tx->rollback();
      throw $e;
    }
  }

  /**
   * Process a donation
   *
   * @param \CRM_Donutapp_API_Donation $donation
   *
   * @throws \CRM_Donutapp_API_Error_Authentication
   * @throws \CRM_Donutapp_API_Error_BadResponse
   * @throws \CiviCRM_API3_Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \CRM_Donutapp_Processor_Exception
   */
  protected function processDonation(CRM_Donutapp_API_Donation $donation) {
    if (!$this->isDeferrable($donation)) {

      $contact_id = $this->createContact($donation);
      $membership_id = $this->createContract($donation, $contact_id);
      $this->createWebshopOrder($donation, $contact_id, $membership_id);

      $this->addToNewsletterGroup($donation, $contact_id);

      $interest_group = $donation->interest_group;
      if (!empty($interest_group)) {
        $this->addGroup($contact_id, $interest_group);
      }

      $topic_group = $donation->topic_group;
      if (!empty($topic_group)) {
        $this->addGroup($contact_id, $topic_group);
      }

      $parent_activity_id = civicrm_api3('Activity', 'getvalue', [
        'return'           => 'id',
        'activity_type_id' => 'Contract_Signed',
        'source_record_id' => $membership_id,
      ]);
      $this->processWelcomeEmail($donation, $contact_id, $parent_activity_id);
      // Should we confirm retrieval?
      if ($this->params['confirm']) {
        $donation->confirm();
      }
    }
  }

  /**
   * Add or update a contact
   *
   * @param \CRM_Donutapp_API_Donation $donation
   *
   * @return mixed
   * @throws \CiviCRM_API3_Exception
   */
  protected function createContact(CRM_Donutapp_API_Donation $donation) {
    switch ($donation->donor_sex) {
      case 1:
        $prefix = 'Herr';
        break;

      case 2:
        $prefix = 'Frau';
        break;
    }

    $phone = $donation->donor_mobile;
    if (empty($phone)) {
      $phone = $donation->donor_phone;
    }

    // compile contact data
    $contact_data = [
      'formal_title'   => $donation->donor_academic_title,
      'first_name'     => $donation->donor_first_name,
      'last_name'      => $donation->donor_last_name,
      'prefix_id'      => $prefix,
      'birth_date'     => $donation->donor_date_of_birth,
      'country_id'     => $donation->donor_country,
      'postal_code'    => $donation->donor_zip_code,
      'city'           => $donation->donor_city,
      'street_address' => trim($donation->donor_street) . ' ' . trim($donation->donor_house_number),
      'email'          => $donation->donor_email,
      'phone'          => $phone,
    ];

    // remove empty attributes to prevent creation of useless diff activity
    foreach ($contact_data as $key => $value) {
      if (empty($value)) {
        unset($contact_data[$key]);
      }
    }

    // and match using XCM
    return civicrm_api3('Contact', 'getorcreate', $contact_data)['id'];
  }

  /**
   * Add a membership
   *
   * @param CRM_Donutapp_API_Donation $donation
   * @param $contactId
   *
   * @return mixed
   * @throws \CRM_Donutapp_Processor_Exception
   * @throws \CiviCRM_API3_Exception
   */
  protected function createContract(CRM_Donutapp_API_Donation $donation, $contactId) {
    if ($donation->direct_debit_interval == '0') {
      throw new CRM_Donutapp_Processor_Exception('One-off payments are not supported');
    }
    $iban = strtoupper(str_replace(' ', '', $donation->bank_account_iban));
    $mandate_data = [
      'iban'               => $iban,
      'frequency_unit'     => 'month',
      'contact_id'         => $contactId,
      'financial_type_id'  => 2, // Membership Dues
      'currency'           => 'EUR',
      'type'               => 'RCUR',
      'campaign_id'        => $this->getCampaign($donation),
      'frequency_interval' => 12 / $donation->direct_debit_interval,
    ];

    // use Little BIC extension to look up BIC via IBAN
    try {
      $result = civicrm_api3('Bic', 'getfromiban', [
        'iban' => $iban,
      ]);
      if (!empty($result['bic'])) {
        $mandate_data['bic'] = $result['bic'];
      }
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message(
        "BIC lookup failed for IBAN '{$mandate_data['iban']}'"
      );
    }

    // if BIC lookup was unsuccessful, fall back to BIC provided by DonutApp
    if (empty($mandate_data['bic'])) {
      $mandate_data['bic'] = strtoupper($donation->bank_account_bic);
    }

    // comma is decimal separator, no thousands separator
    $annualAmount = str_replace(',', '.', $donation->donation_amount_annual);
    $mandate_data['amount'] = number_format($annualAmount / $donation->direct_debit_interval, 2, '.', '');
    if ($mandate_data['amount'] * $donation->direct_debit_interval != $annualAmount) {
      throw new CRM_Donutapp_Processor_Exception(
        "Contract annual amount '{$annualAmount}' not divisible by frequency {$donation->direct_debit_interval}."
      );
    }

    $now = new DateTime();
    $join_date = DateTime::createFromFormat('Y-m-d', $donation->contract_start_date);
    $signature_date = new DateTime($donation->createtime);
    $signature_date->setTimezone(new DateTimeZone(date_default_timezone_get()));

    // process start date
    $mandate_data['start_date'] = $join_date->format('Ymd');
    // if we're late with importing, set start date to today
    if ($mandate_data['start_date'] < $now->format('Ymd')) {
      $mandate_data['start_date'] = $now->format('Ymd');
    }
    $mandate_data['cycle_day'] = $this->getNextCycleDay($mandate_data['start_date']);

    // check parameters
    $required_params = ['bic', 'iban', 'start_date', 'cycle_day', 'contact_id', 'amount', 'campaign_id'];
    foreach ($required_params as $required_param) {
      if (empty($mandate_data[$required_param])) {
        throw new CRM_Donutapp_Processor_Exception(
          "Could not create contract: Parameter '{$required_param}' is missing."
        );
      }
    }

    $mandate = civicrm_api3('SepaMandate', 'createfull', $mandate_data);
    $mandate = civicrm_api3('SepaMandate', 'getsingle', ['id' => $mandate['id']]);

    $dialoger = $this->findOrCreateDialoger($donation);
    if (is_null($dialoger)) {
      throw new CRM_Donutapp_Processor_Exception('Could not create dialoger.');
    }

    $channel = str_replace('Kontaktart:', '', $donation->membership_channel);
    // create membership
    // @TODO: use signature_date for contract_signed activity
    $contract_data = [
      'contact_id'                                           => $contactId,
      'membership_type_id'                                   => $this->getMembershipType($donation),
      'join_date'                                            => $join_date->format('Ymd'),
      'start_date'                                           => $signature_date->format('YmdHis'),
      'campaign_id'                                          => $this->getCampaign($donation),
      'membership_general.membership_channel'                => $channel,
      'membership_general.membership_contract'               => $donation->person_id,
      'membership_general.membership_dialoger'               => $dialoger,
      'membership_payment.membership_recurring_contribution' => $mandate['entity_id'],
      'membership_payment.from_ba'                           => CRM_Contract_BankingLogic::getOrCreateBankAccount($contactId, $mandate_data['iban'], $mandate_data['bic']),
      'membership_payment.to_ba'                             => CRM_Contract_BankingLogic::getCreditorBankAccount(),
    ];
    $membership = civicrm_api3('Contract', 'create', $contract_data);
    $this->storeContractFile($donation->person_id . '.pdf', $donation->pdf_content, $membership['id']);
    return $membership['id'];
  }

  /**
   * Store the contract PDF as a File entity
   *
   * @todo this should ideally be implemented using the Attachment.create API,
   *       which is not possible as of Civi 5.7 due to an overly-sensitive
   *       permission check. Switch to Attachment.create once
   *       https://lab.civicrm.org/dev/core/issues/690 lands.
   *
   * @param $fileName
   * @param $content
   * @param $membershipId
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function storeContractFile($fileName, $content, $membershipId) {
    $config = CRM_Core_Config::singleton();
    $uri = CRM_Utils_File::makeFileName($fileName);
    $path = $config->customFileUploadDir . DIRECTORY_SEPARATOR . $uri;
    file_put_contents($path, $content);
    $file = civicrm_api3('File', 'create', [
      'mime_type' => 'application/pdf',
      'uri' => $uri,
    ]);
    $custom_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID('contract_file', 'membership_general');
    civicrm_api3('custom_value', 'create', [
      'entity_id' => $membershipId,
      $custom_field => $file['id'],
    ]);
  }

  /**
   * Create a webshop order
   *
   * @param \CRM_Donutapp_API_Donation $donation
   * @param $contactId
   * @param $membershipId
   *
   * @return array|bool new webshop order activity
   * @throws \CiviCRM_API3_Exception
   */
  protected function createWebshopOrder(CRM_Donutapp_API_Donation $donation, $contactId, $membershipId) {
    $shirt_type = $donation->shirt_type;
    $shirt_size = $donation->shirt_size;
    if (empty($shirt_type) && empty($shirt_size)) {
      return FALSE;
    }
    $shirt_type = str_replace('T-Shirt Modell:', '', $shirt_type);
    $shirt_size = str_replace('T-Shirt Größe:', '', $shirt_size);

    // @todo: should not be hardcoded in case we want to add other incentives
    $order_type = 'T-Shirt';
    if ($donation->membership_type == 'Guardian of the Ocean') {
      $order_type = 'T-Shirt: Guardian Of The Oceans';
    }

    if (empty($shirt_type)) {
      // default to male
      // this is going to come back to haunt me when I run for president ...
      $shirt_type = 'M';
    }

    if (empty($shirt_size)) {
      // default to medium
      $shirt_size = 'M';
    }

    $params = [
      'target_id'        => $contactId,
      'activity_type_id' => 'Webshop Order',
      'status_id'        => 'Scheduled',
      'campaign_id'      => $this->getCampaign($donation),
      'subject'          => "order type {$order_type} {$shirt_type}/{$shirt_size} AND number of items 1",
      'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID('order_type', 'webshop_information') =>
        $order_type,
      'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID('order_count', 'webshop_information') =>
        1,
      'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID('shirt_type', 'webshop_information') =>
        $shirt_type,
      'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID('shirt_size', 'webshop_information')  =>
        $shirt_size,
      'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID('linked_membership', 'webshop_information') =>
        $membershipId,
    ];
    return civicrm_api3('Activity', 'create', $params);
  }

  /**
   * Add contact to newsletter group if email is present
   *
   * @param \CRM_Donutapp_API_Donation $donation
   * @param $contactId
   *
   * @return array|bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function addToNewsletterGroup(CRM_Donutapp_API_Donation $donation, $contactId) {
    $email = $donation->donor_email;
    if (empty($email)) {
      return FALSE;
    }
    $this->addGroup($contactId, 'Community NL');
  }

  /**
   * Determine the Civi Campaign ID for a donation
   *
   * @todo this is currently hardcoded for internal DD and needs to return other
   *       values based on something like the customer_id API field
   *
   * @param \CRM_Donutapp_API_Entity $donation
   *
   * @return int
   * @throws \CiviCRM_API3_Exception
   */
  protected function getCampaign(CRM_Donutapp_API_Entity $donation) {
    return civicrm_api3('Campaign', 'getsingle', [
      'external_identifier' => 'DD_DDG',
      'return'              => 'id'
    ])['id'];
  }

  /**
   * Get membership type ID for a given donation
   *
   * @param \CRM_Donutapp_API_Donation $donation
   *
   * @return mixed
   * @throws \CRM_Donutapp_Processor_Exception
   */
  protected function getMembershipType(CRM_Donutapp_API_Donation $donation) {
    $membership_type = $donation->membership_type;
    if (empty($membership_type)) {
      throw new CRM_Donutapp_Processor_Exception(
        'Field membership_type cannot be empty'
      );
    }

    if (!array_key_exists($membership_type, $this->membershipTypes)) {
      throw new CRM_Donutapp_Processor_Exception(
        "Unknown membership type {$membership_type}"
      );
    }

    return $this->membershipTypes[$membership_type];
  }

  /**
   * Preload all membership types in $this->membershipTypes
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function loadMembershipTypes() {
    if ($this->membershipTypes === NULL) {
      $membership_types = civicrm_api3('MembershipType', 'get', [
        'sequential' => 1,
        'return' => ['id', 'name'],
        'options' => ['limit' => 0],
      ])['values'];
      foreach ($membership_types as $membership_type) {
        $this->membershipTypes[$membership_type['name']] = $membership_type['id'];
      }
    }
  }

  /**
   * Get the next possible cycle day
   *
   * @todo use SEPA function
   *
   * @param $start_date
   *
   * @return int
   * @throws \CRM_Donutapp_Processor_Exception
   */
  protected function getNextCycleDay($start_date) {
    // find the right start date
    $creditor = CRM_Sepa_Logic_Settings::defaultCreditor();
    $buffer_days = (int) CRM_Sepa_Logic_Settings::getSetting('pp_buffer_days') + (int) CRM_Sepa_Logic_Settings::getSetting('batching.FRST.notice', $creditor->id);
    $now                 = time();
    $start_date          = strtotime($start_date);
    $earliest_start_date = strtotime("+{$buffer_days} day", $now);
    if ($start_date < $earliest_start_date) {
      $start_date = $earliest_start_date;
    }

    // now: find the next valid start day
    $cycle_days = $this->getCycleDays();
    $safety_counter = 32;
    while (!in_array(date('j', $start_date), $cycle_days)) {
      $start_date = strtotime('+ 1 day', $start_date);
      $safety_counter -= 1;
      if ($safety_counter == 0) {
        throw new CRM_Donutapp_Processor_Exception('getNextCycleDay() reached maximum iteration limit');
      }
    }
    return (int) date('j', $start_date);
  }

  /**
   * Get the list of allowed cycle days
   *
   * @return array
   * @throws \CRM_Donutapp_Processor_Exception
   */
  protected function getCycleDays() {
    $creditor = CRM_Sepa_Logic_Settings::defaultCreditor();
    if (is_null($creditor)) {
      throw new CRM_Donutapp_Processor_Exception('No default creditor set');
    }
    return CRM_Sepa_Logic_Settings::getListSetting('cycledays', range(1, 28), $creditor->id);
  }

  /**
   * Get subject of welcome email
   *
   * @todo move this to a setting or determine via API
   *
   * @param \CRM_Donutapp_API_Entity $entity
   *
   * @return string
   */
  protected function getEmailSubject(CRM_Donutapp_API_Entity $entity) {
    return 'Wir sagen Danke!';
  }

}
