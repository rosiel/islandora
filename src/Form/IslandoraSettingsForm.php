<?php

namespace Drupal\islandora\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Stomp\Client;
use Stomp\Exception\StompException;
use Stomp\StatefulStomp;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Config form for Islandora settings.
 */
class IslandoraSettingsForm extends ConfigFormBase {

  const CONFIG_NAME = 'islandora.settings';
  const UPLOAD_FORM = 'upload_form';
  const UPLOAD_FORM_LOCATION = 'upload_form_location';
  const UPLOAD_FORM_ALLOWED_MIMETYPES = 'upload_form_allowed_mimetypes';
  const GEMINI_PSEUDO = 'gemini_pseudo_bundles';
  const FEDORA_URL = 'fedora_url';
  const GEMINI_PSEUDO_FIELD = 'field_gemini_uri';

  /**
   * To list the available bundle types.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  private $entityTypeBundleInfo;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The EntityTypeBundleInfo service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManager service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->setConfigFactory($config_factory);
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
          $container->get('config.factory'),
          $container->get('entity_type.bundle.info'),
          $container->get('entity_type.manager')
      );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'islandora_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      self::CONFIG_NAME,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(self::CONFIG_NAME);

    $form[self::UPLOAD_FORM] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Add Children / Media Form'),
    ];

    $form[self::UPLOAD_FORM][self::UPLOAD_FORM_LOCATION] = [
      '#type' => 'textfield',
      '#title' => $this->t('Upload location'),
      '#description' => $this->t('Tokenized URI pattern where the uploaded file should go.  You may use tokens to provide a pattern (e.g. "fedora://[current-date:custom:Y]-[current-date:custom:m]")'),
      '#default_value' => $config->get(self::UPLOAD_FORM_LOCATION),
      '#element_validate' => ['token_element_validate'],
      '#token_types' => ['system'],
    ];

    $form[self::UPLOAD_FORM]['TOKEN_HELP'] = [
      '#theme' => 'token_tree_link',
      '#token_type' => ['system'],
    ];

    $form[self::UPLOAD_FORM][self::UPLOAD_FORM_ALLOWED_MIMETYPES] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed Mimetypes'),
      '#description' => $this->t('Add mimetypes as a space delimited list with no periods before the extension.'),
      '#default_value' => $config->get(self::UPLOAD_FORM_ALLOWED_MIMETYPES),
    ];

    $flysystem_config = Settings::get('flysystem');
    if ($flysystem_config != NULL) {
      $fedora_url = $flysystem_config['fedora']['config']['root'];
    }
    else {
      $fedora_url = NULL;
    }

    $form[self::FEDORA_URL] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fedora URL'),
      '#attributes' => ['readonly' => 'readonly'],
      '#default_value' => $fedora_url,
    ];

    $selected_bundles = $config->get(self::GEMINI_PSEUDO);

    $options = [];
    foreach (['node', 'media', 'taxonomy_term'] as $content_entity) {
      $bundles = $this->entityTypeBundleInfo->getBundleInfo($content_entity);
      foreach ($bundles as $bundle => $bundle_properties) {
        $options["{$bundle}:{$content_entity}"] =
                $this->t('@label (@type)', [
                  '@label' => $bundle_properties['label'],
                  '@type' => $content_entity,
                ]);
      }
    }

    $form['bundle_container'] = [
      '#type' => 'details',
      '#title' => $this->t('Fedora URL Display'),
      '#description' => $this->t('Selected bundles can display the Fedora URL of repository content.'),
      '#open' => TRUE,
      self::GEMINI_PSEUDO => [
        '#type' => 'checkboxes',
        '#options' => $options,
        '#default_value' => $selected_bundles,
      ],
    ];

    $form['rdf_namespaces'] = [
      '#type' => 'link',
      '#title' => $this->t('Update RDF namespace configurations in the JSON-LD module settings.'),
      '#url' => Url::fromRoute('system.jsonld_settings'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable(self::CONFIG_NAME);

    $new_pseudo_types = array_filter($form_state->getValue(self::GEMINI_PSEUDO));

    // Check for types being unset and remove the field from them first.
    $current_pseudo_types = $config->get(self::GEMINI_PSEUDO);
    $this->updateEntityViewConfiguration($current_pseudo_types, $new_pseudo_types);

    $config
      ->set(self::UPLOAD_FORM_LOCATION, $form_state->getValue(self::UPLOAD_FORM_LOCATION))
      ->set(self::UPLOAD_FORM_ALLOWED_MIMETYPES, $form_state->getValue(self::UPLOAD_FORM_ALLOWED_MIMETYPES))
      ->set(self::GEMINI_PSEUDO, $new_pseudo_types)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Removes the Fedora URI field from entity bundles that have be unselected.
   *
   * @param array $current_config
   *   The current set of entity types & bundles to have the pseudo field,
   *   format {bundle}:{entity_type}.
   * @param array $new_config
   *   The new set of entity types & bundles to have the pseudo field, format
   *   {bundle}:{entity_type}.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function updateEntityViewConfiguration(array $current_config, array $new_config) {
    $removed = array_diff($current_config, $new_config);
    $added = array_diff($new_config, $current_config);
    $entity_view_display = $this->entityTypeManager->getStorage('entity_view_display');
    foreach ($removed as $bundle_type) {
      [$bundle, $type_id] = explode(":", $bundle_type);
      $results = $entity_view_display->getQuery()
        ->condition('bundle', $bundle)
        ->condition('targetEntityType', $type_id)
        ->exists('content.' . self::GEMINI_PSEUDO_FIELD . '.region')
        ->execute();
      $entities = $entity_view_display->loadMultiple($results);
      foreach ($entities as $entity) {
        $entity->removeComponent(self::GEMINI_PSEUDO_FIELD);
        $entity->save();
      }
    }
    if (count($removed) > 0 || count($added) > 0) {
      // If we added or cleared a type then clear the extra_fields cache.
      // @see Drupal/Core/Entity/EntityFieldManager::getExtraFields
      Cache::invalidateTags(["entity_field_info"]);
    }
  }

}
