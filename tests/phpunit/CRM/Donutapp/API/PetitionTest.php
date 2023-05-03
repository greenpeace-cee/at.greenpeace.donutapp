<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_Donutapp_ExtensionUtil as E;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Test petition-specific DonutApp API client features
 *
 * @group headless
 */
class CRM_Donutapp_API_PetitionTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  const PETITION_RESPONSE = '{"count":2,"total_pages":1,"next":null,"previous":null,"results":[{"donor_last_name":"Doe","uploadtime":"2019-01-17T10:20:37.649402Z","newsletter_optin":"1","uid":12345,"donor_house_number":"13","donor_salutation":2,"donor_email":"johndoe@example.com","on_hold_comment":"","campaign_id":51,"agency_id":"","donor_age_in_years":20,"donor_city":"Vienna","donor_zip_code":"1030","fundraiser_name":"Doe, Janet","comments":null,"on_hold":false,"change_note_public":"","donor_date_of_birth":"1999-01-05","donor_country":"AT","welcome_email_status":"sent","donor_first_name":"John","campaign_type":1,"organisation_id":null,"special1":"","campaign_type2":"city_campaign","donor_mobile":null,"donor_sex":2,"donor_occupation":6,"donor_phone":null,"fundraiser_code":"gpat-1337","contact_by_email":0,"change_note_private":"","special2":"","donor_street":"Landstraße","donor_academic_title":null,"person_id":"12345","pdf":"https://donutapp.mock/api/v1/petitions/pdf/?uid=12345","contact_by_phone":0,"customer_id":532,"createtime":"2019-01-17T10:20:41.966000Z","petition_id":"14"},{"donor_last_name":"Doe","uploadtime":"2019-01-17T10:15:53.078149Z","newsletter_optin":null,"uid":76543,"donor_house_number":"33","donor_salutation":2,"donor_email":"lisadoe@example.org","on_hold_comment":"","campaign_id":51,"agency_id":"","donor_age_in_years":25,"donor_city":"Graz","donor_zip_code":"8041","fundraiser_name":"Doe, Janet","comments":null,"on_hold":false,"change_note_public":"","donor_date_of_birth":"1994-03-11","donor_country":"AT","welcome_email_status":"sent","donor_first_name":"Lisa","campaign_type":1,"organisation_id":null,"special1":"","campaign_type2":"city_campaign","donor_mobile":null,"donor_sex":2,"donor_occupation":6,"donor_phone":null,"fundraiser_code":"gpat-1337","contact_by_email":0,"change_note_private":"","special2":"","donor_street":"Rathausplatz","donor_academic_title":null,"person_id":"34567","pdf":"https://donutapp.io/api/v1/petitions/pdf/?uid=76543","contact_by_phone":0,"customer_id":532,"createtime":"2019-01-17T10:15:47.396000Z","petition_id":"14"}]}';

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
    CRM_Donutapp_API_Client::setAPIEndpoint('https://donutapp.mock/api/v1/');
    CRM_Donutapp_API_Client::setOAuth2Endpoint('https://donutapp.mock/o/token/?grant_type=client_credentials');
    // mock authentication
    $mock = new MockHandler([
      new Response(200, [], CRM_Donutapp_API_ClientTest::SUCCESSFUL_AUTH_RESPONSE),
    ]);
    $stack = HandlerStack::create($mock);
    CRM_Donutapp_API_Client::setupOauth2Client(['handler' => $stack]);
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Test basic petition request without options
   *
   * @throws \CRM_Donutapp_API_Error_Authentication
   * @throws \CRM_Donutapp_API_Error_BadResponse
   * @throws \CiviCRM_API3_Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function testPetitionAll() {
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        self::PETITION_RESPONSE
      ),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push($history);
    CRM_Donutapp_API_Client::setupClient(['handler' => $stack]);
    $petitions = CRM_Donutapp_API_Petition::all();
    $petitionRequest = $container[0]['request'];

    // request should have set header Accept: application/json
    $this->assertEquals('application/json', $petitionRequest->getHeader('Accept')[0]);
    // authz header should be set to auth token
    $this->assertContains(CRM_Donutapp_API_Client::$accessToken, $petitionRequest->getHeader('Authorization')[0]);

    // we expect two petitions
    $this->assertCount(2, $petitions);
    $petition = $petitions[0];
    // array items should be instances of CRM_Donutapp_API_Petition
    $this->assertInstanceOf(CRM_Donutapp_API_Petition::class, $petition);
    // first petition should have first name "John"
    $this->assertEquals('John', $petition->donor_first_name);
  }

  /**
   * Test petition requests with limit
   */
  public function testPetitionAllWithLimit() {
    // @TODO: test with limit > no. of petitions
    // @TODO: test with limit < no. of petitions
    // @TODO: test with limit == no. of petitions
    // @TODO: test with limit && no petitions in result
    $this->assertTrue(TRUE);
  }

  /**
   * Test petition request with server error response
   */
  public function testServerError() {
    // @TODO: Test server error
    $this->assertTrue(TRUE);
  }

  /**
   * Test confirmation request
   */
  public function testConfirm() {
    // @TODO: Test successful case
    // @TODO: Test uid not matching
    // @TODO: Test unexpected status
    $this->assertTrue(TRUE);
  }

}
