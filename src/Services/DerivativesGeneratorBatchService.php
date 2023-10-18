<?php

namespace Drupal\islandora_drush_utils\Services;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Class DerivativesGeneratorBatchService implores generation of derivatives.
 */
class DerivativesGeneratorBatchService {

  use StringTranslationTrait;

  /**
   * Batch process callback.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   An array of nodes to process.
   * @param string $media_use_uri
   *   The media use URI to use for processing.
   * @param object $context
   *   Context for operations.
   */
  public static function generateDerivativesOperation(array $nodes, $media_use_uri, &$context) {
    $sandbox = &$context['sandbox'];
    $limit = 10;

    if (!isset($sandbox['total'])) {
      if (empty($nodes)) {
        $context['message'] = t('Found no nodes to process');
        $context['results']['error'] = TRUE;
        $context['finished'] = 1;
      }
      $sandbox['offset'] = 0;
      $sandbox['total'] = count($nodes);
      $context['results']['count'] = 0;
      $context['results']['error'] = FALSE;
    }

    $sub_nodes = array_slice($nodes, $sandbox['offset'], $limit);
    $entities = \Drupal::service('entity_type.manager')
      ->getStorage('node')
      ->loadMultiple($sub_nodes);

    $end = $sandbox['total'] < $limit ? $sandbox['total'] : $sandbox['offset'] + $limit;
    $context['message'] = t(
      'Processing @start to @end of @total', [
        '@start' => $sandbox['offset'],
        '@end' => $end,
        '@total' => $sandbox['total'],
      ]
    );

    try {
      $actions = \Drupal::service('entity_type.manager')
        ->getStorage('action')
        ->getQuery()
        ->condition('configuration.derivative_term_uri', $media_use_uri)
        ->execute();

      $actions = \Drupal::service('entity_type.manager')
        ->getStorage('action')
        ->loadMultiple($actions);

      foreach ($entities as $entity) {
        $performed_action = FALSE;
        foreach ($actions as $action) {
          $action_id = $action->id();
          $node_model = $entity->get('field_model')->getValue();
          $node_model = \Drupal::service('entity_type.manager')
            ->getStorage('taxonomy_term')
            ->load($node_model[0]['target_id'])
            ->label();
          $node_model = str_replace(' ', '_', $node_model);

          if (stripos($action_id, $node_model) !== FALSE) {
            $context['message'] = t(
              'Performing @action on "@id"', [
                '@action' => $action->id(),
                '@id' => $entity->id(),
              ]
            );
            try {
              $action->execute([$entity]);
              $context['results']['nodes succeeded'][] = $entity->id();
              $performed_action = TRUE;
            }
            catch (\Exception $e) {
              $context['results']['error'] = TRUE;
              $context['results']['nodes failed'][] = $entity->id();
              $context['message'] = t(
                '@action on node "@id" failed', [
                  '@action' => $action->id(),
                  '@id' => $entity->id(),
                ]
              );
            }
          }
        }
        $context['results']['count']++;
        if (!$performed_action) {
          $context['message'] = t(
            'Unable to determine an action for node "@id"', [
              '@id' => $entity->id(),
            ]
          );
        }
      }
    }
    catch (\Exception $e) {
      $context['results']['error'] = TRUE;
      $context['message'] = t(
        'Encountered an exception: @exception', [
          '@exception' => $e,
        ]
      );
    }
    $sandbox['offset'] = $sandbox['offset'] + $limit;
    $context['finished'] = $sandbox['offset'] / $sandbox['total'];
  }

  /**
   * Batch Finished callback.
   *
   * @param bool $success
   *   Success of the operation.
   * @param array $results
   *   Array of results for post-processing.
   * @param array $operations
   *   Array of operations.
   */
  public static function generateDerivativesOperationFinished($success, array $results, array $operations) {
    if (!$success || $results['error']) {
      $error_operation = reset($operations);
      \Drupal::messenger()
        ->addMessage(
          t(
            'An error occurred while processing @operation with arguments : @args', [
              '@operation' => $error_operation[0],
              '@args' => print_r($error_operation[0], TRUE),
            ]
          )
        );
    }
    if (!empty($results['nodes failed'])) {
      \Drupal::messenger()->addMessage(
        t(
          'The following nodes produced errors: \n @nodes', [
            '@nodes' => implode("\n", $results['nodes failed']),
          ]
        )
      );
    }
    else {
      \Drupal::messenger()->addMessage(
        t(
          '@count results processed.', [
            '@count' => $results['count'],
          ]
        )
      );
    }
  }

}
