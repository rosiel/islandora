<?php

namespace Drupal\islandora\MediaSource;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\huacaya\MediaSource;
use Drupal\islandora\IslandoraUtils;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Utility functions for working with source files for Media.
 */
class MediaSourceService extends MediaSource\MediaSourceService{

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * Language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Islandora Utility service.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected $islandoraUtils;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   Language manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   File system service.
   * @param \Drupal\islandora\IslandoraUtils $islandora_utils
   *   Utility service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $account,
    LanguageManagerInterface $language_manager,
    FileSystemInterface $file_system,
    IslandoraUtils $islandora_utils
  ) {
    parent::__construct($entity_type_manager, $account, $language_manager, $file_system);
    $this->islandoraUtils = $islandora_utils;
  }

  /**
   * Creates a new Media using the provided resource, adding it to a Node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to reference the newly created Media.
   * @param \Drupal\media\MediaTypeInterface $media_type
   *   Media type for new media.
   * @param \Drupal\taxonomy\TermInterface $taxonomy_term
   *   Term from the 'Behavior' vocabulary to give to new media.
   * @param resource $resource
   *   New file contents as a resource.
   * @param string $mimetype
   *   New mimetype of contents.
   * @param string $content_location
   *   Drupal/PHP stream wrapper for where to upload the binary.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   */
  public function putToNode(
    NodeInterface $node,
    MediaTypeInterface $media_type,
    TermInterface $taxonomy_term,
    $resource,
    $mimetype,
    $content_location
  ) {
    $existing = $this->islandoraUtils->getMediaReferencingNodeAndTerm($node, $taxonomy_term);

    if (!empty($existing)) {
      // Just update already existing media.
      $media = $this->entityTypeManager->getStorage('media')->load(reset($existing));
      $this->updateSourceField(
          $media,
          $resource,
          $mimetype
      );
      return FALSE;
    }
    else {
      // Otherwise, the media doesn't exist yet.
      // So make everything by hand.
      // Get the source field for the media type.
      $bundle = $media_type->id();
      $source_field = $this->getSourceFieldName($bundle);
      if (empty($source_field)) {
        throw new NotFoundHttpException("Source field not set for $bundle media");
      }

      // Construct the File.
      $file = $this->entityTypeManager->getStorage('file')->create([
        'uid' => $this->account->id(),
        'uri' => $content_location,
        'filename' => $this->fileSystem->basename($content_location),
        'filemime' => $mimetype,
      ]);
      $file->setPermanent();

      // Validate file extension.
      $source_field_config = $this->entityTypeManager->getStorage('field_config')->load("media.$bundle.$source_field");
      $valid_extensions = $source_field_config->getSetting('file_extensions');
      $errors = file_validate_extensions($file, $valid_extensions);

      if (!empty($errors)) {
        throw new BadRequestHttpException("Invalid file extension.  Valid types are $valid_extensions");
      }

      $directory = $this->fileSystem->dirname($content_location);
      if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        throw new HttpException(500, "The destination directory does not exist, could not be created, or is not writable");
      }

      // Copy over the file content.
      $this->updateFile($file, $resource, $mimetype);
      $file->save();

      // Construct the Media.
      $media_struct = [
        'bundle' => $bundle,
        'uid' => $this->account->id(),
        'name' => $file->getFilename(),
        'langcode' => $this->languageManager->getDefaultLanguage()->getId(),
        "$source_field" => [
          'target_id' => $file->id(),
        ],
        IslandoraUtils::MEDIA_OF_FIELD => [
          'target_id' => $node->id(),
        ],
        IslandoraUtils::MEDIA_USAGE_FIELD => [
          'target_id' => $taxonomy_term->id(),
        ],
      ];

      // Set alt text.
      if ($source_field_config->getSetting('alt_field') && $source_field_config->getSetting('alt_field_required')) {
        $media_struct[$source_field]['alt'] = $file->getFilename();
      }

      $media = $this->entityTypeManager->getStorage('media')->create($media_struct);
      $media->save();
      return $media;
    }
  }

}
