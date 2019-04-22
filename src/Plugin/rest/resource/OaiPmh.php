<?php

namespace Drupal\rest_oai_pmh\Plugin\rest\resource;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\node\Entity\Node;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "oai_pmh",
 *   label = @Translation("OAI-PMH"),
 *   uri_paths = {
 *     "canonical" = "/oai/request"
 *   }
 * )
 */
class OaiPmh extends ResourceBase {

  const OAI_DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

  /**
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  protected $currentRequest;

  private $response = [];

  private $error = FALSE;

  private $entity;

  private $bundle;

  private $set_field;
  private $set_field_conditional;

  /**
   * Constructs a new OaiPmh object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   A current user instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $current_user,
    Request $currentRequest) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);

    $this->currentUser = $current_user;
    $this->currentRequest = $currentRequest;

    // read the config settings for this endpoint
    $config = \Drupal::config('rest_oai_pmh.settings');
    $this->bundle = $config->get('bundle');
    $this->set_field = $config->get('set_field');
    $this->set_field_conditional = $config->get('set_field_conditional');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest_oai_pmh'),
      $container->get('current_user'),
      $container->get('request_stack')->getCurrentRequest()
    );
  }

  /**
   * Responds to GET requests.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function get() {

    // You must to implement the logic of your REST Resource here.
    // Use current user after pass authentication to validate access.
    if (!$this->currentUser->hasPermission('access content')) {
      throw new AccessDeniedHttpException();
    }

    $base_oai_url = $this->currentRequest->getSchemeAndHttpHost() . '/oai/request';

    $this->response = [
      '@xmlns' => 'http://www.openarchives.org/OAI/2.0/',
      '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
      '@xsi:schemaLocation' => 'http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd',
      '@name' => 'OAI-PMH',
      'responseDate' => gmdate(self::OAI_DATE_FORMAT, \Drupal::time()->getRequestTime()),
      'request' => [
         'oai-dc-string' => $base_oai_url
       ],
    ];
    $verb = $this->currentRequest->get('verb');
    $set_id = $this->currentRequest->get('set');
    $verbs = [
      'GetRecord',
      'Identify',
      'ListMetadataFormats',
      'ListRecords',
      'ListSets'
    ];
    if (in_array($verb, $verbs)) {
      $this->response['request']['@verb'] = $verb;
      $this->{$verb}();
    }
    else {
      $this->response['error'] = [
        '@code' => 'badVerb',
        'oai-dc-string' =>  'Value of the verb argument is not a legal OAI-PMH verb, the verb argument is missing, or the verb argument is repeated.'
      ];
    }


    $response = new ResourceResponse($this->response, 200);
    $response->addCacheableDependency($this->response['request']);

    return $response;
  }

  protected function GetRecord() {

    $identifier = $this->currentRequest->get('identifier');
    if (empty($identifier)) {
      $this->setError('badArgument', 'Missing required argument identifier.');
    }
    $components = explode(':', $identifier);
    $nid = empty($components[2]) ? FALSE : $components[2];
    $this->entity = Node::load($nid);
    if (count($components) != 3 ||
      $components[0] !== 'oai' ||
      $components[1] !== $this->currentRequest->getHttpHost() ||
      empty($this->entity) ||
      (!empty($this->bundle) && $this->entity->bundle() !== $this->bundle)) {
      $this->setError('idDoesNotExist', 'The value of the identifier argument is unknown or illegal in this repository.');
    }
    $metadata_prefix = $this->currentRequest->get('metadataPrefix');
    if (empty($metadata_prefix)) {
      $this->setError('badArgument', 'Missing required argument metadataPrefix.');
    }
    elseif (!in_array($metadata_prefix, ['oai_dc'])) {
      $this->setError('cannotDisseminateFormat', 'The metadata format identified by the value given for the metadataPrefix argument is not supported by the item or by the repository.');
    }

    if ($this->error) {
      unset($this->response['request']['@verb']);
      return;
    }

    $set_nids = empty($this->set_field) ? [] : $this->getSetNids();
    $this->response[__FUNCTION__]['record'] = $this->getRecordById($identifier, $set_nids);
  }

  protected function Identify() {
    /**
    * @todo fetch earliest created date on entities as defined in config
    * i.e. eventually let admins choose entity_type and optionally bundles of entities to expose to OAI
    */
    $earliest_date = \Drupal::database()->query('SELECT MIN(`created`)
      FROM {node_field_data}')->fetchField();

    $this->response[__FUNCTION__] = [
      'repositoryName' => \Drupal::config('system.site')->get('name'),
      'baseURL' => $this->currentRequest->getSchemeAndHttpHost() . '/oai/request',
      'protocolVersion' => '2.0',
      'adminEmail' => \Drupal::config('system.site')->get('mail'),
      'earliestDatestamp' => gmdate(self::OAI_DATE_FORMAT, $earliest_date),
      'deletedRecord' => 'no',
      'granularity' => 'YYYY-MM-DDThh:mm:ssZ',
      'description' => [
        'oai-identifier' => [
          '@xmlns' => 'http://www.openarchives.org/OAI/2.0/oai-identifier',
          '@xsi:schemaLocation' => 'http://www.openarchives.org/OAI/2.0/oai-identifier http://www.openarchives.org/OAI/2.0/oai-identifier.xsd',
          'scheme' => 'oai',
          'repositoryIdentifier' => $this->currentRequest->getHttpHost(),
          'delimiter' => ':',
          'sampleIdentifier' => 'oai:' . $this->currentRequest->getHttpHost() . ':1'
        ]
      ]
    ];
  }

