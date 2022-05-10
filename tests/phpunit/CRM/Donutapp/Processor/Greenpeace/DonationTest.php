<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tdely\Luhn\Luhn;

/**
 * Test donation/contract import
 *
 * @todo DRY up code, lots of duplicated fragments from PetitionTest
 *
 * @group headless
 */
class CRM_Donutapp_Processor_Greenpeace_DonationTest extends CRM_Donutapp_Processor_Greenpeace_BaseTest {

  const SUCCESSFUL_AUTH_RESPONSE = '{"access_token": "secret", "token_type": "Bearer", "expires_in": 172800, "scope": "read write"}';
  const DONATION_RESPONSE = '{"count":3,"total_pages":1,"next":null,"previous":null,"results":[{"payment_method":"donut-sepa","on_hold_comment":"","fundraiser_code":"gpat-1337","raisenow_epp_transaction_id":null,"change_note_private":"","bank_account_bic":"","membership_channel":"Kontaktart:F2F","welcome_email_status":"sent","donor_first_name":"Jon","campaign_type":null,"bank_account_was_validated":false,"donor_occupation":4,"donor_phone":null,"donor_company_name":null,"special2":"","special1":"","location":"","donor_city":"Castle Black","donor_last_name":"Snow","organisation_id":null,"donor_salutation":2,"donor_email":"snow@thewatch.example.org","bank_account_bank_name":"","fundraiser_name":"Stark, Benjen","donor_date_of_birth":"1961-11-14","donor_country":"AT","donor_house_number":"1","bank_card_checked":null,"bank_account_holder":"Jon Snow","donor_mobile":"+43664123456","donor_street":"Main Street","donation_amount_annual":"180,00","uploadtime":"2019-10-26T14:56:25.535888Z","uid":12345,"campaign_id":260,"contact_by_email":0,"contract_start_date":"2019-10-26","change_note_public":"","on_hold":false,"donor_sex":2,"interest_group":"Tierfreunde","shirt_type":"","comments":"","person_id":"GT123456","customer_id":158,"direct_debit_interval":12,"membership_type":"Landwirtschaft","contact_by_phone":0,"topic_group":"Wald","agency_id":null,"donor_age_in_years":57,"donor_zip_code":"1234","bank_account_iban":"AT483200000012345864","donor_academic_title":null,"shirt_size":"","pdf":"https://donutapp.mock/api/v1/donations/pdf/?uid=12345","campaign_type2":null,"createtime":"2019-10-29T16:30:24.227000Z"},{"payment_method":"donut-sepa","fundraiser_code":"gpat-1337","raisenow_epp_transaction_id":null,"change_note_private":"","bank_account_bic":"","membership_channel":"Kontaktart:F2F","welcome_email_status":"sent","donor_first_name":"Jane","campaign_type":null,"bank_account_was_validated":false,"donor_occupation":4,"donor_phone":null,"donor_company_name":null,"special2":"","special1":"","location":"","donor_city":null,"donor_last_name":"Doe","organisation_id":null,"donor_salutation":2,"donor_email":"jadoe@example.org","bank_account_bank_name":"","fundraiser_name":"Some, Person","donor_date_of_birth":"1960-11-14","donor_country":null,"donor_house_number":null,"bank_card_checked":null,"bank_account_holder":"Jane Doe","donor_mobile":"+43660123456","donor_street":null,"donation_amount_annual":"150,00","uploadtime":"2019-10-26T14:56:25.535888Z","uid":54321,"campaign_id":260,"contact_by_email":0,"contract_start_date":"2019-10-26","change_note_public":"","status":"none","donor_sex":1,"interest_group":"Tierfreunde","shirt_type":"","comments":"","person_id":"GT123457","customer_id":158,"direct_debit_interval":12,"membership_type":"Landwirtschaft","contact_by_phone":0,"topic_group":"Wald","agency_id":null,"donor_age_in_years":57,"donor_zip_code":null,"bank_account_iban":"DE75512108001245126199","donor_academic_title":null,"shirt_size":"","external_campaign_id":{EXTERNAL_CAMPAIGN_ID},"external_contact_id":{EXTERNAL_CONTACT_ID},"newsletter_optin":"1","pdf":"https://donutapp.mock/api/v1/donations/pdf/?uid=54321","campaign_type2":null,"createtime":"2019-10-29T16:30:24.227000Z"},{"payment_method":"donut-sepa","on_hold_comment":"","fundraiser_code":"gpat-1337","raisenow_epp_transaction_id":null,"change_note_private":"","bank_account_bic":"","membership_channel":"Kontaktart:F2F","welcome_email_status":"sent","donor_first_name":"Wendy","campaign_type":null,"bank_account_was_validated":false,"donor_occupation":4,"donor_phone":null,"donor_company_name":null,"special2":"","special1":"","location":"","donor_city":null,"donor_last_name":"Doe","organisation_id":null,"donor_salutation":2,"donor_email":"wedoe@example.org","bank_account_bank_name":"","fundraiser_name":"Some, Person","donor_date_of_birth":"1960-11-14","donor_country":null,"donor_house_number":null,"bank_card_checked":null,"bank_account_holder":"Wendy Doe","donor_mobile":"+43660123451","donor_street":null,"donation_amount_annual":"150,00","uploadtime":"2019-10-26T14:56:25.535888Z","uid":543210,"campaign_id":261,"contact_by_email":0,"contract_start_date":"2019-10-26","change_note_public":"","on_hold":false,"donor_sex":1,"interest_group":"Tierfreunde","shirt_type":"","comments":"","person_id":"GT123458","customer_id":158,"direct_debit_interval":12,"membership_type":"Landwirtschaft","contact_by_phone":0,"topic_group":"Wald","agency_id":null,"donor_age_in_years":57,"donor_zip_code":null,"bank_account_iban":"DE75512108001245126199","donor_academic_title":null,"shirt_size":"","newsletter_optin":"1","pdf":"https://donutapp.mock/api/v1/donations/pdf/?uid=543210","campaign_type2":null,"createtime":"2019-10-29T16:30:24.227000Z"}]}';
  const DONATION_RESPONSE_JOIN_DATE = '{"count":1,"total_pages":1,"next":null,"previous":null,"results":[{"payment_method":"donut-sepa","on_hold_comment":"","fundraiser_code":"gpat-1337","raisenow_epp_transaction_id":null,"change_note_private":"","bank_account_bic":"","membership_channel":"Kontaktart:F2F","welcome_email_status":"sent","donor_first_name":"Jon","campaign_type":null,"bank_account_was_validated":false,"donor_occupation":4,"donor_phone":null,"donor_company_name":null,"special2":"","special1":"","location":"","donor_city":"Castle Black","donor_last_name":"Snow","organisation_id":null,"donor_salutation":2,"donor_email":"snow@thewatch.example.org","bank_account_bank_name":"","fundraiser_name":"Stark, Benjen","donor_date_of_birth":"1961-11-14","donor_country":"AT","donor_house_number":"1","bank_card_checked":null,"bank_account_holder":"Jon Snow","donor_mobile":"+43664123456","donor_street":"Main Street","donation_amount_annual":"180,00","uploadtime":"2019-10-26T14:56:25.535888Z","uid":55441,"campaign_id":260,"contact_by_email":0,"contract_start_date":"2099-10-26","change_note_public":"","on_hold":false,"donor_sex":2,"interest_group":"Tierfreunde","shirt_type":"","comments":"","person_id":"GT123459","customer_id":158,"direct_debit_interval":12,"membership_type":"Landwirtschaft","contact_by_phone":0,"topic_group":"Wald","agency_id":null,"donor_age_in_years":57,"donor_zip_code":"1234","bank_account_iban":"AT483200000012345864","donor_academic_title":null,"shirt_size":"","pdf":"https://donutapp.mock/api/v1/donations/pdf/?uid=55441","campaign_type2":null,"createtime":"2019-10-29T16:30:24.227000Z"}]}';
  const CONFIRMATION_RESPONSE = '[{"status":"success","message":"","uid":{UID},"confirmation_date":"2019-10-30T11:25:12.335209Z"}]';

