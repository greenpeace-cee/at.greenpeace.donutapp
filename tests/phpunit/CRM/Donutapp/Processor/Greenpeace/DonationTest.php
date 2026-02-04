<?php

use Civi\Api4;
use CRM_Donutapp_ExtensionUtil as E;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Test donation/contract import
 *
 * @todo DRY up code, lots of duplicated fragments from PetitionTest
 *
 * @group headless
 */
class CRM_Donutapp_Processor_Greenpeace_DonationTest extends CRM_Donutapp_Processor_Greenpeace_BaseTest {

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
      ->install('de.systopia.identitytracker')
      ->install('greenpeace_contribute')
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

  public function setUp(): void {
    $this->setUpContractExtension();
    parent::setUp();
    // mock authentication
    $mock = new MockHandler([
      new Response(200, [], file_get_contents(E::path('tests/fixtures/donation-responses/successful-auth-response.json'))),
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
          '"{EXTERNAL_CAMPAIGN_ID}"',
          $this->altCampaignId,
          file_get_contents(E::path('tests/fixtures/donation-responses/multiple-donations.json'))
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
        str_replace('"{UID}"', '12345', file_get_contents(E::path('tests/fixtures/donation-responses/confirmation-response.json')))
      ),
      new Response(
        200,
        ['Content-Type' => 'application/pdf'],
        'test pdf'
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('"{UID}"', '54321', file_get_contents(E::path('tests/fixtures/donation-responses/confirmation-response.json')))
      ),
      new Response(
        200,
        ['Content-Type' => 'application/pdf'],
        'test pdf'
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('"{UID}"', '543210', file_get_contents(E::path('tests/fixtures/donation-responses/confirmation-response.json')))
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
    $this->assertEquals('Other', $contact['gender']);
    $this->assertEquals('', $contact['prefix_id']);
    $this->assertFalse(
      $this->getLastImportError(),
      'Should not create any import error activities'
    );
    $contract = $this->callAPISuccess('Contract', 'getsingle', [
      'contact_id' => $contact['id'],
    ]);
    $this->assertEquals('2019-10-29', $contract['join_date']);
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
    $this->assertEquals($this->dialoger, $contract[$dialoger_field . '_id']);
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
    $otherContract = $this->callAPISuccess('Contract', 'getsingle', [
      'contact_id' => $otherContact['id'],
    ]);
    $this->assertEquals('Stark, Benjen', $otherContract[$dialoger_field]);
    $this->assertEquals($this->dialoger, $otherContract[$dialoger_field . '_id']);
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

  public function testContractStartDate() {
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        file_get_contents(E::path('tests/fixtures/donation-responses/donation-with-future-start-date.json'))
      ),
      new Response(
        200,
        ['Content-Type' => 'application/pdf'],
        'test pdf'
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('"{UID}"', '55441', file_get_contents(E::path('tests/fixtures/donation-responses/confirmation-response.json')))
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
    $this->assertMatchesRegularExpression(
      "/Invalid contract_start_date '2099-10-26'. Value must not be in the future/",
      $error['details']
    );
  }

  public function testOneOffDonations() {
    // Define mock responses
    $mock = new MockHandler([
      new Response(
        200,
        [ 'Content-Type' => 'application/json' ],
        file_get_contents(E::path('tests/fixtures/donation-responses/one-off-donation.json'))
      ),
      new Response(
        200,
        [ 'Content-Type' => 'application/pdf' ],
        'Test PDF'
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('"{UID}"', '543210', file_get_contents(E::path('tests/fixtures/donation-responses/confirmation-response.json')))
      ),
    ]);

    CRM_Donutapp_API_Client::setupClient([ 'handler' => HandlerStack::create($mock) ]);

    // Process incoming donations
    $processor = new CRM_Donutapp_Processor_Greenpeace_Donation([
      'client_id'     => 'client-id',
      'client_secret' => 'client-secret',
      'campaign_id'   => $this->campaignId,
      'confirm'       => TRUE,
      'limit'         => 100,
    ]);

    $processor->process();

    $this->assertFalse(
      $this->getLastImportError(),
      'Should not create any import error activities'
    );

    // Assert the contact has been created
    $contact = Api4\Contact::get(FALSE)
      ->addWhere('first_name', '=', 'Wendy')
      ->addWhere('last_name',  '=', 'Doe')
      ->addWhere('birth_date', '=', '1960-11-14')
      ->setLimit(1)
      ->execute()
      ->first();

    $this->assertNotEmpty($contact);

    // Assert a SEPA mandate has been created
    $sepa_mandate = Api4\SepaMandate::get(FALSE)
      ->addSelect(
        'entity_id',
        'entity_table',
        'iban',
        'status',
        'type'
      )
      ->addWhere('contact_id', '=', $contact['id'])
      ->execute()
      ->first();

    $this->assertEquals([
      'entity_id'    => $sepa_mandate['entity_id'],
      'entity_table' => 'civicrm_contribution',
      'iban'         => 'DE75512108001245126199',
      'id'           => $sepa_mandate['id'],
      'status'       => 'OOFF',
      'type'         => 'OOFF',
    ], $sepa_mandate);

    // Assert dialoger contact has been created
    $dialoger = Api4\Contact::get(FALSE)
      ->addWhere('contact_type', '=', 'Individual')
      ->addWhere('contact_sub_type', '=', 'Dialoger')
      ->addWhere('first_name', '=', 'Person')
      ->addWhere('last_name', '=', 'Some')
      ->setLimit(1)
      ->execute()
      ->first();

    $this->assertNotEmpty($dialoger);

    // Assert a contribution file has been created
    $file = Api4\File::get(FALSE)
      ->addWhere('mime_type', '=', 'application/pdf')
      ->addWhere('uri', 'LIKE', 'GT654321_%.pdf')
      ->execute()
      ->first();

    $this->assertNotEmpty($file);

    // Assert a Contribution has been created
    $contribution = Api4\Contribution::get(FALSE)
      ->addSelect(
        'contribution_information.channel',
        'contribution_information.contract_number',
        'contribution_information.contribution_file',
        'contribution_information.dialoger',
        'financial_type_id.name',
        'receive_date',
        'total_amount'
      )
      ->addWhere('id', '=', $sepa_mandate['entity_id'])
      ->execute()
      ->first();

    $this->assertEquals([
      'contribution_information.channel'           => 'F2F',
      'contribution_information.contract_number'   => 'GT654321',
      'contribution_information.contribution_file' => $file['id'],
      'contribution_information.dialoger'          => $dialoger['id'],
      'financial_type_id.name'                     => 'Donation',
      'id'                                         => $contribution['id'],
      'receive_date'                               => '2019-10-26 00:00:00',
      'total_amount'                               => 150.0,
    ], $contribution);

    // Assert the contact has been added to the expected Groups
    $group_contacts = (array) Api4\GroupContact::get(FALSE)
      ->addSelect('group_id:label')
      ->addWhere('contact_id.id', '=', $contact['id'])
      ->addOrderBy('group_id:label', 'ASC')
      ->execute();

    $group_labels = array_map(fn ($group) => $group['group_id:label'], $group_contacts);

    $this->assertEquals([
      'Community NL',
      'Tierfreunde',
      'Wald',
    ], $group_labels);

    // Assert an Activity for the welcome email has been created
    $welcome_email_activity = Api4\Activity::get(FALSE)
      ->addSelect('subject')
      ->addWhere('activity_type_id:name', '=', 'Online_Mailing')
      ->addWhere('target_contact_id', '=', $contact['id'])
      ->execute()
      ->first();

    $this->assertNotEmpty($welcome_email_activity);
    $this->assertEquals('Wie war Ihr Gespräch?', $welcome_email_activity['subject']);
  }

}
