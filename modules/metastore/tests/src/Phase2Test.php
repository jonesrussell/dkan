<?php

namespace Drupal\Tests\metastore;

use Drupal\Component\Uuid\Php;
use Drupal\Component\Uuid\Uuid;
use Drupal\metastore\Phase2;
use MockChain\Chain;
use MockChain\Options;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class Phase2Test extends TestCase {

  public function testRegister() {
    $container = $this->getCommonMockChain();
    $p2 = Phase2::create($container->getMock());

    $uuidReturned = $p2->register('s3://bucket/filename.ext');
    $this->assertTrue(Uuid::isValid($uuidReturned));
  }

  public function testRegisterAlreadyExisting() {
    $container = $this->getCommonMockChain();
    $p2 = Phase2::create($container->getMock());

    $p2->register('s3://bucket/filename.ext');
    $this->expectExceptionMessage('Url already registered.');
    $p2->register('s3://bucket/filename.ext');
  }

  public function testRetrieveLocalUrl() {
    $container = $this->getCommonMockChain();
    $p2 = Phase2::create($container->getMock());

    $originalUrl = 'foobar';
    $uuid = $p2->register($originalUrl);
    $localUrl = $p2->retrieveLocalUrl($uuid);
    // @Todo: Check that this is a local url.
    $this->assertNotEquals($originalUrl, $localUrl);
  }

  public function testRetrieveLocalUrlNonExistent() {
    $container = $this->getCommonMockChain();
    $p2 = Phase2::create($container->getMock());

    $this->expectExceptionMessage('Unknown url.');
    $p2->retrieveLocalUrl('foobar');
  }

  private function getCommonMockChain() {
    $options = (new Options())
      ->add('uuid', new Php())
      ->index(0);

    $container = (new Chain($this))
      ->add(ContainerInterface::class, 'get', $options);

    return $container;
  }

}