  protected function ListMetadataFormats() {
    // @todo support more metadata formats
    $this->response[__FUNCTION__] = [
      'metadataFormat' => [
        'metadataPrefix' => 'oai_dc',
        'schema' => 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd',
        'metadataNamespace' => 'http://www.openarchives.org/OAI/2.0/oai_dc/'
      ],
    ];
  }

  protected function ListRecords() {
    $metadata_prefix = $this->currentRequest->get('metadataPrefix');
    if (empty($metadata_prefix)) {
      $this->setError('badArgument', 'Missing required argument metadataPrefix.');
    }
    elseif (!in_array($metadata_prefix, ['oai_dc'])) {
      $this->setError('cannotDisseminateFormat', 'The metadata format identified by the value given for the metadataPrefix argument is not supported by the item or by the repository.');
    }
    if ($this->error) {
      return;
    }

    $query = \Drupal::entityQuery('node')
         ->condition('status', NODE_PUBLISHED);
    if ($this->bundle) {
      $query->condition('type', $this->bundle);
    }

    // do not include sets in the list of records
    $set_nids = $this->getSetNids();
    $query->condition('nid', $set_nids, 'NOT IN');

    // if sets are supported,
    // be sure to only include items referenced by sets specified
    if (!empty($this->set_field)) {
      $query->condition("{$this->set_field}.target_id", $set_nids, 'IN');
    }

    // @todo check set GET param and add a condition if it's there

    $query->range(0, 25);
    $nids = $query->execute();
    foreach ($nids as $nid) {
      $this->entity = Node::load($nid);
      $identifier = 'oai:' . $this->currentRequest->getHttpHost() . ':' .$nid;
      $this->response[__FUNCTION__]['record'][] = $this->getRecordById($identifier, $set_nids);
    }
  }

  protected function ListSets() {
    if (empty($this->set_field)) {
      $this->setError('noSetHierarchy', 'The repository does not support sets.');
      return;
    }

    $this->response[__FUNCTION__] = [];

    $nids = $this->getSetNids();
    foreach ($nids as $nid) {
      $this->entity = Node::load($nid);
      if ($this->entity && $this->entity->isPublished()) {
        // @todo check for set hierarchy and show accordingly
        // https://www.openarchives.org/OAI/2.0/guidelines-repository.htm#Sets-Hierarchy
        $this->response[__FUNCTION__][] = [
          'set' => [
            'setSpec' => $this->entity->id(),
            'setName' => $this->entity->label(),
            'setDescription' => $this->getRecordMetadata()
          ]
        ];
      }
    }
  }