  private $altCampaignId;
  private $mappedCampaignId;
  private $contactId;

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->install('de.systopia.xcm')
      ->install('org.project60.sepa')
      ->install('org.project60.banking')
      ->install('de.systopia.contract')
      ->apply(TRUE);
  }

  /**
   * Setup contract extension and its dependencies
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function setUpContractExtension() {
    $default_creditor_id = (int) CRM_Sepa_Logic_Settings::getSetting('batching_default_creditor');
    if (empty($default_creditor_id)) {
      // create if there isn't
      $creditor = $this->callAPISuccess('SepaCreditor', 'create', [
        'creditor_type'  => 'SEPA',
        'currency'       => 'EUR',
        'mandate_active' => 1,
        'iban'           => 'AT483200000012345864',
        'uses_bic'       => FALSE,
      ]);
      CRM_Sepa_Logic_Settings::setSetting($creditor['id'], 'batching_default_creditor');
    }
    $default_creditor_id = (int) CRM_Sepa_Logic_Settings::getSetting('batching_default_creditor');
    $this->assertNotEmpty($default_creditor_id, "There is no default SEPA creditor set");
  }

  public function setUp() {
    $this->setUpContractExtension();
    parent::setUp();
    // mock authentication
    $mock = new MockHandler([
      new Response(200, [], self::SUCCESSFUL_AUTH_RESPONSE),
    ]);
    $stack = HandlerStack::create($mock);
    CRM_Donutapp_API_Client::setupOauth2Client(['handler' => $stack]);
  }

  private function getMockStack() {
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace(
          ['{EXTERNAL_CAMPAIGN_ID}', '{EXTERNAL_CONTACT_ID}'],
          [$this->altCampaignId, Luhn::create($this->contactId)],
          self::DONATION_RESPONSE
        )
      ),
      new Response(
        200,
        ['Content-Type' => 'application/pdf'],
        'test pdf'
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('{UID}', '12345', self::CONFIRMATION_RESPONSE)
      ),
      new Response(
        200,
        ['Content-Type' => 'application/pdf'],
        'test pdf'
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('{UID}', '54321', self::CONFIRMATION_RESPONSE)
      ),
      new Response(
        200,
        ['Content-Type' => 'application/pdf'],
        'test pdf'
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('{UID}', '543210', self::CONFIRMATION_RESPONSE)
      ),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push($history);
    return $stack;
  }

  protected function setUpFieldsAndData() {
    parent::setupFieldsAndData();

    $this->altCampaignId = reset($this->callAPISuccess('Campaign', 'create', [
      'name'                => 'DDTFR',
      'title'               => 'Direct Dialog TFR',
      'external_identifier' => 'DDTFR',
    ])['values'])['id'];

    $this->mappedCampaignId = reset($this->callAPISuccess('Campaign', 'create', [
      'name'                => 'DDMAP',
      'title'               => 'Direct Dialog Mapped',
      'external_identifier' => 'DDMAP',
    ])['values'])['id'];

    Civi::settings()->set('donutapp_campaign_map', ['261' => $this->mappedCampaignId]);

    $this->contactId = reset($this->callAPISuccess('Contact', 'create', [
      'email'        => 'random@example.org',
      'contact_type' => 'Individual',
    ])['values'])['id'];

    $this->callAPISuccess('MembershipType', 'create', [
      'member_of_contact_id' => 1,
      'financial_type_id'    => 'Member Dues',
      'duration_unit'        => 'lifetime',
      'duration_interval'    => 1,
      'period_type'          => 'rolling',
      'name'                 => 'Landwirtschaft',
    ]);

    $this->callAPISuccess('OptionValue', 'create', [
      'option_group_id' => 'contact_channel',
      'value'           => 'F2F',
      'label'           => 'F2F',
    ]);

    $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => 'membership_general',
      'label'           => 'contract_file',
      'data_type'       => 'File',
      'html_type'       => 'File',
    ]);

    $this->callAPISuccess('Group', 'create', [
      'title' => 'Tierfreunde',
    ]);

    $this->callAPISuccess('Group', 'create', [
      'title' => 'Wald',
    ]);
  }

  public function testContractCreation() {
    CRM_Donutapp_API_Client::setupClient(['handler' => $this->getMockStack()]);
    $processor = new CRM_Donutapp_Processor_Greenpeace_Donation([
      'client_id'     => 'client-id',
      'client_secret' => 'client-secret',
      'campaign_id'   => $this->campaignId,
      'confirm'       => TRUE,
      'limit'         => 100,
    ]);
    $processor->process();
    $contact = $this->callAPISuccess('Contact', 'getsingle', [
      'email' => 'snow@thewatch.example.org',
    ]);
    $this->assertEquals('Jon', $contact['first_name']);
    $this->assertEquals('Snow', $contact['last_name']);
    $this->assertFalse(
      $this->getLastImportError(),
      'Should not create any import error activities'
    );
    $contract = $this->callAPISuccess('Contract', 'getsingle', [
      'contact_id' => $contact['id'],
    ]);
    $this->assertEquals('2019-10-26', $contract['join_date']);
    $this->assertEquals(date('Y-m-d'), $contract['start_date']);
    $number_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
    'membership_contract',
    'membership_general'
    );
    $this->assertEquals('GT123456', $contract[$number_field]);
    $dialoger_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'membership_dialoger',
      'membership_general'
    );
    $this->assertEquals('Stark, Benjen', $contract[$dialoger_field]);
    $channel_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'membership_channel',
      'membership_general'
    );
    $this->assertEquals('F2F', $contract[$channel_field]);
    $annual_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
        'membership_annual',
        'membership_payment'
      );
    $this->assertEquals('180.00', $contract[$annual_field]);
    $mandate = $this->callAPISuccess('SepaMandate', 'getsingle', [
      'contact_id' => $contact['id'],
    ]);
    $this->assertEquals('civicrm_contribution_recur', $mandate['entity_table']);
    $this->assertEquals(CRM_Sepa_Logic_Settings::getSetting('batching_default_creditor'), $mandate['creditor_id']);
    $this->assertEquals('AT483200000012345864', $mandate['iban']);
    $this->assertEquals('NOTPROVIDED', $mandate['bic']);
    $this->assertEquals('RCUR', $mandate['type']);
    $this->assertEquals('FRST', $mandate['status']);

    $this->assertEquals(
      0,
      $this->callAPISuccess('GroupContact', 'getcount', [
        'group_id'   => 'Community_NL',
        'contact_id' => $contact['id'],
      ]),
      'Should not be in group Community NL'
    );
    $this->assertEquals(
      $this->campaignId,
      $contract['campaign_id'],
      'Default campaign should be used'
    );

    $otherContact = $this->callAPISuccess('Contact', 'getsingle', [
      'email' => 'jadoe@example.org',
    ]);
    $this->assertEquals(
      $this->contactId,
      $otherContact['id'],
      'Contact should have been matched via external_contact_id'
    );
    $otherContract = $this->callAPISuccess('Contract', 'getsingle', [
      'contact_id' => $otherContact['id'],
    ]);
    $this->assertEquals(
      $this->altCampaignId,
      $otherContract['campaign_id'],
      'Campaign specified via external_campaign_id should be used'
    );
    $this->assertEquals(
      1,
      $this->callAPISuccess('GroupContact', 'getcount', [
        'group_id'   => 'Community_NL',
        'contact_id' => $otherContact['id'],
      ]),
      'Should be in group Community NL'
    );

    $mappedContact = $this->callAPISuccess('Contact', 'getsingle', [
      'email' => 'wedoe@example.org',
    ]);
    $mappedContract = $this->callAPISuccess('Contract', 'getsingle', [
      'contact_id' => $mappedContact['id'],
    ]);
    $this->assertEquals(
      $this->mappedCampaignId,
      $mappedContract['campaign_id'],
      'Campaign mapped via donutapp_campaign_map setting should be used'
    );
  }

  public function testContractJoinDate() {
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace(
          ['{EXTERNAL_CAMPAIGN_ID}', '{EXTERNAL_CONTACT_ID}'],
          [$this->altCampaignId, Luhn::create($this->contactId)],
          self::DONATION_RESPONSE_JOIN_DATE
        )
      ),
      new Response(
        200,
        ['Content-Type' => 'application/pdf'],
        'test pdf'
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('{UID}', '55441', self::CONFIRMATION_RESPONSE)
      ),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push($history);
    CRM_Donutapp_API_Client::setupClient(['handler' => $stack]);
    $processor = new CRM_Donutapp_Processor_Greenpeace_Donation([
      'client_id' => 'client-id',
      'client_secret' => 'client-secret',
      'campaign_id' => $this->campaignId,
      'confirm' => TRUE,
      'limit' => 100,
    ]);
    $processor->process();
    $error = $this->getLastImportError();
    $this->assertRegexp(
      "/Invalid contract_start_date '2099-10-26'. Value must not be in the future/",
      $error['details']
    );
  }

}
