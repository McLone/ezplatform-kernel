<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace eZ\Publish\Core\Persistence\Cache\Tests;

use eZ\Publish\Core\Persistence\Cache\Adapter\TransactionalInMemoryCacheAdapter;
use eZ\Publish\Core\Persistence\Cache\Handler as CacheHandler;
use eZ\Publish\Core\Persistence\Cache\SectionHandler as CacheSectionHandler;
use eZ\Publish\Core\Persistence\Cache\LocationHandler as CacheLocationHandler;
use eZ\Publish\Core\Persistence\Cache\ContentHandler as CacheContentHandler;
use eZ\Publish\Core\Persistence\Cache\ContentLanguageHandler as CacheContentLanguageHandler;
use eZ\Publish\Core\Persistence\Cache\ContentTypeHandler as CacheContentTypeHandler;
use Ibexa\Core\Persistence\Cache\Tag\CacheIdentifierGeneratorInterface;
use eZ\Publish\Core\Persistence\Cache\UserHandler as CacheUserHandler;
use eZ\Publish\Core\Persistence\Cache\TransactionHandler as CacheTransactionHandler;
use eZ\Publish\Core\Persistence\Cache\TrashHandler as CacheTrashHandler;
use eZ\Publish\Core\Persistence\Cache\UrlAliasHandler as CacheUrlAliasHandler;
use eZ\Publish\Core\Persistence\Cache\ObjectStateHandler as CacheObjectStateHandler;
use eZ\Publish\Core\Persistence\Cache\URLHandler as CacheUrlHandler;
use eZ\Publish\Core\Persistence\Cache\BookmarkHandler as CacheBookmarkHandler;
use eZ\Publish\Core\Persistence\Cache\NotificationHandler as CacheNotificationHandler;
use eZ\Publish\Core\Persistence\Cache\UserPreferenceHandler as CacheUserPreferenceHandler;
use eZ\Publish\Core\Persistence\Cache\UrlWildcardHandler as CacheUrlWildcardHandler;
use eZ\Publish\Core\Persistence\Cache\SettingHandler as CacheSettingHandler;
use eZ\Publish\Core\Persistence\Cache\InMemory\InMemoryCache;
use eZ\Publish\Core\Persistence\Cache\PersistenceLogger;
use eZ\Publish\SPI\Persistence\Handler;
use Symfony\Component\Cache\CacheItem;
use PHPUnit\Framework\TestCase;

/**
 * Abstract test case for spi cache impl.
 */
abstract class AbstractBaseHandlerTest extends TestCase
{
    /** @var \eZ\Publish\Core\Persistence\Cache\Adapter\TransactionalInMemoryCacheAdapter|\PHPUnit\Framework\MockObject\MockObject */
    protected $cacheMock;

    /** @var \eZ\Publish\SPI\Persistence\Handler|\PHPUnit\Framework\MockObject\MockObject */
    protected $persistenceHandlerMock;

    /** @var \eZ\Publish\Core\Persistence\Cache\Handler */
    protected $persistenceCacheHandler;

    /** @var \eZ\Publish\Core\Persistence\Cache\PersistenceLogger|\PHPUnit\Framework\MockObject\MockObject */
    protected $loggerMock;

    /** @var \eZ\Publish\Core\Persistence\Cache\InMemory\InMemoryCache|\PHPUnit\Framework\MockObject\MockObject */
    protected $inMemoryMock;

    /** @var \Closure */
    protected $cacheItemsClosure;

