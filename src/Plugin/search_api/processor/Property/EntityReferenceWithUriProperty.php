<?php

namespace Drupal\islandora\Plugin\search_api\processor\Property;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Processor\ConfigurablePropertyBase;

/**
 * Defines a "Entity Reference with URI" search property.
 *
 * @see \Drupal\islandora\Plugin\search_api\processor\EntityReferenceWithUri
 */
class EntityReferenceWithUriProperty extends ConfigurablePropertyBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'filter_uri' => '',
      'target_type' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(FieldInterface $field, array $form, FormStateInterface $form_state) {
    $configuration = $field->getConfiguration();
    $form['filter_uri'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Related entities must have this URI to be included.'),
      '#multiple' => FALSE,
      '#default_value' => $configuration['filter_uri'],
      '#required' => FALSE,
    ];
    $form['target_type'] = [
      '#type' => 'hidden',
      '#value' => $field->getDataDefinition()->getSetting('target_type'),
    ];
    return $form;
  }

}
