<?php

use CRM_Donutapp_ExtensionUtil as E;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Test petition import
 *
 * @group headless
 */
class CRM_Donutapp_Processor_Greenpeace_PetitionTest extends CRM_Donutapp_Processor_Greenpeace_BaseTest {

  private $petitionID;
  private $activityTypeID;

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->install('de.systopia.xcm')
      ->install('at.greenpeace.gpapi')
      ->install('de.systopia.identitytracker')
      ->apply(TRUE); // recreate every time: ContactType.create seems to bypass TransactionalInterface
  }

  public function setUp(): void {
    parent::setUp();

    // mock authentication
    $mock = new MockHandler([
      new Response(200, [], file_get_contents(E::path('tests/fixtures/petition-responses/successful-auth-response.json'))),
    ]);
    $stack = HandlerStack::create($mock);
    CRM_Donutapp_API_Client::setupOauth2Client(['handler' => $stack]);
  }

  protected function setUpFieldsAndData() {
    parent::setupFieldsAndData();

    $this->callAPISuccess('Group', 'create', [
      'title' => 'Donation Info',
      'name'  => 'Donation_Info',
    ]);

    $this->activityTypeID = civicrm_api3('OptionValue', 'getvalue', [
      'return'          => 'value',
      'option_group_id' => 'activity_type',
      'name'            => 'Petition',
    ]);

    $this->petitionID = $this->callAPISuccess('Survey', 'create', [
      'title'            => 'Save the Whales',
      'activity_type_id' => $this->activityTypeID,
    ])['id'];
  }

  private function getMockStack() {
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('{PETITION_ID}', $this->petitionID, file_get_contents(E::path('tests/fixtures/petition-responses/petition-response.json')))
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('"{UID}"', '12345', file_get_contents(E::path('tests/fixtures/petition-responses/confirmation-response.json')))
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('"{UID}"', '76543', file_get_contents(E::path('tests/fixtures/petition-responses/confirmation-response.json')))
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('"{UID}"', '76542', file_get_contents(E::path('tests/fixtures/petition-responses/confirmation-response.json')))
      ),
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        str_replace('"{UID}"', '53219', file_get_contents(E::path('tests/fixtures/petition-responses/confirmation-response.json')))
      ),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push($history);
    return $stack;
  }

  public function testContactCreation() {
    CRM_Donutapp_API_Client::setupClient(['handler' => $this->getMockStack()]);
    $processor = new CRM_Donutapp_Processor_Greenpeace_Petition([
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
    $contact = $this->callAPISuccess('Contact', 'getsingle', [
      'email' => 'johndoe@example.com',
    ]);
    $this->assertEquals('John', $contact['first_name']);
    $this->assertEquals('Doe', $contact['last_name']);
    // test phone set via donor_mobile
    $this->assertEquals('+43 680 1234321', $contact['phone']);
    $this->assertEquals('Male', $contact['gender']);
    $this->assertEquals('3', $contact['prefix_id']);

    $contact = $this->callAPISuccess('Contact', 'getsingle', [
      'email' => 'lisadoe@example.org',
    ]);
    $this->assertEquals('Lisa', $contact['first_name']);
    $this->assertEquals('Doe', $contact['last_name']);
    // test phone set via donor_phone
    $this->assertEquals('+43 664 1234543', $contact['phone']);
    $this->assertEquals('Female', $contact['gender']);

    $this->assertEquals(
      0,
      $this->callApiSuccess('Contact', 'getcount', [
        'last_name' => 'Discard',
      ]),
      'Should not create contact when no email AND no phone given'
    );
  }

  public function testPetitionSignature() {
    CRM_Donutapp_API_Client::setupClient(['handler' => $this->getMockStack()]);
    $processor = new CRM_Donutapp_Processor_Greenpeace_Petition([
      'client_id'     => 'client-id',
      'client_secret' => 'client-secret',
      'campaign_id'   => $this->campaignId,
      'confirm'       => TRUE,
      'limit'         => 100,
    ]);
    $processor->process();

    $contact = $this->callAPISuccess('Contact', 'getsingle', [
      'email' => 'johndoe@example.com',
    ]);
    $activity = reset($this->callAPISuccess('Activity', 'get', [
      'target_contact_id' => $contact['id'],
      'campaign_id'       => $this->campaignId,
      'activity_type_id'  => $this->activityTypeID,
    ])['values']);
    $this->assertEquals('Save the Whales', $activity['subject']);
    $this->assertEquals('2019-01-17 10:20:41', $activity['activity_date_time']);
    $dialoger_field = CRM_Core_BAO_CustomField::getCustomFieldID(
      'petition_dialoger',
      'petition_information',
      TRUE
    );
    $this->assertEquals('Doe, Janet', $activity[$dialoger_field]);
  }

  public function testWelcomeEmail() {
    $this->markTestIncomplete('requires more setup to work');
    CRM_Donutapp_API_Client::setupClient(['handler' => $this->getMockStack()]);
    $processor = new CRM_Donutapp_Processor_Greenpeace_Petition([
      'client_id'     => 'client-id',
      'client_secret' => 'client-secret',
      'campaign_id'   => $this->campaignId,
      'confirm'       => TRUE,
      'limit'         => 100,
    ]);
    $processor->process();

    $contact = $this->callAPISuccess('Contact', 'getsingle', [
      'email' => 'johndoe@example.com',
    ]);

    // find the email action activity
    $activity = reset($this->callAPISuccess('Activity', 'get', [
      'target_contact_id' => $contact['id'],
      'campaign_id'       => $this->campaignId,
      'activity_type_id'  => $this->mailingActivityTypeID,
    ])['values']);

    // activity_date_time should match signature date
    $this->assertEquals('2019-01-17 10:20:41', $activity['activity_date_time']);

    $email_medium_value = civicrm_api3('OptionValue', 'getvalue', [
      'return'          => 'value',
      'option_group_id' => 'encounter_medium',
      'name'            => 'email',
    ]);
    // encounter_medium should be email
    $this->assertEquals($email_medium_value, $activity['medium_id']);

    $email_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
      'email',
      'email_information'
    );
    // email should be stored in custom field
    $this->assertEquals('johndoe@example.com', $activity[$email_field]);
  }

  public function testNewsletter() {
    CRM_Donutapp_API_Client::setupClient(['handler' => $this->getMockStack()]);
    $processor = new CRM_Donutapp_Processor_Greenpeace_Petition([
      'client_id'     => 'client-id',
      'client_secret' => 'client-secret',
      'campaign_id'   => $this->campaignId,
      'confirm'       => TRUE,
      'limit'         => 100,
    ]);
    $processor->process();

    $newsletter_group = civicrm_api3('Group', 'getvalue', [
      'title'  => 'Community NL',
      'return' => 'id',
    ]);

    // contact with newsletter_optin = 1 ...
    $contact_id = $this->callAPISuccess('Contact', 'getvalue', [
      'email'  => 'johndoe@example.com',
      'return' => 'id',
    ]);
    $result = civicrm_api3('GroupContact', 'get', [
      'group_id'   => $newsletter_group,
      'contact_id' => $contact_id,
    ]);
    // ... should have newsletter group
    $this->assertEquals(1, $result['count']);

    // contact with newsletter_optin != 1 ...
    $contact_id = $this->callAPISuccess('Contact', 'getvalue', [
      'email'      => 'lisadoe@example.org',
      'return'     => 'id',
    ]);
    $result = civicrm_api3('GroupContact', 'get', [
      'group_id'   => $newsletter_group,
      'contact_id' => $contact_id,
    ]);
    // ... should not have newsletter group
    $this->assertEquals(0, $result['count']);
  }

}
