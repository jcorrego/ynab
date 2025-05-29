<?php

namespace Drupal\renesas_algolia\Drush\Commands;

use Algolia\AlgoliaSearch\SearchClient;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\idt_health\RenesasLoggerInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\renesas_algolia\AlgoliaSearchableBase;
use Drupal\renesas_algolia\AlgoliaSearchableInterface;
use Drupal\renesas_algolia\PartialReindex;
use Drupal\renesas_algolia\RenesasAlgoliaManager;
use Drupal\renesas_algolia\SplitStrategy\DocumentSplitStrategy;
use Drupal\renesas_utilities\RenesasEnvironmentManagerInterface;
use Drush\Commands\DrushCommands as CommandsDrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Commands for main index.
 */
class DrushCommands extends CommandsDrushCommands {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * Batch size for processing.
   */
  const BATCH_SIZE = 50;

  /**
   * Table separator.
   */
  const TABLE_SEPARATOR = '--------------------';

  /**
   * File name of config for algolia indexes.
   */
  const FILE_ALGOLIA_INDEXES_CONFIG = 'indexes.algolia.yml';

  /**
   * Maximum number of indexed parts for a single node.
   */
  const MAX_SPLIT_CHUNKS = 6;

  /**
   * Path to renesas_algolia module.
   *
   * @var string
   */
  private string $modulePath;

  /**
   * Name of algolia indexes config file.
   *
   * @var string
   */
  private string $configFile;

  /**
   * Name of algolia indexes config base path.
   *
   * @var string
   */
  private string $configPath;

  /**
   * Algolia keys.
   *
   * @var array
   */
  private array $keys;

  /**
   * Algolia search client.
   *
   * @var \Algolia\AlgoliaSearch\SearchClient
   */
  private SearchClient $client;

