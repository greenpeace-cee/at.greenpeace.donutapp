<?php

use Civi\Api4;
use Civi\Api4\Contact;

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
    return $this->isOnHold($donation) || ($pending && $recent);
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
          'message'   => $e->getMessage(),
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
   * @throws \Exception
   */
  protected function processDonation(CRM_Donutapp_API_Donation $donation) {
    if (!$this->isDeferrable($donation)) {

      $contact_id = $this->createContact($donation);
      $contract_result = $this->createContract($donation, $contact_id);
      if (!empty($contract_result['contribution_id'])) {
        $activityType = 'Contribution';
      } else if (!empty($contract_result['membership_id'])) {
        $activityType = 'Contract_Signed';
      } else {
        throw new CRM_Donutapp_Processor_Exception("Donation did not result in creation of contribution or membership.");
      }

      $this->addToNewsletterGroup($donation, $contact_id);
      $interest_group = $donation->interest_group;
      if (!empty($interest_group)) {
        $this->addGroup($contact_id, $interest_group);
      }

      $topic_group = $donation->topic_group;
      if (!empty($topic_group)) {
        $this->addGroup($contact_id, $topic_group);
      }

      $this->createWebshopOrder($donation, $contact_id, $contract_result);
      // get corresponding contribution or contract signed activity
      $parent_activity_id = civicrm_api3('Activity', 'getvalue', [
        'return'           => 'id',
        'activity_type_id' => $activityType,
        'source_record_id' => $contract_result['membership_id'] ?? $contract_result['contribution_id'],
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

    $gender = CRM_Donutapp_Util::getGender($donation);
    if (empty($gender)) {
      Civi::log()->warning('Unable to determine gender', $donation->getData());
    }
    $prefix = CRM_Donutapp_Util::getPrefix($donation);

    $phone = $donation->donor_mobile;
    if (empty($phone)) {
      $phone = $donation->donor_phone;
    }

    // compile contact data
    $contact_data = [
      'xcm_profile'    => 'DD',
      'formal_title'   => $donation->donor_academic_title,
      'first_name'     => $donation->donor_first_name,
      'last_name'      => $donation->donor_last_name,
      'gender_id'      => $gender,
      'prefix_id'      => $prefix,
      'birth_date'     => $donation->donor_date_of_birth,
      'country_id'     => $donation->donor_country,
      'postal_code'    => $donation->donor_zip_code,
      'city'           => $donation->donor_city,
      'street_address' => trim(trim($donation->donor_street ?? '') . ' ' . trim($donation->donor_house_number ?? '')),
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
   * Add a membership or contribution
   *
   * @param CRM_Donutapp_API_Donation $donation
   * @param $contactId
   *
   * @return array
   * @throws \CRM_Donutapp_Processor_Exception
   * @throws \CiviCRM_API3_Exception|\DateInvalidTimeZoneException
   */
  protected function createContract(CRM_Donutapp_API_Donation $donation, $contactId) {
    // Payment frequency & amount
    $annualAmount = (float) str_replace(',', '.', $donation->donation_amount_annual);
    $frequency = (int) $donation->direct_debit_interval;

    // Start date
    $now = new DateTime();
    $contract_start_date = DateTime::createFromFormat('Y-m-d', $donation->contract_start_date);

    if ($contract_start_date > $now) {
      throw new CRM_Donutapp_Processor_Exception(
        "Invalid contract_start_date '{$contract_start_date->format('Y-m-d')}'. " .
        "Value must not be in the future"
      );
    }

    // Bank accounts
    $iban = strtoupper(str_replace(' ', '', $donation->bank_account_iban));

    if (empty($iban)) {
      throw new CRM_Donutapp_Processor_Exception("Could not create contract: IBAN is missing.");
    }

    $from_ba = CRM_Contract_BankingLogic::getOrCreateBankAccount($contactId, $iban);
    $to_ba = CRM_Contract_BankingLogic::getCreditorBankAccount();

    // Dialoger
    $dialoger_id = $this->findOrCreateDialoger($donation);

    if (is_null($dialoger_id)) {
      throw new CRM_Donutapp_Processor_Exception('Could not create dialoger.');
    }

    // Channel
    $channel = str_replace('Kontaktart:', '', $donation->membership_channel);

    // Join date
    $signature_date = new DateTime($donation->createtime);
    $signature_date->setTimezone(new DateTimeZone(date_default_timezone_get()));

    if ($frequency < 1) {
      // The contract_number (person_id) of the contribution must be unique
      $contract_number = $donation->person_id;

      $contract_number_count = Api4\Contribution::get(FALSE)
        ->selectRowCount()
        ->addWhere('contribution_information.contract_number', '=', $contract_number)
        ->execute()
        ->rowCount;

      if ($contract_number_count > 0) {
        throw new CRM_Donutapp_Processor_Exception("A contribution with contract_number '{$contract_number}' already exists.");
      }

      // Create a SEPA mandate for a one-off donation
      $sepa_mandate_result = civicrm_api3('SepaMandate', 'createfull', [
        'amount'            => $annualAmount,
        'campaign_id'       => $this->getCampaign($donation),
        'contact_id'        => $contactId,
        'financial_type_id' => self::getFinancialTypeId('Donation'),
        'iban'              => $iban,
        'receive_date'      => $contract_start_date->format('Y-m-d'),
        'type'              => 'OOFF',
        'date'              => $signature_date->format('YmdHis')
      ]);

      $contribution_id = reset($sepa_mandate_result['values'])['entity_id'];

      // Store the contribution file
      $file = self::storeContributionFile("{$donation->person_id}.pdf", $donation->pdf_content, $contribution_id);

      // Set custom fields for the associated contribution
      Api4\Contribution::update(FALSE)
        ->addValue('contribution_information.channel', $channel)
        ->addValue('contribution_information.contract_number', $contract_number)
        ->addValue('contribution_information.contribution_file', $file['id'])
        ->addValue('contribution_information.dialoger', $dialoger_id)
        ->addWhere('id', '=', $contribution_id)
        ->execute();

      return ['contribution_id' => $contribution_id];
    }

    // round frequency amount to two decimals digits
    $amount = (float) round($annualAmount / $frequency, 2);
    $annualAmountCalculated = (float) $amount * $frequency;

    // ensure multiplying frequency amount with frequency interval matches the annual amount
    // strval() helps us avoid floating point precision issues
    if (strval($annualAmountCalculated) !== strval($annualAmount)) {
      throw new CRM_Donutapp_Processor_Exception(
        "Contract annual amount '$annualAmount' not divisible by frequency $frequency."
      );
    }

    // Create membership
    // @TODO: use signature_date for contract_signed activity
    $contract_data = [
      'contact_id'                             => $contactId,
      'membership_type_id'                     => $this->getMembershipType($donation),
      'join_date'                              => $signature_date->format('Ymd'),
      'start_date'                             => $now->format('Ymd'),
      'campaign_id'                            => $this->getCampaign($donation),
      'membership_general.membership_channel'  => $channel,
      'membership_general.membership_contract' => $donation->person_id,
      'membership_general.membership_dialoger' => $dialoger_id,
      'membership_payment.from_ba'             => $from_ba,
      'membership_payment.to_ba'               => $to_ba,
      'payment_method.adapter'                 => 'sepa_mandate',
      'payment_method.amount'                  => $amount,
      'payment_method.campaign_id'             => $this->getCampaign($donation),
      'payment_method.contact_id'              => $contactId,
      'payment_method.currency'                => 'EUR',
      'payment_method.financial_type_id'       => self::getFinancialTypeId('Member Dues'),
      'payment_method.frequency_interval'      => 12 / $frequency,
      'payment_method.frequency_unit'          => 'month',
      'payment_method.iban'                    => $iban,
      'payment_method.type'                    => 'RCUR',
    ];

    $membership = civicrm_api3('Contract', 'create', $contract_data);
    $this->storeContractFile($donation->person_id . '.pdf', $donation->pdf_content, $membership['id']);

    return ['membership_id' => $membership['id']];
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
   * Store the contribution PDF as a File entity
   *
   * @param $file_name
   * @param $content
   * @param $contribution_id
   *
   * @returns int
   */
  private static function storeContributionFile($file_name, $content, $contribution_id) {
    $config = CRM_Core_Config::singleton();
    $uri = CRM_Utils_File::makeFileName($file_name);
    $path = $config->customFileUploadDir . DIRECTORY_SEPARATOR . $uri;

    file_put_contents($path, $content);

    return Api4\File::create(FALSE)
      ->addValue('mime_type', 'application/pdf')
      ->addValue('uri', $uri)
      ->execute()
      ->first();
  }

  /**
   * Create a webshop order
   *
   * @param \CRM_Donutapp_API_Donation $donation
   * @param $contactId
   * @param array $contractResult
   *
   * @return array|bool new webshop order activity
   * @throws \CiviCRM_API3_Exception
   */
  protected function createWebshopOrder(CRM_Donutapp_API_Donation $donation, $contactId, array $contractResult) {
    $order_type = $donation->order_type;
    $shirt_type = $donation->shirt_type;
    $shirt_size = $donation->shirt_size;
    $membership_type = $donation->membership_type ?? NULL;
    if ((empty($shirt_type) || empty($shirt_size)) && empty($order_type)) {
      return FALSE;
    }
    $shirt_type = str_replace('T-Shirt Modell:', '', $shirt_type ?? '');
    $shirt_size = str_replace('T-Shirt Größe:', '', $shirt_size ?? '');

    if (empty($order_type)) {
      $order_type_map = Civi::settings()->get('donutapp_order_type_map');
      $order_type = $order_type_map[$membership_type] ?? $order_type_map['default'];
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
        $contractResult['membership_id'] ?? NULL,
      'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID('linked_contribution', 'webshop_information') =>
        $contractResult['contribution_id'] ?? NULL,
    ];
    return civicrm_api3('Activity', 'create', $params);
  }

  /**
   * Add contact to newsletter group if email and newsletter_optin are set
   *
   * @param \CRM_Donutapp_API_Donation $donation
   * @param $contactId
   *
   * @return array|bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function addToNewsletterGroup(CRM_Donutapp_API_Donation $donation, $contactId) {
    $email = $donation->donor_email;
    if (empty($email) || $donation->newsletter_optin != '1') {
      return FALSE;
    }
    Contact::update(FALSE)
      ->addValue('do_not_email', FALSE)
      ->addValue('is_opt_out', FALSE)
      ->addWhere('id', '=', $contactId)
      ->execute();
    $this->addGroup($contactId, 'Community NL');
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

    $membership_type_map = Civi::settings()->get('donutapp_membership_type_map');
    if (!empty($membership_type_map[$membership_type])) {
      $membership_type = $membership_type_map[$membership_type];
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
   * Get subject of welcome email
   *
   * @todo move this to a setting or determine via API
   *
   * @param \CRM_Donutapp_API_Entity $entity
   *
   * @return string
   */
  protected function getEmailSubject(CRM_Donutapp_API_Entity $entity) {
    return 'Wie war Ihr Gespräch?';
  }

  private static function getFinancialTypeId($name) {
    $financial_type = Api4\FinancialType::get(FALSE)
      ->addSelect('id')
      ->addWhere('name', '=', $name)
      ->execute()
      ->first();

    return $financial_type['id'];
  }

}