    /** @var \Ibexa\Core\Persistence\Cache\Tag\CacheIdentifierGeneratorInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $cacheIdentifierGeneratorMock;

    /**
     * Setup the HandlerTest.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->persistenceHandlerMock = $this->createMock(Handler::class);
        $this->cacheMock = $this->createMock(TransactionalInMemoryCacheAdapter::class);
        $this->loggerMock = $this->createMock(PersistenceLogger::class);
        $this->inMemoryMock = $this->createMock(InMemoryCache::class);
        $this->cacheIdentifierGeneratorMock = $this->createMock(CacheIdentifierGeneratorInterface::class);

        $this->persistenceCacheHandler = new CacheHandler(
            $this->persistenceHandlerMock,
            new CacheSectionHandler($this->cacheMock, $this->persistenceHandlerMock, $this->loggerMock, $this->cacheIdentifierGeneratorMock),
            new CacheLocationHandler($this->cacheMock, $this->loggerMock, $this->inMemoryMock, $this->persistenceHandlerMock, $this->cacheIdentifierGeneratorMock),
            new CacheContentHandler($this->cacheMock, $this->loggerMock, $this->inMemoryMock, $this->persistenceHandlerMock, $this->cacheIdentifierGeneratorMock),
            new CacheContentLanguageHandler($this->cacheMock, $this->loggerMock, $this->inMemoryMock, $this->persistenceHandlerMock, $this->cacheIdentifierGeneratorMock),
            new CacheContentTypeHandler($this->cacheMock, $this->loggerMock, $this->inMemoryMock, $this->persistenceHandlerMock, $this->cacheIdentifierGeneratorMock),
            new CacheUserHandler($this->cacheMock, $this->loggerMock, $this->inMemoryMock, $this->persistenceHandlerMock, $this->cacheIdentifierGeneratorMock),
            new CacheTransactionHandler($this->cacheMock, $this->loggerMock, $this->inMemoryMock, $this->persistenceHandlerMock, $this->cacheIdentifierGeneratorMock),
            new CacheTrashHandler($this->cacheMock, $this->persistenceHandlerMock, $this->loggerMock, $this->cacheIdentifierGeneratorMock),
            new CacheUrlAliasHandler($this->cacheMock, $this->loggerMock, $this->inMemoryMock, $this->persistenceHandlerMock, $this->cacheIdentifierGeneratorMock),
            new CacheObjectStateHandler($this->cacheMock, $this->loggerMock, $this->inMemoryMock, $this->persistenceHandlerMock, $this->cacheIdentifierGeneratorMock),
            new CacheUrlHandler($this->cacheMock, $this->persistenceHandlerMock, $this->loggerMock, $this->cacheIdentifierGeneratorMock),
            new CacheBookmarkHandler($this->cacheMock, $this->persistenceHandlerMock, $this->loggerMock, $this->cacheIdentifierGeneratorMock),
            new CacheNotificationHandler($this->cacheMock, $this->persistenceHandlerMock, $this->loggerMock, $this->cacheIdentifierGeneratorMock),
            new CacheUserPreferenceHandler($this->cacheMock, $this->loggerMock, $this->inMemoryMock, $this->persistenceHandlerMock, $this->cacheIdentifierGeneratorMock),
            new CacheUrlWildcardHandler($this->cacheMock, $this->persistenceHandlerMock, $this->loggerMock, $this->cacheIdentifierGeneratorMock),
            new CacheSettingHandler($this->cacheMock, $this->persistenceHandlerMock, $this->loggerMock, $this->cacheIdentifierGeneratorMock),
            $this->loggerMock
        );

        $this->cacheItemsClosure = \Closure::bind(
            static function ($key, $value, $isHit, $defaultLifetime = 0) {
                $item = new CacheItem();
                $item->key = $key;
                $item->value = $value;
                $item->isHit = $isHit;
                $item->defaultLifetime = $defaultLifetime;
                $item->isTaggable = true;

                return $item;
            },
            null,
            CacheItem::class
        );
    }

    /**
     * Tear down test (properties).
     */
    protected function tearDown(): void
    {
        unset(
            $this->cacheMock,
            $this->persistenceHandlerMock,
            $this->persistenceCacheHandler,
            $this->loggerMock,
            $this->cacheItemsClosure,
            $this->inMemoryMock,
            $this->cacheIdentifierGeneratorMock
        );

        parent::tearDown();
    }

    /**
     * @param $key
     * @param null $value If null the cache item will be assumed to be a cache miss here.
     * @param int $defaultLifetime
     *
     * @return CacheItem
     */
    final protected function getCacheItem($key, $value = null, $defaultLifetime = 0)
    {
        $cacheItemsClosure = $this->cacheItemsClosure;

        return $cacheItemsClosure($key, $value, (bool)$value, $defaultLifetime);
    }
}
