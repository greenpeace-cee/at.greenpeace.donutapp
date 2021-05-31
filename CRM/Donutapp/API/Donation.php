<?php

class CRM_Donutapp_API_Donation extends CRM_Donutapp_API_Entity {

  private $special;

  public function __construct($data) {
    parent::__construct($data);

    if (!empty($this->special1)) {
      $this->parseSpecial($this->special1);
    }
    if (!empty($this->special2)) {
      $this->parseSpecial($this->special2);
    }
  }

  /**
   * Return the requested property from one of the special<N> fields
   *
   * @param $property
   *
   * @return mixed
   */
  public function getSpecial($property) {
    if (array_key_exists($property, $this->special)) {
      return $this->data[$property];
    }
    return NULL;
  }

  /**
   * Parses data in the CSV-style key/value format present in special1/special2
   *
   * @param $value CSV-style key/value string in the following format:
   *               key1;key2:value3;key3;key4:value4
   *               valueless keys are interpreted as booleans (i.e. set to TRUE)
   *
   * @todo are we sure about the format? in my instance it was simply 'value1;value2'
   */
  private function parseSpecial($value) {
    foreach (explode(';', $value) as $field) {
      if (strpos($field, ':') === FALSE) {
        $this->special[$field] = TRUE;
      }
      else {
        $this->special[$field] = explode(':', $field)[1];
      }
    }
  }

  /**
   * Fetch all donations ready for retrieval
   *
   * @param array $options
   *
   * @return CRM_Donutapp_API_Donation[]
   * @throws \CRM_Donutapp_API_Error_Authentication
   * @throws \CRM_Donutapp_API_Error_BadResponse
   * @throws \CiviCRM_API3_Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public static function all(array $options = []) {
    $hasNextPage = TRUE;
    $nextUri = NULL;
    $page_size = 100;
    $limit = PHP_INT_MAX;
    if (array_key_exists('limit', $options) && $options['limit'] != 0) {
      $limit = $options['limit'];
      if ($limit < $page_size) {
        $page_size = $limit;
      }
    }

    $donations = [];

    while ($hasNextPage && $limit > 0) {
      if (is_null($nextUri)) {
        $result = CRM_Donutapp_API_Client::get(
          CRM_Donutapp_API_Client::buildUri('donations', [
            'page_size' => $page_size
          ])
        );
      }
      else {
        $result = CRM_Donutapp_API_Client::get($nextUri);
      }
      $hasNextPage = !empty($result->next);
      if ($hasNextPage) {
        $nextUri = $result->next;
      }
      if ($result->count > 0) {
        foreach ($result->results as $donation) {
          if ($limit == 0) {
            break;
          }
          $donations[] = new CRM_Donutapp_API_Donation((array) $donation);
          $limit--;
        }
      }
    }
    return $donations;
  }

  /**
   * Confirm the retrieval of this donation
   *
   * @throws \CRM_Donutapp_API_Error_Authentication
   * @throws \CRM_Donutapp_API_Error_BadResponse
   * @throws \CiviCRM_API3_Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function confirm() {
    $response = CRM_Donutapp_API_Client::postJSON(
      CRM_Donutapp_API_Client::buildUri('/donations/confirm_retrieval'),
      [
        [
          'uid' => $this->uid,
          'status' => 'success',
          'message' => '',
        ],
      ]
    );

    foreach ($response as $confirmation) {
      if ($confirmation->uid != $this->uid) {
        throw new CRM_Donutapp_API_Error_BadResponse(
          'Error confirming donation retrieval: UID "' . $confirmation->uid . '" does not match "' . $this->uid
        );
      }
      if ($confirmation->status != 'success') {
        throw new CRM_Donutapp_API_Error_BadResponse(
          'Error confirming donation retrieval: got status "' . $confirmation->status . '" after confirmation'
        );
      }
    }
  }

  /**
   * Fetches and returns the PDF for this donation
   *
   * PDF is cached in $this->pdf_content after first retrieval
   *
   * @return mixed
   */
  public function fetchPdf() {
    $this->data['pdf_content'] = CRM_Donutapp_API_Client::getRaw($this->pdf);
    return $this->data['pdf_content'];
  }

}
