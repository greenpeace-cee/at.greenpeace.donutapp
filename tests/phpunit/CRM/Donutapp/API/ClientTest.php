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
 * Test DonutApp API client
 *
 * @group headless
 */
class CRM_Donutapp_API_ClientTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  const ACCESS_TOKEN = '9QYEBKqnu8sJruCFyZtjeTJmXiLTEa';
  const SUCCESSFUL_AUTH_RESPONSE = '{"access_token": "' . self::ACCESS_TOKEN . '", "token_type": "Bearer", "expires_in": 172800, "scope": "read write"}';
  const FAILED_AUTH_RESPONSE = '{"error": "invalid_client"}';
  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    CRM_Donutapp_API_Client::setAPIEndpoint('https://donutapp.mock/api/v1/');
    CRM_Donutapp_API_Client::setOAuth2Endpoint('https://donutapp.mock/o/token/?grant_type=client_credentials');
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  /**
   * Test a successful login attempt
   *
   * @throws \CRM_Donutapp_API_Error_Authentication
   * @throws \CRM_Donutapp_API_Error_BadResponse
   * @throws \CiviCRM_API3_Exception
   */
  public function testLoginSuccess() {
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(200, [], self::SUCCESSFUL_AUTH_RESPONSE),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push($history);
    CRM_Donutapp_API_Client::setupOauth2Client(['handler' => $stack]);
    CRM_Donutapp_API_Client::setClientId('client_id_xxx');
    CRM_Donutapp_API_Client::setClientSecret('client_secret_xxx');
    $this->assertEquals(self::ACCESS_TOKEN, CRM_Donutapp_API_Client::getAccessToken());
    $loginRequest = $container[0]['request'];
    $this->assertEquals('POST', $loginRequest->getMethod());
    $authz = explode(' ', $loginRequest->getHeader('Authorization')[0]);
    // $authz[1] holds base64-encoded client_id/secret
    $this->assertEquals('client_id_xxx:client_secret_xxx', base64_decode($authz[1]));
  }

  /**
   * Test that a failed login throws an exception
   *
   * @throws \CRM_Donutapp_API_Error_Authentication
   * @throws \CRM_Donutapp_API_Error_BadResponse
   * @throws \CiviCRM_API3_Exception
   */
  public function testLoginError() {
    $this->expectException(CRM_Donutapp_API_Error_Authentication::class);
    $mock = new MockHandler([
      new Response(401, [], self::FAILED_AUTH_RESPONSE),
    ]);
    $stack = HandlerStack::create($mock);
    CRM_Donutapp_API_Client::setupOauth2Client(['handler' => $stack]);
    CRM_Donutapp_API_Client::setClientId('wrong_client_id_xxx');
    CRM_Donutapp_API_Client::setClientSecret('client_secret_xxx');
    CRM_Donutapp_API_Client::getAccessToken();
  }

  /**
   * Test that a server error during login throws an exception
   *
   * @throws \CRM_Donutapp_API_Error_Authentication
   * @throws \CRM_Donutapp_API_Error_BadResponse
   * @throws \CiviCRM_API3_Exception
   */
  public function testLoginServerError() {
    $this->expectException(CRM_Donutapp_API_Error_BadResponse::class);
    $mock = new MockHandler([
      new Response(500, [], 'Server Error'),
    ]);
    $stack = HandlerStack::create($mock);
    CRM_Donutapp_API_Client::setupOauth2Client(['handler' => $stack]);
    CRM_Donutapp_API_Client::setClientId('wrong_client_id_xxx');
    CRM_Donutapp_API_Client::setClientSecret('client_secret_xxx');
    CRM_Donutapp_API_Client::getAccessToken();
  }
}