  /**
   * Algolia commands constructor.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   Language manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module Handler.
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   Key Repository.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Drupal\Core\Cache\MemoryCache\MemoryCacheInterface $memoryCache
   *   Memory Static Cache.
   * @param \Drupal\renesas_utilities\RenesasEnvironmentManagerInterface $environmentManager
   *   Renesas environment manager instance.
   * @param \Drupal\idt_health\RenesasLoggerInterface $renesasLogger
   *   Renesas logger.
   * @param \Drupal\renesas_algolia\PartialReindex $partialReindex
   *   Partial reindex service.
   */
  public function __construct(
    protected LanguageManagerInterface $languageManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MessengerInterface $messenger,
    protected ModuleHandlerInterface $moduleHandler,
    protected KeyRepositoryInterface $keyRepository,
    protected ConfigFactoryInterface $configFactory,
    protected MemoryCacheInterface $memoryCache,
    protected RenesasEnvironmentManagerInterface $environmentManager,
    protected RenesasLoggerInterface $renesasLogger,
    protected PartialReindex $partialReindex,
    protected RenesasAlgoliaManager $algoliaManager,
  ) {
    parent::__construct();

    $this->modulePath = $this->moduleHandler->getModule('renesas_algolia')->getPath();
    $this->configPath = $this->modulePath . '/config/';
    $this->configFile = $this->configPath . self::FILE_ALGOLIA_INDEXES_CONFIG;
    $this->keys = $this->keyRepository->getKeys(['algolia_app_id', 'algolia_search_api_key']);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('language_manager'),
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('module_handler'),
      $container->get('key.repository'),
      $container->get('config.factory'),
      $container->get('entity.memory_cache'),
      $container->get('renesas_utilities.environment_manager'),
      $container->get('idt_health.renesas.logger'),
      $container->get('renesas_algolia.partial_reindex'),
      $container->get('renesas_algolia.manager'),
    );
  }

  /**
   * Deletes entries by node type.
   *
   * @command renesas_algolia:delete-type
   *
   * @usage drush renesas_algolia:delete-type --types=keyword,generic_product --index=main
   * @aliases al-dt
   */
  public function deleteType(
    $options = [
      'types' => '',
      'index' => 'main',
    ],
  ): void {
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($options['index'] ?? 'main');
    /** @var \Drupal\search_api_algolia\Plugin\search_api\backend\SearchApiAlgoliaBackend $backend */
    $backend = $index->getServerInstance()->getBackend();
    $backend->viewSettings();
    $algolia_client = $backend->getAlgolia();

    $types = array_filter(explode(',', $options['types']));
    foreach (['en', 'ja', 'zh-hans'] as $langcode) {
      $algolia_index = $algolia_client->initIndex($index->getOption('algolia_index_name') . '_' . $langcode);
      foreach ($types as $type) {
        $type = trim($type);
        $records = $algolia_index->browseObjects(
          ['query' => '', 'attributesToRetrieve' => ['objectID'], 'filters' => "bundle:$type"],
        );
        $records_to_delete = [];
        foreach ($records as $record) {
          $records_to_delete[] = $record['objectID'];
        }
        $this->logger()->notice('Deleting ' . count($records_to_delete) . ' records of type ' . $type . ' and language ' . $langcode);
        $chunk = 0;
        foreach (array_chunk($records_to_delete, self::BATCH_SIZE) as $records_to_delete_chunk) {
          $algolia_index->deleteObjects($records_to_delete_chunk);
          $this->logger()->notice('Deleted ' . ((self::BATCH_SIZE * $chunk++) + count($records_to_delete_chunk)) . '/' . count($records_to_delete) . ' records');
        }
      }
    }
  }

  /**
   * Index a node in main index.
   *
   * @command renesas_algolia:index-node
   *
   * @option nids
   *  A comma-separate list of node ids.
   *
   * @usage drush renesas_algolia:index-node --nids=1,2,999999 --index=main
   * @aliases al-in
   */
  public function indexNode(
    $options = [
      'nids' => '',
      'index' => 'main',
    ],
  ): void {
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($options['index'] ?? 'main');
    $nids = array_filter(explode(',', $options['nids']));
    $this->output->writeln('<info>Processing ' . count($nids) . ' items</info>');

    $this->algoliaManager->indexNodes($nids, $index);
  }

  /**
   * Index a node type in main index.
   *
   * @command renesas_algolia:index-type
   *
   * @option types
   *  A comma-separate list of types.
   *
   * @usage drush renesas_algolia:index-type --types=keyword,generic_product --limit=1000 --offset=0 --index=main
   * @aliases al-it
   */
  public function indexType(
    $options = [
      'types' => '',
      'limit' => 0,
      'offset' => 0,
      'index' => 'main',
      'batch' => 50,
    ],
  ): void {
    $types = array_filter(explode(',', $options['types']));
    $batch_size = $options['batch'] ?? self::BATCH_SIZE;
    $batch = (new BatchBuilder())
      ->setInitMessage('Starting Algolia indexing...')
      ->setTitle('Algolia indexing');

    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $query->accessCheck(FALSE);
    $query->condition('type', $types, 'IN');
    $query->sort('nid', 'DESC');
    if ($options['limit'] > 0) {
      $query->range($options['offset'], $options['limit']);
    }
    $nids = $query->execute();
    foreach (array_chunk($nids, $batch_size) as $nids_chunk) {
      $batch->addOperation([$this, 'indexTypeBatchProcess'], [$nids_chunk, $options, count($nids)]);
    }

    $batch->setFinishCallback([$this, 'indexTypeBatchFinished']);
    batch_set($batch->toArray());
    drush_backend_batch_process();
  }

  /**
   * Mass index batch process callback.
   *
   * @param array $nids
   *   Array of nids to be indexed.
   * @param array $options
   *   Options.
   * @param int $total
   *   Total number of items to be indexed.
   * @param mixed $context
   *   Batch context.
   *
   * @return void
   *   Nothing.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function indexTypeBatchProcess(array $nids, array $options, int $total, mixed &$context): void {
    $start_time = microtime(TRUE);
    $index = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->load($options['index'] ?? 'main');
    $backend = $index->getServerInstance()->getBackend();
    $datasource = $index->getDatasource('entity:node');

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $items = [];
    foreach ($nodes as $node) {
      foreach (array_keys($this->languageManager->getLanguages()) as $langcode) {
        if (!$node instanceof AlgoliaSearchableInterface || !$node->hasTranslation($langcode)) {
          continue;
        }

        $node = $node->getTranslation($langcode);
        $object_id = 'entity:node/' . $node->id() . ':' . $langcode;
        $data = $node->getTypedData();
        $items[$object_id] = $backend->getFieldsHelper()->createItemFromObject($index, $data, $object_id, $datasource);
      }

      $context['results'][] = $node->id();
    }

    // Once operations are done node cache storage should be reset, any other
    // way the static cache will continue growing up, being idle as we load more
    // nodes. This will help to avoid problem types like Memory Allocation
    // Errors.
    // Notice: Besides the entities load here some logic methods or access
    // methods like $node->field_name->entity does also load entities.
    $this->memoryCache->deleteAll();

    if ($items) {
      try {
        $keys_to_delete = [];
        foreach ($items as $object_id => $item) {
          $keys_to_delete[] = AlgoliaSearchableBase::formatObjectId($item->getOriginalObject()->getEntity()->id());
          $keys_to_delete[] = AlgoliaSearchableBase::formatObjectId($item->getOriginalObject()->getEntity()->id(), DocumentSplitStrategy::PDF_SPLIT_KEY);
          for ($i = 1; $i < self::MAX_SPLIT_CHUNKS; $i++) {
            $keys_to_delete[] = AlgoliaSearchableBase::formatObjectId($item->getOriginalObject()->getEntity()->id(), $i);
          }

          // This is the fallback routine for correct data cleanup from Algolia
          // until everything is re-indexed and new objectID format is in place.
          // @todo Remove it after content is re-indexed.
          $keys_to_delete[] = $object_id;
          $keys_to_delete[] = "$object_id:" . DocumentSplitStrategy::PDF_SPLIT_KEY;
          for ($i = 1; $i < self::MAX_SPLIT_CHUNKS; $i++) {
            $keys_to_delete[] = "$object_id:" . $i;
          }
        }

        $backend->deleteItems($index, $keys_to_delete);
        $backend->indexItems($index, $items);
      }
      catch (\Exception $e) {
        $this->messenger->addError($this->t('Error indexing to Algolia.'));
        $this->logger()->error('Error indexing to Algolia: ' . $e->getMessage());
      }
    }
    $types = array_filter(explode(',', $options['types']));
    if (count($types) == 1) {
      $type_label = $types[0];
    }
    else {
      $type_label = '';
    }
    $execution_time = round(microtime(TRUE) - $start_time, 2);
    $context['message'] = $this->t('Processed @count / @total @type items in @time seconds.', [
      '@count' => count($context['results']),
      '@total' => $total,
      '@type' => $type_label,
      '@time' => $execution_time,
    ]);
  }

  /**
   * Mass index batch finished callback.
   *
   * @param bool $success
   *   If operation was successful.
   * @param array $results
   *   Results from index array.
   *
   * @return void
   *   Nothing.
   */
  public function indexTypeBatchFinished(bool $success, array $results): void {
    if ($success) {
      $this->messenger->addStatus($this->t('@count results processed.', ['@count' => count($results)]));
    }
    else {
      $this->messenger->addError($this->t('Error indexing to Algolia.'));
    }
  }

  /**
   * Export available indexes for the given app_id and api_key.
   *
   * @command renesas_algolia:index-export
   *
   * @option app_id
   *  Algolia application id.
   * @option api_key
   *  Algolia API key.
   *
   * @usage drush renesas_algolia:index-export --app_id=YOUR_APP_ID --api_key=YOUR_API_KEY --
   * @aliases al-ie
   */
  public function indexExport($options = ['app_id' => '', 'api_key' => '']): void {
    $indexes = [];

    $client = SearchClient::create(
      $options['app_id'],
      $options['api_key'],
    );
    if (empty($options['app_id']) || empty($options['api_key'])) {
      $this->io()->error('App ID and API key are required.');
      return;
    }

    $indexes = $client->listIndices();
    if (empty($indexes['items'])) {
      $this->io()->error('No indexes found.');
      return;
    }
    foreach ($indexes['items'] as $search_index) {

      if (strpos($search_index['name'], '.tmp') !== FALSE) {
        continue;
      }
      try {
        $index = $client->initIndex($search_index['name']);

        // Export settings.
        $settings = $index->getSettings();
        // Unset non used settings in current exported versions.
        unset($settings['version']);
        unset($settings['replicas']);

        // Final export.
        $export = $settings;
        ksort($export);

        // Save json file.
        $json = json_encode($export, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $json = str_replace('    ', '  ', $json);
        $file_name = $search_index['name'] . '-' . $options['app_id'] . '.json';
        $file_path = "{$this->configPath}{$file_name}";
        file_put_contents($file_path, $json);

        // Report exported index.
        $mask = "%-64.64s %-64.64s";
        $report = sprintf($mask, "exported: " . $search_index['name'], "file: {$file_name}");
        $this->logger()->success(dt($report));
      }
      catch (\Exception $exception) {
        $this->logger()->error(dt("Algolia request for index " . $search_index['name'] . " failed, error: {$exception->getMessage()}."));
      }
    }
  }

  /**
   * Send a partial update to algolia index records.
   *
   * @command renesas_algolia:partial-update
   *
   * @usage drush renesas_algolia:partial-update --types=keyword,generic_product --index=main --fields=title
   * @aliases al-pu
   */
  public function partialUpdate(
    $options = [
      'types' => NULL,
      'index' => 'main',
      'fields' => NULL,
    ],
  ): void {
    $bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo('node');

    if (empty($options['types'])) {
      // Show to user the allowed list of bundles for partial indexing.
      $question = 'Select the content types (type comma separated values)';
      // If a bundle has a class that inherits from AlgoliaSearchableBase,
      // add it.
      $choices = array_map(
        fn($item) => $item['label'],
        array_filter(
          $bundle_info,
          fn($item) => !empty($item['class']) && is_subclass_of($item['class'], AlgoliaSearchableBase::class)
        )
      );
      $options['types'] = $this->io()->choice(
        question: $question,
        choices: $choices,
        multiSelect: TRUE
      );
    }
    else {
      // Use the types from command used.
      $options['types'] = array_filter(explode(',', $options['types']));
      $options['types'] = array_keys(array_filter(
        $bundle_info,
        fn($item, $key) => !empty($item['class'])
          && is_subclass_of($item['class'], AlgoliaSearchableBase::class)
          && in_array($key, $options['types']),
        ARRAY_FILTER_USE_BOTH
      ));

      if (empty($options['types'])) {
        $this->io()->error('There are no valid entity types in the list.');
        return;
      }
    }

    // Get the list of allowed partial indexable fields.
    $partial_indexable_fields = [];
    foreach ($options['types'] as $type) {
      if (empty($bundle_info[$type])) {
        continue;
      }
      $entity_class = $bundle_info[$type]['class'];
      $partial_indexable_fields = [
        ...$partial_indexable_fields,
        ...$entity_class::getPartialIndexableFields(),
      ];
    }
    $partial_indexable_fields = array_unique($partial_indexable_fields);

    if (empty($options['fields'])) {
      // Show to user the allowed list of fields from the selected bundles.
      $question = 'Select the fields to partial index';
      $options['fields'] = $this->io()->choice(
        question: $question,
        choices: array_combine($partial_indexable_fields, $partial_indexable_fields),
        multiSelect: TRUE
      );
    }
    else {
      $options['fields'] = array_filter(explode(',', $options['fields']));
      $options['fields'] = array_intersect($options['fields'], $partial_indexable_fields);

      if (empty($options['fields'])) {
        $this->io()->error('There are no valid fields for the selected entity types.');
        return;
      }
    }

    $batch = (new BatchBuilder())
      ->setInitMessage('Starting Algolia Partial indexing...')
      ->setTitle('Algolia partial indexing');

    foreach ($options['types'] as $type) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery();
      $query->accessCheck(FALSE);
      $query->condition('type', $type);
      $query->sort('nid', 'DESC');
      $nids = $query->execute();

      foreach (array_chunk($nids, self::BATCH_SIZE) as $nids) {
        $batch->addOperation([$this, 'partialUpdateBatchProcess'], [$nids, $options]);
      }
    }
    $batch->setFinishCallback([$this, 'indexTypeBatchFinished']);
    batch_set($batch->toArray());
    drush_backend_batch_process();
  }

  /**
   * Mass index batch partial updates process callback.
   *
   * @param array $nids
   *   Array of nids to be indexed.
   * @param array $options
   *   Options.
   * @param mixed $context
   *   Batch context.
   *
   * @return void
   *   Nothing.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function partialUpdateBatchProcess(array $nids, array $options, mixed &$context): void {
    $start_time = microtime(TRUE);
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($options['index'] ?? 'main');
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    $failed_items = [];
    foreach ($this->languageManager->getLanguages() as $language) {
      $langcode = $language->getId();
      $items = [];
      foreach ($nodes as $node) {
        if (!$node instanceof AlgoliaSearchableBase) {
          continue;
        }

        if (!$node->hasTranslation($langcode)) {
          $langcode = $node->language()->getId();
        }

        $node = $node->getFallbackTranslation($langcode);
        $values = [];
        foreach ($options['fields'] as $field) {
          // Check if getSearchableCamelCase method of the field exists.
          $method_name = 'getSearchable' . str_replace('_', '', ucwords($field, '_'));
          if (!method_exists($node, $method_name)) {
            continue;
          }
          $context['results'][] = $node->id();
          $values = [
            ...$values,
            ...call_user_func([$node, $method_name], $langcode),
          ];
        }

        $items = array_merge($items, $this->partialReindex->preparePartialDataSplits($node, $values));
      }

      if (!$this->partialReindex->reindexItemsPartial($items, $langcode, retry_indexing: TRUE, failed_items: $failed_items)) {
        $this->io()->error('Partial index failed, please see the logs for more context');
      }
    }

    // Trigger a full reindex for failed nodes. This is to ensure split strategy
    // is used to calculate new splits. Note that mixing split strategy and
    // partial index is not possible, as partial index won't be aware of records
    // that change in size, adds or remove splits in the index.
    if (!empty($failed_items)) {
      $this->algoliaManager->indexNodes($failed_items, $index);
    }

    // Once operations are done node cache storage should be reset, any other
    // way the static cache will continue growing up, being idle as we load more
    // nodes. This will help to avoid problem types like Memory Allocation
    // Errors.
    // Notice: Besides the entities load here some logic methods or access
    // methods like $node->field_name->entity does also load entities.
    $this->memoryCache->deleteAll();

    $execution_time = round(microtime(TRUE) - $start_time, 2);
    $context['message'] = $this->t('Processed @count nodes (@count2 with translations) in @time seconds.', [
      '@count' => count(array_unique($context['results'])),
      '@count2' => count($context['results']),
      '@time' => $execution_time,
    ]);
  }

  /**
   * Copy data from one index to another.
   *
   * @command renesas_algolia:merge-index
   *
   * @usage drush renesas_algolia:merge-index
   * @aliases al-mi
   */
  public function mergeIndex(): void {
    // Get the current environment.
    $env = getenv('AH_SITE_ENVIRONMENT') === 'prod' || getenv('LAGOON_ENVIRONMENT_TYPE') === 'production' ? 'prod' : 'dev';
    $this->io()->writeln('Current environment: ' . $env);
    $keys = $this->keyRepository->getKeys([
      'algolia_app_id',
      'algolia_search_api_key',
      'algolia_write_api_key',
    ]);

    $prod_client = SearchClient::create(
      "KJ220GAQ35",
      "4bd491f99bc33491f1e343b8e0734d8f",
    );
    $local_client = SearchClient::create(
      $keys['algolia_app_id']->getKeyValue(),
      $keys['algolia_write_api_key']->getKeyValue(),
    );
    $langs = array_keys($this->languageManager->getLanguages());
    foreach ($langs as $lang) {
      $target_index = $local_client->initIndex($env . '_main_' . $lang);
      $crawler_indexes = [
        // 'community' => [
        //   'index' => 'crawler_community_forums_consolidated_' . $lang,
        //   'label' => $this->t('Support Forums', [], ['langcode' => $lang]),
        // ],
        // 'faq' => [
        //   'index' => 'crawler_faq_' . $lang,
        //   'label' => $this->t('FAQ', [], ['langcode' => $lang]),
        // ],
        'tool' => [
          'index' => 'crawler_toolsupport_' . $lang,
          'label' => $this->t('CS+ Support', [], ['langcode' => $lang]),
        ],
      ];
      foreach ($crawler_indexes as $bundle => $data) {
        $this->io()->writeln($data['label'] . ' Deleting existing records.');
        $records = $target_index->browseObjects(
          ['query' => '', 'attributesToRetrieve' => ['objectID'], 'filters' => "bundle:$bundle"],
        );
        $records_to_delete = [];
        foreach ($records as $record) {
          $records_to_delete[] = $record['objectID'];
        }
        $this->logger()->notice('Deleting ' . count($records_to_delete) . ' records of type ' . $bundle);
        $chunk = 0;
        foreach (array_chunk($records_to_delete, self::BATCH_SIZE) as $records_to_delete_chunk) {
          $target_index->deleteObjects($records_to_delete_chunk);
          $this->logger()->notice('Deleted ' . ((self::BATCH_SIZE * $chunk++) + count($records_to_delete_chunk)) . '/' . count($records_to_delete) . ' records');
        }
        $source_index = $prod_client->initIndex($data['index']);
        $this->io()->writeln('Reading ' . $data['label'] . ' records... from ' . $data['index']);
        $original_records = $source_index->browseObjects();
        $modified_records = [];
        $existing_keys = [];
        $this->io()->writeln('Processing fields...');
        foreach ($original_records as $hit) {
          // Skip records with empty tile, body or url.
          if (empty($hit['title']) || empty($hit['url'])) {
            $this->io()->writeln($data['label'] . ' skipping empty record: "' . ($hit['url'] ?? $hit['objectID']) . '"');
            continue;
          }

          if ($bundle == 'tool') {
            if (!empty($hit['breadcrumb'])) {
              $hit['breadcrumb'] = $this->processToolBreacrumb($hit);
            }
            // Skip if the title and subtitle (version) already exists.
            $key = $hit['title'] . $hit['subtitle'];
            if (in_array($key, $existing_keys)) {
              $this->io()->writeln($data['label'] . ' skipping duplicate record: "' . $hit['url'] . '"');
              continue;
            }
            $existing_keys[] = $key;
          }
          elseif ($bundle == 'faq') {
            if (isset($hit['relatedProducts'])) {
              $hit['extra'] = implode(' ', $hit['relatedProducts']);
            }
          }
          elseif ($bundle == 'community') {
            $hit['breadcrumb'] = $this->processCommunityBreacrumb($hit);
            if (!empty($hit['replies'])) {
              $hit['extra'] = $hit['replies'];
            }
          }
          // Add default attributes.
          $hit['dEyebrow'] = $data['label'];
          $hit['bundleScore'] = 0;
          $hit['bundle'] = $bundle;
          // Update pills to translate values.
          $hit['fPill'] = [$data['label']];

          // Remove unused fields.
          unset(
            $hit['post'],
            $hit['question'],
            $hit['answer'],
            $hit['relatedProducts'],
            $hit['latestUpdate'],
            $hit['images'],
            $hit['source'],
            $hit['container'],
            $hit['application'],
            $hit['replies'],
            $hit['bestAnswer'],
          );

          $modified_records[] = $hit;
        }
        $this->io()->writeln('Copying ' . count($modified_records) . ' ' . $data['label'] . ' records to : ' . $env . '_main_' . $lang);
        $chunk = 0;
        foreach (array_chunk($modified_records, self::BATCH_SIZE) as $modified_records_chunk) {
          $target_index->saveObjects($modified_records_chunk);
          $this->logger()->notice('Copied ' . ((self::BATCH_SIZE * $chunk++) + count($modified_records_chunk)) . '/' . count($modified_records) . 'records');
        }
      }
    }
  }

  /**
   * Update Algolia rules with the new objectID format.
   *
   * @command renesas_algolia:update-rules-object-id
   *
   * @usage drush renesas_algolia:update-rules-object-id
   * @aliases al-ur-id
   */
  public function updateRules(): void {
    $keys = $this->keyRepository->getKeys([
      'algolia_app_id',
      'algolia_write_api_key',
    ]);
    $client = SearchClient::create(
      $keys['algolia_app_id']->getKeyValue(),
      $keys['algolia_write_api_key']->getKeyValue(),
    );

    try {
      $index_configs = $client->listIndices()['items'];
      foreach ($index_configs as $index_config) {
        $index = $client->initIndex($index_config['name']);
        $rules = $index->browseRules();
        $new_rules = [];
        foreach ($rules as $rule) {
          $rule_json = json_encode($rule);
          $new_rule_json = preg_replace('~"entity:node\\\/(\d+):(en|ja|zh-hans)"~mu', '"${1}"', $rule_json);
          $new_rules[] = json_decode($new_rule_json, TRUE);
        }
        $index = $client->initIndex($index_config['name']);
        $index->saveRules($new_rules);
      }
    }
    catch (\Exception $exception) {
      $this->logger()->error('Could not update rules. Error: "' . $exception->getMessage() . '"');
    }
  }

  /**
   * Get diff between Algolia index and local data.
   *
   * @command renesas_algolia:index-diff
   *
   * @option types
   *   A comma-separate list of node bundles.
   *
   * @usage drush renesas_algolia:index-diff --types=generic_product --index=dev_main --langcode=en
   *
   * @aliases al-id
   */
  public function indexDiff(
    $options = [
      'types' => '',
      'index' => '',
      'langcode' => '',
    ],
  ): void {
    if (empty($options['types'])) {
      $this->writeln('Please provide a list of content types, separated by comma.');
      return;
    }
    if (empty($options['index'])) {
      $this->writeln('Please provide valid Algolia index name, for example - dev_main, prod_main, etc.');
      return;
    }
    if (empty($options['langcode'])) {
      $this->writeln('Please provide one of the langcodes (en, ja, zh-hans).');
      return;
    }

    $types = array_map('trim', explode(',', $options['types']));

    $this->writeln('Retrieving data from Algolia...');
    $algolia_nodes = [];
    foreach ($types as $type) {
      $this->writeln('Retrieving ' . $type);
      $algolia_nodes += $this->getAlgoliaNodesForDiff($options['index'], $options['langcode'], $type);
    }

    $this->writeln('Retrieving data from Drupal...');
    $drupal_nodes = [];
    foreach ($types as $type) {
      $this->writeln('Retrieving ' . $type);
      $drupal_nodes += $this->getDrupalNodesForDiff($type);
    }

    $this->writeln('Comparing data...');
    $not_in_algolia = array_diff(array_keys($drupal_nodes), array_keys($algolia_nodes));
    $not_in_drupal = array_diff(array_keys($algolia_nodes), array_keys($drupal_nodes));

    $this->writeln('');
    $this->writeln('Nodes not indexed in Algolia:');
    $this->writeln(self::TABLE_SEPARATOR);
    foreach ($not_in_algolia as $item) {
      $parts = [
        $item,
        $drupal_nodes[$item]['type'],
        $drupal_nodes[$item]['title'],
      ];
      $this->writeln(implode('|', $parts));
    }
    $this->writeln(self::TABLE_SEPARATOR);
    $this->writeln('Total ' . count($not_in_algolia) . ' items.');

    $this->writeln('');
    $this->writeln('Nodes indexed in Algolia, but not present/not searchable in Drupal:');
    $this->writeln(self::TABLE_SEPARATOR);
    foreach ($not_in_drupal as $item) {
      $parts = [
        $item,
        $algolia_nodes[$item]['type'],
        $algolia_nodes[$item]['title'],
      ];
      $this->writeln(implode('|', $parts));
    }
    $this->writeln(self::TABLE_SEPARATOR);
    $this->writeln('Total ' . count($not_in_drupal) . ' items.');
  }

  /**
   * Retrieve Algolia items by type.
   *
   * @param string $index
   *   Index name (without langcode suffix).
   * @param string $langcode
   *   Langcode.
   * @param string $type
   *   Content type.
   *
   * @return array
   *   Array of nodes.
   */
  private function getAlgoliaNodesForDiff(string $index, string $langcode, string $type): array {
    $keys = $this->keyRepository->getKeys([
      'algolia_app_id',
      'algolia_write_api_key',
    ]);

    $algolia_client = SearchClient::create(
      $keys['algolia_app_id']->getKeyValue(),
      $keys['algolia_write_api_key']->getKeyValue(),
    );

    $algolia_index = $algolia_client->initIndex($index . '_' . $langcode);
    $algolia_records = $algolia_index->browseObjects([
      'query' => '',
      'filters' => "bundle:$type",
      'attributesToRetrieve' => ['objectID', 'nid', 'title'],
    ]);

    $algolia_nodes = [];
    foreach (iterator_to_array($algolia_records) as $record) {
      $algolia_nodes[$record['nid']] = [
        'title' => $record['title'],
        'type' => $type,
      ];
    }

    return $algolia_nodes;
  }

  /**
   * Get Drupal nodes by type.
   *
   * @param string $type
   *   Content type.
   *
   * @return array
   *   Array of nodes.
   */
  private function getDrupalNodesForDiff(string $type): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $query->condition('type', $type);
    $query->accessCheck(FALSE);
    $drupal_nids = $query->execute();
    $drupal_nids_chunks = array_chunk($drupal_nids, self::BATCH_SIZE);
    $drupal_nodes = [];
    foreach ($drupal_nids_chunks as $drupal_nids_chunk) {
      $drupal_nodes_chunk = $this->entityTypeManager->getStorage('node')->loadMultiple($drupal_nids_chunk);
      foreach ($drupal_nodes_chunk as $node) {
        if (!($node instanceof AlgoliaSearchableInterface) || !$node->isSearchable()) {
          continue;
        }
        $drupal_nodes[$node->id()] = [
          'title' => $node->getTitle(),
          'type' => $type,
        ];
      }
    }
    return $drupal_nodes;
  }

  /**
   * Transform CS+ breadcrumb string to array.
   */
  private function processToolBreacrumb(mixed $hit): array {
    $base_url = $hit['url'];
    $base_url = substr($base_url, 0, strrpos($base_url, '/'));
    $pattern = '/<a\s+href="([^"]+)">([^<]+)<\/a>/i';
    preg_match_all($pattern, $hit['breadcrumb'], $matches, PREG_SET_ORDER);
    $result = [];
    foreach ($matches as $match) {
      $href = $base_url . '/' . $match[1];
      $text = $match[2];
      $result[] = ['url' => $href, 'label' => $text];
    }
    return $result;
  }

  /**
   * Creates Community breadcrumb.
   */
  private function processCommunityBreacrumb(mixed $hit): array {
    $result = [];
    if (isset($hit['container'])) {
      $result[] = ['url' => '#', 'label' => $hit['container']];
    }
    if (isset($hit['application'])) {
      $result[] = ['url' => '#', 'label' => $hit['application']];
    }
    return $result;
  }
  
  /**
   * Process some data.
   *
   * @command renesas_algolia:test-data
   *
   * @usage drush renesas_algolia:test-data
   * @aliases al-td
   */
  public function testData(): void {
    // Get the current environment.
    $dev_client = SearchClient::create(
      "testing20MRCI4146",
      "9393e1aa3e02ddef14119a2441d6cd45",
    );
    $test_index = $dev_client->initIndex('fm_dt_1931_poc');
    $uat_index = $dev_client->initIndex('fm_product_en_uat');
    $uat_records = $uat_index->browseObjects();
    $removeKeys = [
      'weightUOM',
      'weight',
      "createdDate",
      "currencyCode",
      "defaultColor",
      "eCCN",
      "facets",
      "fccCodes",
      "fixedPrice",
      "fixedWeight",
      "hasCategory",
      "hasNCNR",
      "hierarchicalCategories",
      "inParametricSearch",
      "inventory",
      "isBaseCustomCableAssembly",
      "isBlockedForSale",
      "isCable",
      "isCableAssembly",
      "isConnector",
      "isCustomLengthAllowed",
      "isDiscontinued",
      "isInPlp",
      "isMasterCA",
      "isNew",
      "isOversized",
      "isProp65",
      "isPublished",
      "isPublishedOnConfigurator",
      "isSearchable",
      "isSellable",
      "isVariant",
      "keySpecs",
      "lastUpdatedTime",
      "length",
      "lengthVariations",
      "maxFreqMhz",
      "pdpLength",
      "pdpLengthVariations",
      "priceBreakLimit",
      "pricingTiers",
      "reachStatus",
      "replacementSKU",
      "revenue",
      "roHSStatus",
      "roHSStatusCode",
      "surchargeCode",
      "tSCAStatus",
      "unitPrice",
      "uom",
      "variablePrice",
      "variableWeight",
      "webDesc",
      "Maximum Insertion Loss(dB)",
      "Phase",
      "RF Max Frequency(GHz)",
      "RF Min Frequency(GHz)",
      "Typical Insertion Loss(dB)",
      "backOrders",
      "backorders",
      "bestSellerRank",
      "color",
      "colorVariations",
      "colourVariations",
      "compatibility",
      "configuratorData",
      "assets",
      "Body Style",
      "Connection Type",
      "Connector 1 Body Material",
      "Connector 1 Body Plating",
      "Connector 1 Connection Method",
      "Connector 1 Impedance(Ohms)",
      "Connector 1 Mount Method",
      "Connector 1 Polarity",
      "Connector 2 Body Material",
      "Connector 2 Body Plating",
      "Connector 2 Connection Method",
      "Connector 2 Impedance(Ohms)",
      "Connector 2 Mount Method",
      "Connector 2 Polarity",
      "Design",
      "Hermetically Sealed",
      "IP Rating",
      "Isolated Ground",
      "Max Frequency(GHz)",
      "Min Frequency(GHz)",
      "Passive Intermodulation(dBc)",
      "canCategory",
      "categorySEOURL",
    ];
    $insert_records = [];
    foreach ($uat_records as $hit) {
      if (isset($hit['category']) ) {//&& in_array('RF Adapters', $hit['category'])) {
        if (!isset($hit['Connector 1 Gender']) || !isset($hit['Connector 1 Series'])
        || !isset($hit['Connector 2 Gender']) || !isset($hit['Connector 2 Series'])) {
          continue;
        }
        $gender1 = $hit['Connector 1 Gender'];
        $gender2 = $hit['Connector 2 Gender'];
        $series1 = $hit['Connector 1 Series'];
        $series2 = $hit['Connector 2 Series'];
        $hit['Connector Gender'] = [$gender1 . ' to ' . $gender2];
        $hit['Connector Series'] = [$series1 . ' to ' . $series2];
        if ($gender1 != $gender2) {
          $hit['Connector Gender'][] = $gender2 . ' to ' . $gender1;
        }
        if ($series1 != $series2) {
          $hit['Connector Series'][] = $series2 . ' to ' . $series1;
        }
        foreach ($hit['assets'] as $asset) {
          if (isset($asset['type']) && $asset['type'] == 'MediumImage') {
            $hit['image'] = 'https://www.fairviewmicrowave.com/content/dam/infinite-electronics/product-assets/fairview-microwave/images/' . $asset['name'];
          }
        }
        foreach ($removeKeys as $key) {
          unset($hit[$key]);
        }
        $this->io()->writeln($hit['objectID']);
        $insert_records[] = $hit;
        // break;
      }
    }
    $chunk = 0;
    foreach (array_chunk($insert_records, 500) as $insert_records_chunk) {
      // $test_index->saveObjects($insert_records_chunk);
      $this->logger()->notice('Copied ' . ((500 * $chunk++) + count($insert_records_chunk)) . '/' . count($insert_records) . 'records');
    }
  }

  /**
   * Duplicate records with inverse attributes.
   *
   * @command infinite:duplicate
   *
   * @usage drush infinite:duplicate
   * @aliases in-dup
   */
  public function infiniteDuplicate(): void {
    // Get the current environment.
    $dev_client = SearchClient::create(
      "testing20MRCI4146",
      "9393e1aa3e02ddef14119a2441d6cd45",
    );
    $test_index = $dev_client->initIndex('fm_dt_2170_poc');
    $uat_index = $dev_client->initIndex('fm_product_en_uat');
    $uat_records = $uat_index->browseObjects();
    $removeKeys = [
      'weightUOM',
      'weight',
      "createdDate",
      "currencyCode",
      "defaultColor",
      "eCCN",
      "facets",
      "fccCodes",
      "fixedPrice",
      "fixedWeight",
      "hasCategory",
      "hasNCNR",
      "hierarchicalCategories",
      "inParametricSearch",
      "inventory",
      "isBaseCustomCableAssembly",
      "isBlockedForSale",
      "isCable",
      "isCableAssembly",
      "isConnector",
      "isCustomLengthAllowed",
      "isDiscontinued",
      "isInPlp",
      "isMasterCA",
      "isNew",
      "isOversized",
      "isProp65",
      "isPublished",
      "isPublishedOnConfigurator",
      "isSearchable",
      "isSellable",
      "isVariant",
      "keySpecs",
      "lastUpdatedTime",
      "length",
      "lengthVariations",
      "maxFreqMhz",
      "pdpLength",
      "pdpLengthVariations",
      "priceBreakLimit",
      "pricingTiers",
      "reachStatus",
      "replacementSKU",
      "revenue",
      "roHSStatus",
      "roHSStatusCode",
      "surchargeCode",
      "tSCAStatus",
      "unitPrice",
      "uom",
      "variablePrice",
      "variableWeight",
      "webDesc",
      "Maximum Insertion Loss(dB)",
      "Phase",
      "RF Max Frequency(GHz)",
      "RF Min Frequency(GHz)",
      "Typical Insertion Loss(dB)",
      "backOrders",
      "backorders",
      "bestSellerRank",
      "color",
      "colorVariations",
      "colourVariations",
      "compatibility",
      "configuratorData",
      "assets",
      "Body Style",
      "Connection Type",
      "Design",
      "Hermetically Sealed",
      "IP Rating",
      "Isolated Ground",
      "Max Frequency(GHz)",
      "Min Frequency(GHz)",
      "Passive Intermodulation(dBc)",
      "canCategory",
      "categorySEOURL",
      "Detector Polarity",
      "Power Max Input(dBm)",
      "Video Capacitance(pF)",
      "Tangential Sensitivity",
      "Voltage Sensitivity(mV/mW)",
    ];
    $duplicate_keys = [
      "series" => [
        "Connector 1 Series",
        "Connector 2 Series",
      ],
      "gender" => [
        "Connector 1 Gender",
        "Connector 2 Gender",
      ],
      "mount" => [
        "Connector 1 Mount Method",
        "Connector 2 Mount Method",
      ],
      "polarity" => [
        "Connector 1 Polarity",
        "Connector 2 Polarity",
      ],
      "impedance" => [
        "Connector 1 Impedance(Ohms)",
        "Connector 2 Impedance(Ohms)",
      ],
      "connection" => [
        "Connector 1 Connection Method",
        "Connector 2 Connection Method",
      ],
      "body" => [
        "Connector 1 Body Material",
        "Connector 2 Body Material",
      ],
      "plating" => [
        "Connector 1 Body Plating",
        "Connector 2 Body Plating",
      ],
    ];

    $insert_records = [];
    foreach ($uat_records as $hit) {
      if (isset($hit['category']) && in_array('RF Adapters', $hit['category'])) {
        if (!isset($hit['Connector 1 Gender']) || !isset($hit['Connector 1 Series'])
        || !isset($hit['Connector 2 Gender']) || !isset($hit['Connector 2 Series'])
        || empty($hit['Connector 1 Gender']) || empty($hit['Connector 1 Series'])
        || empty($hit['Connector 2 Gender']) || empty($hit['Connector 2 Series'])
        ) {
          continue;
        }
        foreach ($hit['assets'] as $asset) {
          if (isset($asset['type']) && $asset['type'] == 'MediumImage') {
            $hit['image'] = 'https://www.fairviewmicrowave.com/content/dam/infinite-electronics/product-assets/fairview-microwave/images/' . $asset['name'];
          }
        }
        foreach ($removeKeys as $key) {
          unset($hit[$key]);
        }
        $this->io()->writeln($hit['objectID']);
        $insert_records[] = $hit;
        $hit2 = $hit;
        foreach ($duplicate_keys as $key => $keys) {
          $value1 = $hit[$keys[0]];
          $value2 = $hit[$keys[1]];
          $hit2[$keys[0]] = $value2;
          $hit2[$keys[1]] = $value1;
        }
        $hit2['objectID'] = $hit2['objectID'] . '-reverse';
        $this->io()->writeln($hit2['objectID']);
        $insert_records[] = $hit2;
        // break;
      }
    }
    $chunk = 0;
    foreach (array_chunk($insert_records, 500) as $insert_records_chunk) {
      $test_index->saveObjects($insert_records_chunk);
      $this->logger()->notice('Copied ' . ((500 * $chunk++) + count($insert_records_chunk)) . '/' . count($insert_records) . ' records');
    }
  }

  /**
   * Process some data.
   *
   * @command infinite:backup
   *
   * @usage drush infinite:backup
   * @aliases in-bk
   */
  public function infiniteBackup(): void {
    // Get the current environment.
    $prod_client = SearchClient::create(
      "O0PAXP3VI5",
      "0e919089989fd33ae63fd7c95ab174d8",
    );
    $prod_index = $prod_client->initIndex('fm_product_en_prod');
    $backup_index = $prod_client->initIndex('fm_product_en_prod_backup');
    $backup_records = $backup_index->browseObjects();
    $insert_records = [];
    foreach ($backup_records as $hit) {
      $insert_records[] = $hit;
    }
    $chunk = 0;
    foreach (array_chunk($insert_records, 500) as $insert_records_chunk) {
      $prod_index->saveObjects($insert_records_chunk);
      $this->logger()->notice('Copied ' . ((500 * $chunk++) + count($insert_records_chunk)) . '/' . count($insert_records) . ' records');
    }
  }
  
  /**
   * Make interface attr multi value.
   *
   * @command infinite:multi-value
   *
   * @usage drush infinite:multi-value
   * @aliases in-mv
   */
  public function infiniteMultiValue(): void {
    try {
      $app_id = 'testing20MRCI4146'; // DEV
      $api_key = 'ebcff7267cce84aac818b8e68e4df7fd'; // DEV
      // $index_name = 'fm_product_en_dev'; // DEV
      $index_name = 'fm_product_en_qa'; // QA      
      $chunk_size = 500;
      // Initialize Algolia client.
      $client = SearchClient::create($app_id, $api_key);
      $index = $client->initIndex($index_name);

      $total_processed = 0;
      $update_records = [];

      $records = $index->browseObjects();

      foreach ($records as $hit) {
        if (isset($hit['Interface Type'])) {
          if (is_string($hit['Interface Type'])) {
            $hit['Interface Type'] = [$hit['Interface Type']];
            $update_records[] = $hit;
          }
        }
      }

      // Update records in chunks.
      if (!empty($update_records)) {
        foreach (array_chunk($update_records, $chunk_size) as $update_chunk) {
          $index->saveObjects($update_chunk);
          $total_processed += count($update_chunk);
          $this->logger()->notice('Updated ' . $total_processed . ' records');
        }
      }


      $this->logger()->success('Successfully processed all records.');
    }
    catch (\Exception $e) {
      $this->logger()->error('Error processing records: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

}