  protected function setError($code, $string) {
    $this->response['error'][] = [
      '@code' => $code,
      'oai-dc-string' =>  $string,
    ];
    $this->error = TRUE;
  }

  protected function getRecordById($identifier, $set_nids) {
    $record = [
      'header' => [
        'identifier' => $identifier,
      ],
    ];

    $record['header']['datestamp'] = gmdate(self::OAI_DATE_FORMAT, $this->entity->changed->value);
    if (!empty($this->set_field) &&
      $this->entity->hasField($this->set_field)) {
      foreach ($this->entity->get($this->set_field) as $set) {
        $set_nid = empty($set->entity) ? -1 : $set->entity->id();
        if (in_array($set_nid, $set_nids)) {
          $record['header']['setSpec'][] = $set_nid;
        }
      }
    }

    $record['metadata'] = $this->getRecordMetadata();

    return $record;
  }

  protected function getRecordMetadata() {
    $metadata = [
      'oai_dc:dc' => [
        '@xmlns:oai_dc' => 'http://www.openarchives.org/OAI/2.0/oai_dc/',
        '@xmlns:dc' => 'http://purl.org/dc/elements/1.1/',
        '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
        '@xsi:schemaLocation' => 'http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd',
      ]
    ];

    // @todo setSpec base on config

    // @see https://www.lullabot.com/articles/early-rendering-a-lesson-in-debugging-drupal-8
    // can't just call metatag_generate_entity_metatags() here since it renders node token values,
    // which in turn screwing up caching on the REST resource
    // @todo ensure caching is working properly here
    $context = new RenderContext();
    $metatags = \Drupal::service('renderer')->executeInRenderContext($context, function() {
      return metatag_generate_entity_metatags($this->entity);
    });
    if (!$context->isEmpty()) {
      $bubbleable_metadata = $context->pop();
      BubbleableMetadata::createFromObject($metatags)
        ->merge($bubbleable_metadata);
    }

    // go through all the metatags ['#type' => 'tag'] render elements
    // and find mappings for dublin core tags
    foreach ($metatags as $term => $metatag) {
      if (strpos($term, 'dcterms') !== FALSE) {
        // metatag_dc stores terms ad dcterms.ELEMENT
        // rename for oai_dc
        $term = str_replace('dcterms.', 'dc:', $metatag['#attributes']['name']);
        $metadata['oai_dc:dc'][$term][] = $metatag['#attributes']['content'];
      }
    }

    return $metadata;
  }

  protected function getSetNids() {
    // can not use entityQuery here because it does not allow for conditionals on referenced entities
    // so just query the field SQL table directly
    $table = 'node__' . $this->set_field;
    $query = \Drupal::database()->select($table, 'f');

    // return the {node}.nid of the sets referenced
    $column = $this->set_field . '_target_id';
    $query->addField('f', $column);

    // if the repo admin specified a field to filter the sets exposed to OAI
    // add that filter to the query
    if ($this->set_field_conditional) {
      if (in_array($this->set_field_conditional, ['promote', 'sticky'])) {
        $set_filter_table = 'node_field_data';
        $set_filter_column = $this->set_field_conditional;
        $set_filter_id = 'nid';
      }
      else {
        $set_filter_table = 'node__' . $this->set_field_conditional;
        $set_filter_column = $this->set_field_conditional . '_value';
        $set_filter_id = 'entity_id';
      }
      $query->innerJoin($set_filter_table, 'set_filter', "set_filter.{$set_filter_id} = f.{$column}");
      $query->condition("set_filter.{$set_filter_column}", 1);
    }


    $nids = $query->groupBy($column)
      ->execute()
      ->fetchCol();

    return $nids;
  }
}
