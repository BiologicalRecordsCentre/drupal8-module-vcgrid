<?php

namespace Drupal\vcgrid\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Response;

class VcgridForm extends FormBase {
  public function getFormId() {
    return 'vcgrid_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    //Attache library in the form to include CSS & JS files.
    $form['#attached']['library'][] = 'vcgrid/vc_grid';

    include 'counties.inc';
    include 'map.inc';

    $form['map-container'] = array(
        '#type' => 'container',
        '#attributes' => array(
            'class' => array('vcgrid-map-container')
        )
    );

    $form['map-container']['map'] = array(
        '#type' => 'markup',
        '#markup' => $map,
        '#allowed_tags' => array('img','map', 'area'),
    );

    $form['input-container'] = array(
        '#type' => 'container',
        '#attributes' => array(
            'class' => array('vcgrid-input-container')
        ),
    );

    $form['input-container']['intro'] = array(
        '#markup' =>
        '<p>' .
        $this->t('Use this page to obtain a list of all the 10km, 2km or 1km '
            . 'British National Grid squares that overlap a Vice County.') .
        '</p>' .
        '<p>' .
        $this->t('Set your chosen square size and then either click a vice county on the '
            . 'map or select it from the drop down list and submit.') .
        '</p>' .
        '<p>' .
        $this->t('To obtain 2km/1km lists for the whole of Britain, please '
                . '<a href="contact">contact us</a>') .
        '</p>'
    );

    $form['input-container']['resolution'] = array(
        '#type' => 'radios',
        '#title' => $this->t('Choose a square size:'),
        '#options' => array(
            '10' => $this->t('10km'),
            '2' => $this->t('2km'),
            '1' => $this->t('1km'),
        ),
        '#default_value' => '10',
    );

    $form['input-container']['vice_county'] = array(
        '#type' => 'select',
        '#title' => $this->t('Choose a vice county:'),
        '#options' => $vice_counties,
        '#default_value' => '0',
    );

    $form['input-container']['submit'] = array(
        '#type' => 'submit',
        '#value' => $this->t('Submit'),
        '#ajax' => array(
            'callback' => '::vcgrid_form_submit_ajax',
            'wrapper' => 'vcgrid-response',
            'effect' => 'fade',
        ),
    );

    $form['input-container']['download'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Download'),
      '#visible' => true,
    );


    $form['response-container'] = array(
        '#type' => 'container',
        '#attributes' => array(
            'class' => array('vcgrid-response-container')
        ),
    );

    $form['response-container']['response'] = array(
        '#markup' => '',
        '#prefix' => '<div id="vcgrid-response">',
        '#suffix' => '</div>',
    );

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $resolution = $form_state->getValue('resolution');
    $vice_county = $form_state->getValue('vice_county');
    if ($vice_county === '0' && $resolution !== '10') {
        $form_state->setErrorByName('vice_county',$this->t("Sorry, only 10km squares can be downloaded for the "
        . "whole country. Choose a vice county or the 10km option."));
    }
  }

  /**
   * Implements hook_form_submit.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $resolution = $form_state->getValue('resolution');
    $vc_number = $form_state->getValue('vice_county');
    switch ($form_state->getValue('op')->__toString()){
      case 'Submit':
        $output = self::vcgrid_get_squares_table($resolution, $vc_number);
        drupal_set_message($output);
        break;
      case 'Download':
        $this->vcgrid_get_csv($form, $form_state);
        break;
      }
  }

  function vcgrid_form_submit_ajax(array &$form, FormStateInterface $form_state) {
    return $form['response-container']['response'];
  }

  function vcgrid_get_squares_table($resolution, $vc_number) {
    include 'counties.inc';
    $vice_county = $vice_counties[$vc_number];

    $squares = self::vcgrid_get_squares_data($resolution, $vc_number);
    if ($squares === FALSE) {
      return '<p>Sorry, we had a problem processing your request. '
              . 'Please would you try again later. If the problem persists, '
              . 'do <a href="contact">let us know</a>.</p>';
    }
    $vcgrid_theme = [
      '#theme' => 'vcgrid_response',
      '#resolution' => $resolution,
      '#vc_number' => $vc_number,
      '#vice_county' => $vice_county,
      '#squares' => $squares,
    ];
    $output = \Drupal::service('renderer')->render($vcgrid_theme);
    return $output;
  }
  
  function vcgrid_get_csv($form,$form_state) {
    $rows = array();
    // Obtain parameters from query string
    include 'counties.inc';
    $resolution = $form_state->getValue('resolution');
    $vc_number = $form_state->getValue('vice_county');
    $vice_county = str_replace(' ', '-', strtolower($vice_counties[$vc_number]));

    // Get the data
    $squares = $this->vcgrid_get_squares_data($resolution, $vc_number);
    if ($squares === FALSE) {
      return 'Sorry, we had a problem processing your request. '
              . 'Please would you try again later. If the problem persists, '
              . 'do let us know.';
    }

    // Output column headings
    $headings = array();
    if ($vc_number === '0') {
      $headings[] = 'VC';
    }
    $headings[] = 'Square';
    $headings[] = '% Land';
    $headings[] = '% Sea';
    $rows[] = implode(',',$headings);

    foreach ($squares as $row) {
      $data = array($row->gridref,$row->percent_land,$row->percent_sea);
      $rows[] = implode(',',$data);
    }

    $format = $vice_county.'-'.$resolution.'km-squares.csv';
    $contents = implode("\n",$rows);
    $response = new Response($contents);
    $response->headers->set('Content-Type', 'text/csv; utf-8');
    $response->headers->set('Content-Disposition','attachment;filename="'. $format .'"');
    $form_state->setResponse($response);
  }

  function vcgrid_get_squares_data($resolution, $vc_number) {
    // Determine query values for selected resolution.
    switch ($resolution) {
      case '10':
        $table_name = 'vcgrid_tenk_intersect';
        $field_name = 'tenk_gridref';
        break;
      case '2':
        $table_name = 'vcgrid_twok_intersect';
        $field_name = 'twok_gridref';
        break;
      case '1':
        $table_name = 'vcgrid_onek_intersect';
        $field_name = 'onek_gridref';
        break;
      default:
        // Error. Unknown resolution.
        return FALSE;
    }

    // Construct query on Oracle database.
    if ($vc_number === '0' && $resolution === '10') {
      // Return squares for all counties but only at 10km resolution.
      $query = "SELECT vc_key, "
              . "$field_name AS gridref, "
              . "FORMAT(percent_land_overlap, 1) AS percent_land, "
              . "FORMAT(percent_sea_overlap, 1) AS percent_sea " .
        "FROM $table_name " .
        "WHERE percent_land_overlap > 0 " .
        "ORDER BY vc_key, $field_name";
    }
    elseif ($vc_number === '0'){
      // Error. All counties not available at other resolutions
      return FALSE;
    }
    else {
      // Return squares for selected county.
      $query = "SELECT $field_name AS gridref, "
              . "FORMAT(percent_land_overlap, 1) AS percent_land, "
              . "FORMAT(percent_sea_overlap, 1) AS percent_sea " .
        "FROM $table_name " .
        "WHERE percent_land_overlap > 0 AND vc_key = :vc_number " .
        "ORDER BY vc_key, $field_name";
    }
    // Perform the logic of the query
    $r = db_query($query, array(':vc_number' => $vc_number));

    // Fetch the results of the query
    $output = $r->fetchAll();

    return $output;
  }
}