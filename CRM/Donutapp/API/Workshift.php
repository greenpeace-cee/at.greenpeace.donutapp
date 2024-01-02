<?php

use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\CustomValue;
use Civi\Api4\Email;
use Civi\Api4\Phone;

class CRM_Donutapp_API_Workshift {

  const TEAMS_PAGE_SIZE = 10;
  const WORKSHIFTS_PAGE_SIZE = 20;

  private static $_fundraisers;
  private static $_workshifts;

  public static function sync($offset_date = NULL) {
    $min_timestamp = is_null($offset_date) ? 0 : strtotime($offset_date);

    $count = [
      'total'   => 0,
      'created' => 0,
      'updated' => 0,
      'deleted' => 0,
    ];

    $not_found = self::getAllWorkshiftsByExternalIds();


    foreach (self::getFundraiserShifts() as $fr_shift) {
      unset($not_found[$fr_shift['id']]);

      if (strtotime($fr_shift['date']) < $min_timestamp) continue;

      $fundraiser = self::getFundraiser($fr_shift['fundraiser']);
      $dialoger = self::getOrCreateDialoger($fundraiser);
      $workshift = self::getWorkshift($fr_shift['work_shift']);

      self::importFundraiserWorkshift([
        'date'         => $fr_shift['date'],
        'entity_id'    => $dialoger['id'],
        'external_id'  => $fr_shift['id'],
        'hours'        => $workshift['weight'],
        'workshift_id' => $fr_shift['work_shift'],
      ], $count);
    }

    foreach (array_keys($not_found) as $ext_id) {
      CustomValue::delete('fundraiser_workshift')
        ->addWhere('external_id', '=', $ext_id)
        ->execute();

      $count['deleted']++;
    }

    return $count;
  }

  private static function getAllWorkshiftsByExternalIds() {
    $result = [];
    $page = 0;
    $page_size = 50;

    while (TRUE) {
      $workshifts = CustomValue::get('fundraiser_workshift')
        ->addSelect('external_id')
        ->setLimit($page_size)
        ->setOffset($page++ * $page_size)
        ->execute();

      if (is_null($workshifts->first())) break;

      foreach ($workshifts as $ws) {
        $result[$ws['external_id']] = NULL;
      }
    }

    return $result;
  }

  private static function getFundraiser($id) {
    if (!isset(self::$_fundraisers)) {
      self::$_fundraisers = [];
    }

    if (!array_key_exists($id, self::$_fundraisers)) {
      sleep(1);
      $response = CRM_Donutapp_API_Client::get("fundraisers/$id");
      self::$_fundraisers[$id] = (array) $response;
    }

    return self::$_fundraisers[$id];
  }

  private static function getFundraiserShifts() {
    $count = 0;
    $page = 1;
    $customer_filter = Civi::settings()->get('donutapp_workshift_customer_filter') ?? [];

    while (TRUE) {
      sleep(1);
      $uri = sprintf('teams/?page_size=%d&page=%d', self::TEAMS_PAGE_SIZE, $page++);
      $response = CRM_Donutapp_API_Client::get($uri);

      foreach ($response->results as $team) {
        if (!empty($customer_filter) && !in_array($team->customer, $customer_filter)) {
          // we're filtering by customer ID and this is not a customer we want to import
          continue;
        }
        foreach ($team->team_fundraiser_work_shifts as $shift) {
          yield (array) $shift;
        }
      }

      if (is_null($response->next)) break;
    }
  }

  private static function getOrCreateDialoger($fundraiser) {
    $contact = Contact::get(FALSE)
      ->addWhere('dialoger_data.dialoger_id', '=', $fundraiser['fundraiser_code'])
      ->execute()
      ->first();

    if (!is_null($contact)) return $contact;

    $contact = Contact::create(FALSE)
      ->addValue('contact_sub_type'         , ['Dialoger'])
      ->addValue('contact_type'             , 'Individual')
      ->addValue('dialoger_data.dialoger_id', $fundraiser['fundraiser_code'])
      ->addValue('first_name'               , $fundraiser['first_name'])
      ->addValue('last_name'                , $fundraiser['last_name'])
      ->execute()
      ->first();

    if (\Civi::settings()->get('donutapp_sync_fundraiser_contact_details')) {
      self::setAddress($fundraiser, $contact['id']);
      self::setEmail($fundraiser, $contact['id']);
      self::setPhoneNumber($fundraiser, $contact['id']);
    }


    return $contact;
  }

  private static function getWorkshift($id) {
    if (!isset(self::$_workshifts)) {
      self::$_workshifts = [];
      $page = 1;

      while (TRUE) {
        sleep(1);
        $uri = sprintf('workshifts/?page_size=%d&page=%d', self::WORKSHIFTS_PAGE_SIZE, $page++);
        $response = CRM_Donutapp_API_Client::get($uri);

        foreach ($response->results as $workshift) {
          self::$_workshifts[$workshift->id] = (array) $workshift;
        }

        if (is_null($response->next)) break;
      }
    }

    if (!array_key_exists($id, self::$_workshifts)) return NULL;

    return self::$_workshifts[$id];
  }

  private static function importFundraiserWorkshift($params, &$count) {
    $count['total']++;

    $fr_shift = CustomValue::get('fundraiser_workshift')
      ->addWhere('external_id', '=', $params['external_id'])
      ->setLimit(1)
      ->execute()
      ->first();

    if (is_null($fr_shift)) {
      CustomValue::create('fundraiser_workshift')
        ->addValue('date'        , $params['date'])
        ->addValue('entity_id'   , $params['entity_id'])
        ->addValue('hours'       , $params['hours'])
        ->addValue('external_id' , $params['external_id'])
        ->addValue('workshift_id', $params['workshift_id'])
        ->execute();

      $count['created']++;

      return;
    }

    if (
      $fr_shift['date'] !== $params['date']
      || $fr_shift['hours'] !== $params['hours']
    ) {
      CustomValue::update('fundraiser_workshift')
        ->addWhere('external_id', '=', $fr_shift['external_id'])
        ->addValue('date', $params['date'])
        ->addValue('hours', $params['hours'])
        ->execute();

      $count['updated']++;
    }
  }

  private static function setAddress($fr_data, $contact_id) {
    foreach (['street', 'zip_code', 'city'] as $addr_field) {
      if (empty($fr_data[$addr_field])) continue;

      Address::create(FALSE)
        ->addValue('city'                 , $fr_data['city'])
        ->addValue('contact_id'           , $contact_id)
        ->addValue('location_type_id:name', 'Home')
        ->addValue('postal_code'          , $fr_data['zip_code'])
        ->addValue('street_address'       , $fr_data['street'])
        ->execute();

      break;
    }
  }

  private static function setEmail($fr_data, $contact_id) {
    if (empty($fr_data['email'])) return;

    Email::create(FALSE)
      ->addValue('contact_id'           , $contact_id)
      ->addValue('email'                , $fr_data['email'])
      ->addValue('is_primary'           , TRUE)
      ->addValue('location_type_id:name', 'Main')
      ->execute();
  }

  private static function setPhoneNumber($fr_data, $contact_id) {
    if (empty($fr_data['phone_number'])) return;

    Phone::create(FALSE)
      ->addValue('contact_id'           , $contact_id)
      ->addValue('is_primary'           , TRUE)
      ->addValue('location_type_id:name', 'Main')
      ->addValue('phone'                , $fr_data['phone_number'])
      ->addValue('phone_type_id:name'   , 'Phone')
      ->execute();
  }

}
