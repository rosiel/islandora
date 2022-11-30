<?php

namespace Drupal\islandora\Plugin\search_api\processor;

use Drupal\islandora\Plugin\search_api\processor\Property\EntityReferenceWithUriProperty;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\islandora\IslandoraUtils;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add a filterable search api field for entity reference fields.
 *
 * @SearchApiProcessor(
 *   id = "entity_reference_with_uri",
 *   label = @Translation("Entity Reference with a taxonomy term with a URI"),
 *   description = @Translation("Indexes the title of Entity Reference field targets, where the targets have a taxonomy term with a certain URI. Excludes Entity Reference fields that refer to taxonomy terms."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class EntityReferenceWithUri extends ProcessorPluginBase {

  /**
   * Islandora utils.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected IslandoraUtils $utils;

  /**
   * Constructor.
   *
   * @param array $configuration
   *   The plugin configuration, i.e. an array with configuration values keyed
   *   by configuration option name. The special key 'context' may be used to
   *   initialize the defined contexts by setting it to an array of context
   *   values keyed by context names.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\islandora\IslandoraUtils $utils
   *   Islandora utils.
   */
  public function __construct(
    array $configuration,
          $plugin_id,
          $plugin_definition,
    IslandoraUtils $utils
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->utils = $utils;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('islandora.utils'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];
    if (!$datasource || !$datasource->getEntityTypeId()) {
      return $properties;
    }

    $entity_type = $datasource->getEntityTypeId();

    // Get all configured Entity Relation fields for this entity type.
    $fields = \Drupal::entityTypeManager()->getStorage('field_config')->loadByProperties([
      'entity_type' => $entity_type,
      'field_type' => 'entity_reference',
    ]);

    foreach ($fields as $field) {
      // Skip over fields that point to taxonomy term fields themselves.
      if ($field->getSetting('target_type') == 'taxonomy_term') {
        continue;
      }

      $definition = [
        'label' => $this->t('@label (by term URI) [@bundle]', [
          '@label' => $field->label(),
          '@bundle' => $field->getTargetBundle(),
        ]),
        'description' => $this->t('Index the target entity, but only if the target entity has a taxonomy term with a given URI.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
        'settings' => [
          'filter_uri' => '',
          'target_type' => $field->getSetting('target_type'),
        ],
        'is_list' => TRUE,
      ];
      $fieldname = 'entity_reference_with_uri__' . str_replace('.', '__', $field->id());
      $properties[$fieldname] = new EntityReferenceWithUriProperty($definition);
    }
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    // Skip if no Entity Reference with URI fields are configured.
    $relevant_search_api_fields = [];
    $search_api_fields = $item->getFields(FALSE);
    foreach ($search_api_fields as $field) {
      if (substr($field->getPropertyPath(), 0, 27) == 'entity_reference_with_uri__') {
        $relevant_search_api_fields[] = $field;
      }
    }
    if (empty($relevant_search_api_fields)) {
      return;
    }

    $content_entity = $item->getOriginalObject()->getValue();
    $type_and_bundle_prefix = $content_entity->getEntityTypeId() . '__' . $content_entity->bundle() . '__';
    foreach ($relevant_search_api_fields as $search_api_field) {
      $entity_type = $search_api_field->getConfiguration()['target_type'];
      $filter_uri = $search_api_field->getConfiguration()['filter_uri'];
      $field_name = substr($search_api_field->getPropertyPath(), 27);
      $field_name = str_replace($type_and_bundle_prefix, '', $field_name);
      if ($content_entity->hasField($field_name)) {
        foreach ($content_entity->get($field_name)->getValue() as $values) {
          foreach ($values as $value) {
            $target_entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($value);
            if ($this->utils->entityHasTermWithUri($target_entity, $filter_uri)) {
              $label = $target_entity->label();
              $search_api_field->addValue($label);
            }
          }
        }
      }
    }
  }

}
