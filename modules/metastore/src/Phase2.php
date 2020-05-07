<?php

namespace Drupal\metastore;

use Drupal\Component\Uuid\Php;
use Drupal\Component\Uuid\Uuid;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\pqdc_content\FileFetcher\Processor\S3;
use Drupal\pqdc_content\Util;
use FileFetcher\FileFetcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

class Phase2 extends FileFetcher implements ContainerInjectionInterface {

  /**
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  private $uuidService;

  private $urlStorage = [];

  public function __construct(UuidInterface $uuidService) {
    $this->uuidService = $uuidService;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('uuid')
    );
  }

  public static function register(string $url) : string {
    $uuid = (new Php())->generate();

    /** @var \Drupal\datastore\Storage\JobStoreFactory $storage */
    $storageService = \Drupal::service('datastore.job_store_factory');
    $storage = $storageService->getInstance('remote_files', []);

    $publicPath = Util::getDrupalsPublicFilesDirectory();
    $distributionPath = $publicPath + '/distributions/' . $uuid ;
    Util::prepareDirectory($distributionPath);

    $fileFetcherConfig = [
      'filePath' => $url,
      'processors' => [S3::class],
      'temporaryDirectory' => $distributionPath,
    ];

    $phase2 = self::get($uuid, $storage, $fileFetcherConfig);
    // @Todo: place in a queueWorker job
    $phase2->run();

    return $uuid;
  }

  public function retrieveLocalUrl(string $uuid) : string {
    if (!isset($this->urlStorage[$uuid])) {
      throw new \Exception('Unknown url.');
    }
    return $this->urlStorage[$uuid];
  }

}
