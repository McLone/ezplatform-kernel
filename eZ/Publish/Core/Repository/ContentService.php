<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace eZ\Publish\Core\Repository;

use eZ\Publish\API\Repository\ContentService as ContentServiceInterface;
use eZ\Publish\API\Repository\PermissionService;
use eZ\Publish\API\Repository\Repository as RepositoryInterface;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion\LanguageCode;
use eZ\Publish\API\Repository\Values\ValueObject;
use eZ\Publish\Core\FieldType\FieldTypeRegistry;
use eZ\Publish\API\Repository\Values\Content\ContentDraftList;
use eZ\Publish\API\Repository\Values\Content\DraftList\Item\ContentDraftListItem;
use eZ\Publish\API\Repository\Values\Content\DraftList\Item\UnauthorizedContentDraftListItem;
use eZ\Publish\API\Repository\Values\Content\RelationList;
use eZ\Publish\API\Repository\Values\Content\RelationList\Item\RelationListItem;
use eZ\Publish\API\Repository\Values\Content\RelationList\Item\UnauthorizedRelationListItem;
use eZ\Publish\API\Repository\Values\User\UserReference;
use eZ\Publish\Core\Repository\Mapper\ContentDomainMapper;
use eZ\Publish\Core\Repository\Mapper\ContentMapper;
use eZ\Publish\Core\Repository\Values\Content\Content;
use eZ\Publish\Core\Repository\Values\Content\Location;
use eZ\Publish\API\Repository\Values\Content\Language;
use eZ\Publish\SPI\Persistence\Filter\Content\Handler as ContentFilteringHandler;
use eZ\Publish\SPI\FieldType\Comparable;
use eZ\Publish\SPI\FieldType\FieldType;
use eZ\Publish\SPI\FieldType\Value;
use eZ\Publish\SPI\Persistence\Handler;
use eZ\Publish\API\Repository\Values\Content\ContentUpdateStruct as APIContentUpdateStruct;
use eZ\Publish\API\Repository\Values\ContentType\ContentType;
use eZ\Publish\API\Repository\Values\Content\ContentCreateStruct as APIContentCreateStruct;
use eZ\Publish\API\Repository\Values\Content\ContentMetadataUpdateStruct;
use eZ\Publish\API\Repository\Values\Content\Content as APIContent;
use eZ\Publish\API\Repository\Values\Content\VersionInfo as APIVersionInfo;
use eZ\Publish\API\Repository\Values\Content\ContentInfo;
use eZ\Publish\API\Repository\Values\User\User;
use eZ\Publish\API\Repository\Values\Content\LocationCreateStruct;
use eZ\Publish\API\Repository\Values\Content\Relation as APIRelation;
use eZ\Publish\API\Repository\Exceptions\NotFoundException as APINotFoundException;
use eZ\Publish\Core\Base\Exceptions\BadStateException;
use eZ\Publish\Core\Base\Exceptions\NotFoundException;
use eZ\Publish\Core\Base\Exceptions\InvalidArgumentException;
use eZ\Publish\Core\Base\Exceptions\ContentFieldValidationException;
use eZ\Publish\Core\Base\Exceptions\UnauthorizedException;
use eZ\Publish\Core\Repository\Values\Content\VersionInfo;
use eZ\Publish\Core\Repository\Values\Content\ContentCreateStruct;
use eZ\Publish\Core\Repository\Values\Content\ContentUpdateStruct;
use eZ\Publish\SPI\Limitation\Target;
use eZ\Publish\SPI\Persistence\Content\MetadataUpdateStruct as SPIMetadataUpdateStruct;
use eZ\Publish\SPI\Persistence\Content\CreateStruct as SPIContentCreateStruct;
use eZ\Publish\SPI\Persistence\Content\UpdateStruct as SPIContentUpdateStruct;
use eZ\Publish\SPI\Persistence\Content\Field as SPIField;
use eZ\Publish\SPI\Persistence\Content\Relation\CreateStruct as SPIRelationCreateStruct;
use eZ\Publish\SPI\Persistence\Content\ContentInfo as SPIContentInfo;
use Exception;
use eZ\Publish\SPI\Repository\Validator\ContentValidator;
use eZ\Publish\API\Repository\Values\Content\ContentList;
use eZ\Publish\API\Repository\Values\Filter\Filter;
use eZ\Publish\SPI\Repository\Values\Filter\FilteringCriterion;
use function count;
use function sprintf;

/**
 * This class provides service methods for managing content.
 */
class ContentService implements ContentServiceInterface
{
    /** @var \eZ\Publish\Core\Repository\Repository */
    protected $repository;

    /** @var \eZ\Publish\SPI\Persistence\Handler */
    protected $persistenceHandler;

    /** @var array */
    protected $settings;

    /** @var \eZ\Publish\Core\Repository\Mapper\ContentDomainMapper */
    protected $contentDomainMapper;

    /** @var \eZ\Publish\Core\Repository\Helper\RelationProcessor */
    protected $relationProcessor;

    /** @var \eZ\Publish\Core\Repository\Helper\NameSchemaService */
    protected $nameSchemaService;

    /** @var \eZ\Publish\Core\FieldType\FieldTypeRegistry */
    protected $fieldTypeRegistry;

    /** @var \eZ\Publish\API\Repository\PermissionResolver */
    private $permissionResolver;

    /** @var \eZ\Publish\Core\Repository\Mapper\ContentMapper */
    private $contentMapper;

    /** @var \eZ\Publish\SPI\Repository\Validator\ContentValidator */
    private $contentValidator;

    /** @var \eZ\Publish\SPI\Persistence\Filter\Content\Handler */
    private $contentFilteringHandler;

    public function __construct(
        RepositoryInterface $repository,
        Handler $handler,
        ContentDomainMapper $contentDomainMapper,
        Helper\RelationProcessor $relationProcessor,
        Helper\NameSchemaService $nameSchemaService,
        FieldTypeRegistry $fieldTypeRegistry,
        PermissionService $permissionService,
        ContentMapper $contentMapper,
        ContentValidator $contentValidator,
        ContentFilteringHandler $contentFilteringHandler,
        array $settings = []
    ) {
        $this->repository = $repository;
        $this->persistenceHandler = $handler;
        $this->contentDomainMapper = $contentDomainMapper;
        $this->relationProcessor = $relationProcessor;
        $this->nameSchemaService = $nameSchemaService;
        $this->fieldTypeRegistry = $fieldTypeRegistry;
        // Union makes sure default settings are ignored if provided in argument
        $this->settings = $settings + [
            // Version archive limit (0-50), only enforced on publish, not on un-publish.
            'default_version_archive_limit' => 5,
            'remove_archived_versions_on_publish' => true,
        ];
        $this->contentFilteringHandler = $contentFilteringHandler;
        $this->permissionResolver = $permissionService;
        $this->contentMapper = $contentMapper;
        $this->contentValidator = $contentValidator;
    }

    /**
     * Loads a content info object.
     *
     * To load fields use loadContent
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed to read the content
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException - if the content with the given id does not exist
     *
     * @param int $contentId
     *
     * @return \eZ\Publish\API\Repository\Values\Content\ContentInfo
     */
    public function loadContentInfo(int $contentId): ContentInfo
    {
        $contentInfo = $this->internalLoadContentInfoById($contentId);
        if (!$this->permissionResolver->canUser('content', 'read', $contentInfo)) {
            throw new UnauthorizedException('content', 'read', ['contentId' => $contentId]);
        }

        return $contentInfo;
    }

    /**
     * {@inheritdoc}
     */
    public function loadContentInfoList(array $contentIds): iterable
    {
        $contentInfoList = [];
        $spiInfoList = $this->persistenceHandler->contentHandler()->loadContentInfoList($contentIds);
        foreach ($spiInfoList as $id => $spiInfo) {
            $contentInfo = $this->contentDomainMapper->buildContentInfoDomainObject($spiInfo);
            if ($this->permissionResolver->canUser('content', 'read', $contentInfo)) {
                $contentInfoList[$id] = $contentInfo;
            }
        }

        return $contentInfoList;
    }

    /**
     * Loads a content info object.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException - if the content with the given id does not exist
     *
     * @param int $id
     *
     * @return \eZ\Publish\API\Repository\Values\Content\ContentInfo
     */
    public function internalLoadContentInfoById(int $id): ContentInfo
    {
        try {
            return $this->contentDomainMapper->buildContentInfoDomainObject(
                $this->persistenceHandler->contentHandler()->loadContentInfo($id)
            );
        } catch (APINotFoundException $e) {
            throw new NotFoundException('Content', $id, $e);
        }
    }

    /**
     * Loads a content info object by remote id.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException - if the content with the given id does not exist
     *
     * @param string $remoteId
     *
     * @return \eZ\Publish\API\Repository\Values\Content\ContentInfo
     */
    public function internalLoadContentInfoByRemoteId(string $remoteId): ContentInfo
    {
        try {
            return $this->contentDomainMapper->buildContentInfoDomainObject(
                $this->persistenceHandler->contentHandler()->loadContentInfoByRemoteId($remoteId)
            );
        } catch (APINotFoundException $e) {
            throw new NotFoundException('Content', $remoteId, $e);
        }
    }

    /**
     * Loads a content info object for the given remoteId.
     *
     * To load fields use loadContent
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed to read the content
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException - if the content with the given remote id does not exist
     *
     * @param string $remoteId
     *
     * @return \eZ\Publish\API\Repository\Values\Content\ContentInfo
     */
    public function loadContentInfoByRemoteId(string $remoteId): ContentInfo
    {
        $contentInfo = $this->internalLoadContentInfoByRemoteId($remoteId);

        if (!$this->permissionResolver->canUser('content', 'read', $contentInfo)) {
            throw new UnauthorizedException('content', 'read', ['remoteId' => $remoteId]);
        }

        return $contentInfo;
    }

    /**
     * Loads a version info of the given content object.
     *
     * If no version number is given, the method returns the current version
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException - if the version with the given number does not exist
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed to load this version
     *
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo $contentInfo
     * @param int|null $versionNo the version number. If not given the current version is returned.
     *
     * @return \eZ\Publish\API\Repository\Values\Content\VersionInfo
     */
    public function loadVersionInfo(ContentInfo $contentInfo, ?int $versionNo = null): APIVersionInfo
    {
        return $this->loadVersionInfoById($contentInfo->id, $versionNo);
    }

    /**
     * Loads a version info of the given content object id.
     *
     * If no version number is given, the method returns the current version
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException - if the version with the given number does not exist
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed to load this version
     *
     * @param int $contentId
     * @param int|null $versionNo the version number. If not given the current version is returned.
     *
     * @return \eZ\Publish\API\Repository\Values\Content\VersionInfo
     */
    public function loadVersionInfoById(int $contentId, ?int $versionNo = null): APIVersionInfo
    {
        try {
            $spiVersionInfo = $this->persistenceHandler->contentHandler()->loadVersionInfo(
                $contentId,
                $versionNo
            );
        } catch (APINotFoundException $e) {
            throw new NotFoundException(
                'VersionInfo',
                [
                    'contentId' => $contentId,
                    'versionNo' => $versionNo,
                ],
                $e
            );
        }

        $versionInfo = $this->contentDomainMapper->buildVersionInfoDomainObject($spiVersionInfo);

        if ($versionInfo->isPublished()) {
            $function = 'read';
        } else {
            $function = 'versionread';
        }

        if (!$this->permissionResolver->canUser('content', $function, $versionInfo)) {
            throw new UnauthorizedException('content', $function, ['contentId' => $contentId]);
        }

        return $versionInfo;
    }

    /**
     * {@inheritdoc}
     */
    public function loadContentByContentInfo(ContentInfo $contentInfo, array $languages = null, ?int $versionNo = null, bool $useAlwaysAvailable = true): APIContent
    {
        // Change $useAlwaysAvailable to false to avoid contentInfo lookup if we know alwaysAvailable is disabled
        if ($useAlwaysAvailable && !$contentInfo->alwaysAvailable) {
            $useAlwaysAvailable = false;
        }

        return $this->loadContent(
            $contentInfo->id,
            $languages,
            $versionNo,// On purpose pass as-is and not use $contentInfo, to make sure to return actual current version on null
            $useAlwaysAvailable
        );
    }

    /**
     * {@inheritdoc}
     */
    public function loadContentByVersionInfo(APIVersionInfo $versionInfo, array $languages = null, bool $useAlwaysAvailable = true): APIContent
    {
        // Change $useAlwaysAvailable to false to avoid contentInfo lookup if we know alwaysAvailable is disabled
        if ($useAlwaysAvailable && !$versionInfo->getContentInfo()->alwaysAvailable) {
            $useAlwaysAvailable = false;
        }

        return $this->loadContent(
            $versionInfo->getContentInfo()->id,
            $languages,
            $versionInfo->versionNo,
            $useAlwaysAvailable
        );
    }

    /**
     * {@inheritdoc}
     */
    public function loadContent(int $contentId, array $languages = null, ?int $versionNo = null, bool $useAlwaysAvailable = true): APIContent
    {
        $content = $this->internalLoadContentById($contentId, $languages, $versionNo, $useAlwaysAvailable);

        if (!$this->permissionResolver->canUser('content', 'read', $content)) {
            throw new UnauthorizedException('content', 'read', ['contentId' => $contentId]);
        }
        if (
            !$content->getVersionInfo()->isPublished()
            && !$this->permissionResolver->canUser('content', 'versionread', $content)
        ) {
            throw new UnauthorizedException('content', 'versionread', ['contentId' => $contentId, 'versionNo' => $versionNo]);
        }

        return $content;
    }

    public function internalLoadContentById(
        int $id,
        ?array $languages = null,
        int $versionNo = null,
        bool $useAlwaysAvailable = true
    ): APIContent {
        try {
            $spiContentInfo = $this->persistenceHandler->contentHandler()->loadContentInfo($id);

            return $this->internalLoadContentBySPIContentInfo(
                $spiContentInfo,
                $languages,
                $versionNo,
                $useAlwaysAvailable
            );
        } catch (APINotFoundException $e) {
            throw new NotFoundException(
                'Content',
                [
                    'id' => $id,
                    'languages' => $languages,
                    'versionNo' => $versionNo,
                ],
                $e
            );
        }
    }

    public function internalLoadContentByRemoteId(
        string $remoteId,
        array $languages = null,
        int $versionNo = null,
        bool $useAlwaysAvailable = true
    ): APIContent {
        try {
            $spiContentInfo = $this->persistenceHandler->contentHandler()->loadContentInfoByRemoteId($remoteId);

            return $this->internalLoadContentBySPIContentInfo(
                $spiContentInfo,
                $languages,
                $versionNo,
                $useAlwaysAvailable
            );
        } catch (APINotFoundException $e) {
            throw new NotFoundException(
                'Content',
                [
                    'remoteId' => $remoteId,
                    'languages' => $languages,
                    'versionNo' => $versionNo,
                ],
                $e
            );
        }
    }

    private function internalLoadContentBySPIContentInfo(SPIContentInfo $spiContentInfo, array $languages = null, int $versionNo = null, bool $useAlwaysAvailable = true): APIContent
    {
        $loadLanguages = $languages;
        $alwaysAvailableLanguageCode = null;
        // Set main language on $languages filter if not empty (all) and $useAlwaysAvailable being true
        // @todo Move use always available logic to SPI load methods, like done in location handler in 7.x
        if (!empty($loadLanguages) && $useAlwaysAvailable && $spiContentInfo->alwaysAvailable) {
            $loadLanguages[] = $alwaysAvailableLanguageCode = $spiContentInfo->mainLanguageCode;
            $loadLanguages = array_unique($loadLanguages);
        }

        $spiContent = $this->persistenceHandler->contentHandler()->load(
            $spiContentInfo->id,
            $versionNo,
            $loadLanguages
        );

        if ($languages === null) {
            $languages = [];
        }

        return $this->contentDomainMapper->buildContentDomainObject(
            $spiContent,
            $this->repository->getContentTypeService()->loadContentType(
                $spiContent->versionInfo->contentInfo->contentTypeId,
                $languages
            ),
            $languages,
            $alwaysAvailableLanguageCode
        );
    }

    /**
     * Loads content in a version for the content object reference by the given remote id.
     *
     * If no version is given, the method returns the current version
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException - if the content or version with the given remote id does not exist
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the user has no access to read content and in case of un-published content: read versions
     *
     * @param string $remoteId
     * @param array $languages A language filter for fields. If not given all languages are returned
     * @param int $versionNo the version number. If not given the current version is returned
     * @param bool $useAlwaysAvailable Add Main language to \$languages if true (default) and if alwaysAvailable is true
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Content
     */
    public function loadContentByRemoteId(string $remoteId, array $languages = null, ?int $versionNo = null, bool $useAlwaysAvailable = true): APIContent
    {
        $content = $this->internalLoadContentByRemoteId($remoteId, $languages, $versionNo, $useAlwaysAvailable);

        if (!$this->permissionResolver->canUser('content', 'read', $content)) {
            throw new UnauthorizedException('content', 'read', ['remoteId' => $remoteId]);
        }

        if (
            !$content->getVersionInfo()->isPublished()
            && !$this->permissionResolver->canUser('content', 'versionread', $content)
        ) {
            throw new UnauthorizedException('content', 'versionread', ['remoteId' => $remoteId, 'versionNo' => $versionNo]);
        }

        return $content;
    }

    /**
     * Bulk-load Content items by the list of ContentInfo Value Objects.
     *
     * Note: it does not throw exceptions on load, just ignores erroneous Content item.
     * Moreover, since the method works on pre-loaded ContentInfo list, it is assumed that user is
     * allowed to access every Content on the list.
     *
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo[] $contentInfoList
     * @param string[] $languages A language priority, filters returned fields and is used as prioritized language code on
     *                            returned value object. If not given all languages are returned.
     * @param bool $useAlwaysAvailable Add Main language to \$languages if true (default) and if alwaysAvailable is true,
     *                                 unless all languages have been asked for.
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Content[] list of Content items with Content Ids as keys
     */
    public function loadContentListByContentInfo(
        array $contentInfoList,
        array $languages = [],
        bool $useAlwaysAvailable = true
    ): iterable {
        $loadAllLanguages = $languages === Language::ALL;
        $contentIds = [];
        $contentTypeIds = [];
        $translations = $languages;
        foreach ($contentInfoList as $contentInfo) {
            $contentIds[] = $contentInfo->id;
            $contentTypeIds[] = $contentInfo->contentTypeId;
            // Unless we are told to load all languages, we add main language to translations so they are loaded too
            // Might in some case load more languages then intended, but prioritised handling will pick right one
            if (!$loadAllLanguages && $useAlwaysAvailable && $contentInfo->alwaysAvailable) {
                $translations[] = $contentInfo->mainLanguageCode;
            }
        }

        $contentList = [];
        $translations = array_unique($translations);
        $spiContentList = $this->persistenceHandler->contentHandler()->loadContentList(
            $contentIds,
            $translations
        );
        $contentTypeList = $this->repository->getContentTypeService()->loadContentTypeList(
            array_unique($contentTypeIds),
            $languages
        );
        foreach ($spiContentList as $contentId => $spiContent) {
            $contentInfo = $spiContent->versionInfo->contentInfo;
            $contentList[$contentId] = $this->contentDomainMapper->buildContentDomainObject(
                $spiContent,
                $contentTypeList[$contentInfo->contentTypeId],
                $languages,
                $contentInfo->alwaysAvailable ? $contentInfo->mainLanguageCode : null
            );
        }

        return $contentList;
    }

    /**
     * Creates a new content draft assigned to the authenticated user.
     *
     * If a different userId is given in $contentCreateStruct it is assigned to the given user
     * but this required special rights for the authenticated user
     * (this is useful for content staging where the transfer process does not
     * have to authenticate with the user which created the content object in the source server).
     * The user has to publish the draft if it should be visible.
     * In 4.x at least one location has to be provided in the location creation array.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed to create the content in the given location
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException if the provided remoteId exists in the system, required properties on
     *                                                                        struct are missing or invalid, or if multiple locations are under the
     *                                                                        same parent.
     * @throws \eZ\Publish\API\Repository\Exceptions\ContentFieldValidationException if a field in the $contentCreateStruct is not valid,
     *                                                                               or if a required field is missing / set to an empty value.
     * @throws \eZ\Publish\API\Repository\Exceptions\ContentValidationException If field definition does not exist in the ContentType,
     *                                                                          or value is set for non-translatable field in language
     *                                                                          other than main.
     *
     * @param \eZ\Publish\API\Repository\Values\Content\ContentCreateStruct $contentCreateStruct
     * @param \eZ\Publish\API\Repository\Values\Content\LocationCreateStruct[] $locationCreateStructs For each location parent under which a location should be created for the content
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Content - the newly created content draft
     */
    public function createContent(APIContentCreateStruct $contentCreateStruct, array $locationCreateStructs = [], ?array $fieldIdentifiersToValidate = null): APIContent
    {
        if ($contentCreateStruct->mainLanguageCode === null) {
            throw new InvalidArgumentException('$contentCreateStruct', "the 'mainLanguageCode' property must be set");
        }

        if ($contentCreateStruct->contentType === null) {
            throw new InvalidArgumentException('$contentCreateStruct', "the 'contentType' property must be set");
        }

        $contentCreateStruct = clone $contentCreateStruct;

        if ($contentCreateStruct->ownerId === null) {
            $contentCreateStruct->ownerId = $this->permissionResolver->getCurrentUserReference()->getUserId();
        }

        if ($contentCreateStruct->alwaysAvailable === null) {
            $contentCreateStruct->alwaysAvailable = $contentCreateStruct->contentType->defaultAlwaysAvailable ?: false;
        }

        $contentCreateStruct->contentType = $this->repository->getContentTypeService()->loadContentType(
            $contentCreateStruct->contentType->id
        );

        $contentCreateStruct->fields = $this->contentMapper->getFieldsForCreate(
            $contentCreateStruct->fields,
            $contentCreateStruct->contentType
        );

        if (empty($contentCreateStruct->sectionId)) {
            if (isset($locationCreateStructs[0])) {
                $location = $this->repository->getLocationService()->loadLocation(
                    $locationCreateStructs[0]->parentLocationId
                );
                $contentCreateStruct->sectionId = $location->contentInfo->sectionId;
            } else {
                $contentCreateStruct->sectionId = 1;
            }
        }

        if (!$this->permissionResolver->canUser('content', 'create', $contentCreateStruct, $locationCreateStructs)) {
            throw new UnauthorizedException(
                'content',
                'create',
                [
                    'parentLocationId' => isset($locationCreateStructs[0]) ?
                            $locationCreateStructs[0]->parentLocationId :
                            null,
                    'sectionId' => $contentCreateStruct->sectionId,
                ]
            );
        }

        if (!empty($contentCreateStruct->remoteId)) {
            try {
                $this->loadContentByRemoteId($contentCreateStruct->remoteId);

                throw new InvalidArgumentException(
                    '$contentCreateStruct',
                    "Another Content item with remoteId '{$contentCreateStruct->remoteId}' exists"
                );
            } catch (APINotFoundException $e) {
                // Do nothing
            }
        } else {
            $contentCreateStruct->remoteId = $this->contentDomainMapper->getUniqueHash($contentCreateStruct);
        }

        $errors = $this->validate(
            $contentCreateStruct,
            [],
            $fieldIdentifiersToValidate
        );

        if (!empty($errors)) {
            throw new ContentFieldValidationException($errors);
        }

        $spiLocationCreateStructs = $spiLocationCreateStructs = $this->buildSPILocationCreateStructs(
            $locationCreateStructs,
            $contentCreateStruct->contentType
        );

        $languageCodes = $this->contentMapper->getLanguageCodesForCreate($contentCreateStruct);
        $fields = $this->contentMapper->mapFieldsForCreate($contentCreateStruct);

        $fieldValues = [];
        $spiFields = [];
        $inputRelations = [];
        $locationIdToContentIdMapping = [];

        foreach ($contentCreateStruct->contentType->getFieldDefinitions() as $fieldDefinition) {
            /** @var $fieldType \eZ\Publish\Core\FieldType\FieldType */
            $fieldType = $this->fieldTypeRegistry->getFieldType(
                $fieldDefinition->fieldTypeIdentifier
            );

            foreach ($languageCodes as $languageCode) {
                $isEmptyValue = false;
                $valueLanguageCode = $fieldDefinition->isTranslatable ? $languageCode : $contentCreateStruct->mainLanguageCode;
                $isLanguageMain = $languageCode === $contentCreateStruct->mainLanguageCode;

                $fieldValue = $this->contentMapper->getFieldValueForCreate(
                    $fieldDefinition,
                    $fields[$fieldDefinition->identifier][$valueLanguageCode] ?? null
                );

                if ($fieldType->isEmptyValue($fieldValue)) {
                    $isEmptyValue = true;
                }

                $this->relationProcessor->appendFieldRelations(
                    $inputRelations,
                    $locationIdToContentIdMapping,
                    $fieldType,
                    $fieldValue,
                    $fieldDefinition->id
                );
                $fieldValues[$fieldDefinition->identifier][$languageCode] = $fieldValue;

                // Only non-empty value for: translatable field or in main language
                if (
                    (!$isEmptyValue && $fieldDefinition->isTranslatable) ||
                    (!$isEmptyValue && $isLanguageMain)
                ) {
                    $spiFields[] = new SPIField(
                        [
                            'id' => null,
                            'fieldDefinitionId' => $fieldDefinition->id,
                            'type' => $fieldDefinition->fieldTypeIdentifier,
                            'value' => $fieldType->toPersistenceValue($fieldValue),
                            'languageCode' => $languageCode,
                            'versionNo' => null,
                        ]
                    );
                }
            }
        }

        $spiContentCreateStruct = new SPIContentCreateStruct(
            [
                'name' => $this->nameSchemaService->resolve(
                    $contentCreateStruct->contentType->nameSchema,
                    $contentCreateStruct->contentType,
                    $fieldValues,
                    $languageCodes
                ),
                'typeId' => $contentCreateStruct->contentType->id,
                'sectionId' => $contentCreateStruct->sectionId,
                'ownerId' => $contentCreateStruct->ownerId,
                'locations' => $spiLocationCreateStructs,
                'fields' => $spiFields,
                'alwaysAvailable' => $contentCreateStruct->alwaysAvailable,
                'remoteId' => $contentCreateStruct->remoteId,
                'modified' => isset($contentCreateStruct->modificationDate) ? $contentCreateStruct->modificationDate->getTimestamp() : time(),
                'initialLanguageId' => $this->persistenceHandler->contentLanguageHandler()->loadByLanguageCode(
                    $contentCreateStruct->mainLanguageCode
                )->id,
            ]
        );

        $defaultObjectStates = $this->getDefaultObjectStates();

        $this->repository->beginTransaction();
        try {
            $spiContent = $this->persistenceHandler->contentHandler()->create($spiContentCreateStruct);
            $this->relationProcessor->processFieldRelations(
                $inputRelations,
                $spiContent->versionInfo->contentInfo->id,
                $spiContent->versionInfo->versionNo,
                $contentCreateStruct->contentType
            );

            $objectStateHandler = $this->persistenceHandler->objectStateHandler();
            foreach ($defaultObjectStates as $objectStateGroupId => $objectState) {
                $objectStateHandler->setContentState(
                    $spiContent->versionInfo->contentInfo->id,
                    $objectStateGroupId,
                    $objectState->id
                );
            }

            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }

        return $this->contentDomainMapper->buildContentDomainObject(
            $spiContent,
            $contentCreateStruct->contentType
        );
    }

    /**
     * Returns an array of default content states with content state group id as key.
     *
     * @return \eZ\Publish\SPI\Persistence\Content\ObjectState[]
     */
    protected function getDefaultObjectStates(): array
    {
        $defaultObjectStatesMap = [];
        $objectStateHandler = $this->persistenceHandler->objectStateHandler();

        foreach ($objectStateHandler->loadAllGroups() as $objectStateGroup) {
            foreach ($objectStateHandler->loadObjectStates($objectStateGroup->id) as $objectState) {
                // Only register the first object state which is the default one.
                $defaultObjectStatesMap[$objectStateGroup->id] = $objectState;
                break;
            }
        }

        return $defaultObjectStatesMap;
    }

    /**
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException
     *
     * @param \eZ\Publish\API\Repository\Values\Content\LocationCreateStruct[] $locationCreateStructs
     * @param \eZ\Publish\API\Repository\Values\ContentType\ContentType|null $contentType
     *
     * @return \eZ\Publish\SPI\Persistence\Content\Location\CreateStruct[]
     */
    protected function buildSPILocationCreateStructs(
        array $locationCreateStructs,
        ?ContentType $contentType = null
    ): array {
        $spiLocationCreateStructs = [];
        $parentLocationIdSet = [];
        $mainLocation = true;

        foreach ($locationCreateStructs as $locationCreateStruct) {
            if (isset($parentLocationIdSet[$locationCreateStruct->parentLocationId])) {
                throw new InvalidArgumentException(
                    '$locationCreateStructs',
                    "You provided multiple LocationCreateStructs with the same parent Location '{$locationCreateStruct->parentLocationId}'"
                );
            }

            if ($locationCreateStruct->sortField === null) {
                $locationCreateStruct->sortField = $contentType->defaultSortField ?? Location::SORT_FIELD_NAME;
            }
            if ($locationCreateStruct->sortOrder === null) {
                $locationCreateStruct->sortOrder = $contentType->defaultSortOrder ?? Location::SORT_ORDER_ASC;
            }

            $parentLocationIdSet[$locationCreateStruct->parentLocationId] = true;
            $parentLocation = $this->repository->getLocationService()->loadLocation(
                $locationCreateStruct->parentLocationId
            );

            $spiLocationCreateStructs[] = $this->contentDomainMapper->buildSPILocationCreateStruct(
                $locationCreateStruct,
                $parentLocation,
                $mainLocation,
                // For Content draft contentId and contentVersionNo are set in ContentHandler upon draft creation
                null,
                null,
                false
            );

            // First Location in the list will be created as main Location
            $mainLocation = false;
        }

        return $spiLocationCreateStructs;
    }

    /**
     * Updates the metadata.
     *
     * (see {@link ContentMetadataUpdateStruct}) of a content object - to update fields use updateContent
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed to update the content meta data
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException if the remoteId in $contentMetadataUpdateStruct is set but already exists
     *
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo $contentInfo
     * @param \eZ\Publish\API\Repository\Values\Content\ContentMetadataUpdateStruct $contentMetadataUpdateStruct
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Content the content with the updated attributes
     */
    public function updateContentMetadata(ContentInfo $contentInfo, ContentMetadataUpdateStruct $contentMetadataUpdateStruct): APIContent
    {
        $propertyCount = 0;
        foreach ($contentMetadataUpdateStruct as $propertyName => $propertyValue) {
            if (isset($contentMetadataUpdateStruct->$propertyName)) {
                ++$propertyCount;
            }
        }
        if ($propertyCount === 0) {
            throw new InvalidArgumentException(
                '$contentMetadataUpdateStruct',
                'At least one property must be set'
            );
        }

        $loadedContentInfo = $this->loadContentInfo($contentInfo->id);

        if (!$this->permissionResolver->canUser('content', 'edit', $loadedContentInfo)) {
            throw new UnauthorizedException('content', 'edit', ['contentId' => $loadedContentInfo->id]);
        }

        if (isset($contentMetadataUpdateStruct->remoteId)) {
            try {
                $existingContentInfo = $this->loadContentInfoByRemoteId($contentMetadataUpdateStruct->remoteId);

                if ($existingContentInfo->id !== $loadedContentInfo->id) {
                    throw new InvalidArgumentException(
                        '$contentMetadataUpdateStruct',
                        "Another Content item with remoteId '{$contentMetadataUpdateStruct->remoteId}' exists"
                    );
                }
            } catch (APINotFoundException $e) {
                // Do nothing
            }
        }

        $this->repository->beginTransaction();
        try {
            if ($propertyCount > 1 || !isset($contentMetadataUpdateStruct->mainLocationId)) {
                $this->persistenceHandler->contentHandler()->updateMetadata(
                    $loadedContentInfo->id,
                    new SPIMetadataUpdateStruct(
                        [
                            'ownerId' => $contentMetadataUpdateStruct->ownerId,
                            'publicationDate' => isset($contentMetadataUpdateStruct->publishedDate) ?
                                $contentMetadataUpdateStruct->publishedDate->getTimestamp() :
                                null,
                            'modificationDate' => isset($contentMetadataUpdateStruct->modificationDate) ?
                                $contentMetadataUpdateStruct->modificationDate->getTimestamp() :
                                null,
                            'mainLanguageId' => isset($contentMetadataUpdateStruct->mainLanguageCode) ?
                                $this->repository->getContentLanguageService()->loadLanguage(
                                    $contentMetadataUpdateStruct->mainLanguageCode
                                )->id :
                                null,
                            'alwaysAvailable' => $contentMetadataUpdateStruct->alwaysAvailable,
                            'remoteId' => $contentMetadataUpdateStruct->remoteId,
                            'name' => $contentMetadataUpdateStruct->name,
                        ]
                    )
                );
            }

            // Change main location
            if (isset($contentMetadataUpdateStruct->mainLocationId)
                && $loadedContentInfo->mainLocationId !== $contentMetadataUpdateStruct->mainLocationId) {
                $this->persistenceHandler->locationHandler()->changeMainLocation(
                    $loadedContentInfo->id,
                    $contentMetadataUpdateStruct->mainLocationId
                );
            }

            // Republish URL aliases to update always-available flag
            if (isset($contentMetadataUpdateStruct->alwaysAvailable)
                && $loadedContentInfo->alwaysAvailable !== $contentMetadataUpdateStruct->alwaysAvailable) {
                $content = $this->loadContent($loadedContentInfo->id);
                $this->publishUrlAliasesForContent($content, false);
            }

            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }

        return isset($content) ? $content : $this->loadContent($loadedContentInfo->id);
    }

    /**
     * Publishes URL aliases for all locations of a given content.
     *
     * @param \eZ\Publish\API\Repository\Values\Content\Content $content
     * @param bool $updatePathIdentificationString this parameter is legacy storage specific for updating
     *                      ezcontentobject_tree.path_identification_string, it is ignored by other storage engines
     */
    protected function publishUrlAliasesForContent(APIContent $content, bool $updatePathIdentificationString = true): void
    {
        $urlAliasNames = $this->nameSchemaService->resolveUrlAliasSchema($content);
        $locations = $this->repository->getLocationService()->loadLocations(
            $content->getVersionInfo()->getContentInfo()
        );
        $urlAliasHandler = $this->persistenceHandler->urlAliasHandler();
        foreach ($locations as $location) {
            foreach ($urlAliasNames as $languageCode => $name) {
                $urlAliasHandler->publishUrlAliasForLocation(
                    $location->id,
                    $location->parentLocationId,
                    $name,
                    $languageCode,
                    $content->contentInfo->alwaysAvailable,
                    $updatePathIdentificationString ? $languageCode === $content->contentInfo->mainLanguageCode : false
                );
            }
            // archive URL aliases of Translations that got deleted
            $urlAliasHandler->archiveUrlAliasesForDeletedTranslations(
                $location->id,
                $location->parentLocationId,
                $content->versionInfo->languageCodes
            );
        }
    }

    /**
     * Deletes a content object including all its versions and locations including their subtrees.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed to delete the content (in one of the locations of the given content object)
     *
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo $contentInfo
     *
     * @return mixed[] Affected Location Id's
     */
    public function deleteContent(ContentInfo $contentInfo): iterable
    {
        $contentInfo = $this->internalLoadContentInfoById($contentInfo->id);
        $versionInfo = $this->persistenceHandler->contentHandler()->loadVersionInfo(
            $contentInfo->id,
            $contentInfo->currentVersionNo
        );
        $translations = $versionInfo->languageCodes;
        $target = (new Target\Version())->deleteTranslations($translations);

        if (!$this->permissionResolver->canUser('content', 'remove', $contentInfo, [$target])) {
            throw new UnauthorizedException('content', 'remove', ['contentId' => $contentInfo->id]);
        }

        $affectedLocations = [];
        $this->repository->beginTransaction();
        try {
            // Load Locations first as deleting Content also deletes belonging Locations
            $spiLocations = $this->persistenceHandler->locationHandler()->loadLocationsByContent($contentInfo->id);
            $this->persistenceHandler->contentHandler()->deleteContent($contentInfo->id);
            $urlAliasHandler = $this->persistenceHandler->urlAliasHandler();
            foreach ($spiLocations as $spiLocation) {
                $urlAliasHandler->locationDeleted($spiLocation->id);
                $affectedLocations[] = $spiLocation->id;
            }
            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }

        return $affectedLocations;
    }

    /**
     * Creates a draft from a published or archived version.
     *
     * If no version is given, the current published version is used.
     *
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo $contentInfo
     * @param \eZ\Publish\API\Repository\Values\Content\VersionInfo|null $versionInfo
     * @param \eZ\Publish\API\Repository\Values\User\User|null $creator if set given user is used to create the draft - otherwise the current-user is used
     * @param \eZ\Publish\API\Repository\Values\Content\Language|null if not set the draft is created with the initialLanguage code of the source version or if not present with the main language.
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Content - the newly created content draft
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\ForbiddenException
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException if the current-user is not allowed to create the draft
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the current-user is not allowed to create the draft
     */
    public function createContentDraft(
        ContentInfo $contentInfo,
        ?APIVersionInfo $versionInfo = null,
        ?User $creator = null,
        ?Language $language = null
    ): APIContent {
        $contentInfo = $this->loadContentInfo($contentInfo->id);

        if ($versionInfo !== null) {
            // Check that given $contentInfo and $versionInfo belong to the same content
            if ($versionInfo->getContentInfo()->id != $contentInfo->id) {
                throw new InvalidArgumentException(
                    '$versionInfo',
                    'VersionInfo does not belong to the same Content item as the given ContentInfo'
                );
            }

            $versionInfo = $this->loadVersionInfoById($contentInfo->id, $versionInfo->versionNo);

            switch ($versionInfo->status) {
                case VersionInfo::STATUS_PUBLISHED:
                case VersionInfo::STATUS_ARCHIVED:
                    break;

                default:
                    // @todo: throw an exception here, to be defined
                    throw new BadStateException(
                        '$versionInfo',
                        'Cannot create a draft from a draft version'
                    );
            }

            $versionNo = $versionInfo->versionNo;
        } elseif ($contentInfo->published) {
            $versionNo = $contentInfo->currentVersionNo;
        } else {
            // @todo: throw an exception here, to be defined
            throw new BadStateException(
                '$contentInfo',
                'Content is not published. A draft can be created only from a published or archived version.'
            );
        }

        if ($creator === null) {
            $creator = $this->permissionResolver->getCurrentUserReference();
        }

        $fallbackLanguageCode = $versionInfo->initialLanguageCode ?? $contentInfo->mainLanguageCode;
        $languageCode = $language->languageCode ?? $fallbackLanguageCode;

        if (!$this->permissionResolver->canUser(
            'content',
            'edit',
            $contentInfo,
            [
                (new Target\Builder\VersionBuilder())
                    ->changeStatusTo(APIVersionInfo::STATUS_DRAFT)
                    ->build(),
            ]
        )) {
            throw new UnauthorizedException(
                'content',
                'edit',
                ['contentId' => $contentInfo->id]
            );
        }

        $this->repository->beginTransaction();
        try {
            $spiContent = $this->persistenceHandler->contentHandler()->createDraftFromVersion(
                $contentInfo->id,
                $versionNo,
                $creator->getUserId(),
                $languageCode
            );
            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }

        return $this->contentDomainMapper->buildContentDomainObject(
            $spiContent,
            $this->repository->getContentTypeService()->loadContentType(
                $spiContent->versionInfo->contentInfo->contentTypeId
            )
        );
    }

    public function countContentDrafts(?User $user = null): int
    {
        if ($this->permissionResolver->hasAccess('content', 'versionread') === false) {
            return 0;
        }

        return $this->persistenceHandler->contentHandler()->countDraftsForUser(
            $this->resolveUser($user)->getUserId()
        );
    }

    /**
     * Loads drafts for a user.
     *
     * If no user is given the drafts for the authenticated user are returned
     *
     * @param \eZ\Publish\API\Repository\Values\User\User|null $user
     *
     * @return \eZ\Publish\API\Repository\Values\Content\VersionInfo[] Drafts owned by the given user
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException
     */
    public function loadContentDrafts(?User $user = null): iterable
    {
        // throw early if user has absolutely no access to versionread
        if ($this->permissionResolver->hasAccess('content', 'versionread') === false) {
            throw new UnauthorizedException('content', 'versionread');
        }

        $spiVersionInfoList = $this->persistenceHandler->contentHandler()->loadDraftsForUser(
            $this->resolveUser($user)->getUserId()
        );
        $versionInfoList = [];
        foreach ($spiVersionInfoList as $spiVersionInfo) {
            $versionInfo = $this->contentDomainMapper->buildVersionInfoDomainObject($spiVersionInfo);
            // @todo: Change this to filter returned drafts by permissions instead of throwing
            if (!$this->permissionResolver->canUser('content', 'versionread', $versionInfo)) {
                throw new UnauthorizedException('content', 'versionread', ['contentId' => $versionInfo->contentInfo->id]);
            }

            $versionInfoList[] = $versionInfo;
        }

        return $versionInfoList;
    }

    public function loadContentDraftList(?User $user = null, int $offset = 0, int $limit = -1): ContentDraftList
    {
        $list = new ContentDraftList();
        if ($this->permissionResolver->hasAccess('content', 'versionread') === false) {
            return $list;
        }

        $list->totalCount = $this->persistenceHandler->contentHandler()->countDraftsForUser(
            $this->resolveUser($user)->getUserId()
        );
        if ($list->totalCount > 0) {
            $spiVersionInfoList = $this->persistenceHandler->contentHandler()->loadDraftListForUser(
                $this->resolveUser($user)->getUserId(),
                $offset,
                $limit
            );
            foreach ($spiVersionInfoList as $spiVersionInfo) {
                $versionInfo = $this->contentDomainMapper->buildVersionInfoDomainObject($spiVersionInfo);
                if ($this->permissionResolver->canUser('content', 'versionread', $versionInfo)) {
                    $list->items[] = new ContentDraftListItem($versionInfo);
                } else {
                    $list->items[] = new UnauthorizedContentDraftListItem(
                        'content',
                        'versionread',
                        ['contentId' => $versionInfo->contentInfo->id]
                    );
                }
            }
        }

        return $list;
    }

    /**
     * Updates the fields of a draft.
     *
     * @param \eZ\Publish\API\Repository\Values\Content\VersionInfo $versionInfo
     * @param \eZ\Publish\API\Repository\Values\Content\ContentUpdateStruct $contentUpdateStruct
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Content the content draft with the updated fields
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\ContentFieldValidationException if a field in the $contentCreateStruct is not valid,
     *                                                                               or if a required field is missing / set to an empty value.
     * @throws \eZ\Publish\API\Repository\Exceptions\ContentValidationException If field definition does not exist in the ContentType,
     *                                                                          or value is set for non-translatable field in language
     *                                                                          other than main.
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed to update this version
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException if the version is not a draft
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException if a property on the struct is invalid.
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException
     */
    public function updateContent(APIVersionInfo $versionInfo, APIContentUpdateStruct $contentUpdateStruct, ?array $fieldIdentifiersToValidate = null): APIContent
    {
        /** @var $content \eZ\Publish\Core\Repository\Values\Content\Content */
        $content = $this->loadContent(
            $versionInfo->getContentInfo()->id,
            null,
            $versionInfo->versionNo
        );

        $updatedFields = $this->contentMapper->getFieldsForUpdate($contentUpdateStruct->fields, $content);

        if (!$this->repository->getPermissionResolver()->canUser(
            'content',
            'edit',
            $content,
            [
                (new Target\Builder\VersionBuilder())
                    ->updateFields($updatedFields)
                    ->updateFieldsTo(
                        $contentUpdateStruct->initialLanguageCode,
                        $contentUpdateStruct->fields
                    )
                    ->build(),
            ]
        )) {
            throw new UnauthorizedException('content', 'edit', ['contentId' => $content->id]);
        }

        return $this->internalUpdateContent($versionInfo, $contentUpdateStruct, $fieldIdentifiersToValidate);
    }

    /**
     * Updates the fields of a draft without checking the permissions.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\ContentFieldValidationException if a field in the $contentCreateStruct is not valid,
     *                                                                               or if a required field is missing / set to an empty value.
     * @throws \eZ\Publish\API\Repository\Exceptions\ContentValidationException If field definition does not exist in the ContentType,
     *                                                                          or value is set for non-translatable field in language
     *                                                                          other than main.
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException if the version is not a draft
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException if a property on the struct is invalid.
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException
     */
    protected function internalUpdateContent(
        APIVersionInfo $versionInfo,
        APIContentUpdateStruct $contentUpdateStruct,
        ?array $fieldIdentifiersToValidate = null
    ): Content {
        $contentUpdateStruct = clone $contentUpdateStruct;

        /** @var $content \eZ\Publish\Core\Repository\Values\Content\Content */
        $content = $this->internalLoadContentById(
            $versionInfo->getContentInfo()->id,
            null,
            $versionInfo->versionNo
        );

        if (!$content->versionInfo->isDraft()) {
            throw new BadStateException(
                '$versionInfo',
                'The version is not a draft and cannot be updated'
            );
        }

        $errors = $this->validate(
            $contentUpdateStruct,
            ['content' => $content],
            $fieldIdentifiersToValidate
        );

        if (!empty($errors)) {
            throw new ContentFieldValidationException($errors);
        }

        $mainLanguageCode = $content->contentInfo->mainLanguageCode;
        if ($contentUpdateStruct->initialLanguageCode === null) {
            $contentUpdateStruct->initialLanguageCode = $mainLanguageCode;
        }

        $allLanguageCodes = $this->contentMapper->getLanguageCodesForUpdate($contentUpdateStruct, $content);
        $contentLanguageHandler = $this->persistenceHandler->contentLanguageHandler();
        foreach ($allLanguageCodes as $languageCode) {
            $contentLanguageHandler->loadByLanguageCode($languageCode);
        }

        $contentType = $this->repository->getContentTypeService()->loadContentType(
            $content->contentInfo->contentTypeId
        );
        $fields = $this->contentMapper->mapFieldsForUpdate(
            $contentUpdateStruct,
            $contentType,
            $mainLanguageCode
        );

        $fieldValues = [];
        $spiFields = [];
        $inputRelations = [];
        $locationIdToContentIdMapping = [];

        foreach ($contentType->getFieldDefinitions() as $fieldDefinition) {
            $fieldType = $this->fieldTypeRegistry->getFieldType(
                $fieldDefinition->fieldTypeIdentifier
            );

            foreach ($allLanguageCodes as $languageCode) {
                $isCopied = $isEmpty = $isRetained = false;
                $isLanguageNew = !in_array($languageCode, $content->versionInfo->languageCodes);
                $valueLanguageCode = $fieldDefinition->isTranslatable ? $languageCode : $mainLanguageCode;
                $isFieldUpdated = isset($fields[$fieldDefinition->identifier][$valueLanguageCode]);
                $isProcessed = isset($fieldValues[$fieldDefinition->identifier][$valueLanguageCode]);

                if (!$isFieldUpdated && !$isLanguageNew) {
                    $isRetained = true;
                } elseif (!$isFieldUpdated && $isLanguageNew && !$fieldDefinition->isTranslatable) {
                    $isCopied = true;
                }

                $fieldValue = $this->contentMapper->getFieldValueForUpdate(
                    $fields[$fieldDefinition->identifier][$valueLanguageCode] ?? null,
                    $content->getField($fieldDefinition->identifier, $valueLanguageCode),
                    $fieldDefinition,
                    $isLanguageNew
                );

                if ($fieldType->isEmptyValue($fieldValue)) {
                    $isEmpty = true;
                }

                $this->relationProcessor->appendFieldRelations(
                    $inputRelations,
                    $locationIdToContentIdMapping,
                    $fieldType,
                    $fieldValue,
                    $fieldDefinition->id
                );
                $fieldValues[$fieldDefinition->identifier][$languageCode] = $fieldValue;

                if ($isRetained || $isCopied || ($isLanguageNew && $isEmpty) || $isProcessed) {
                    continue;
                }

                $spiFields[] = new SPIField(
                    [
                        'id' => $isLanguageNew ?
                            null :
                            $content->getField($fieldDefinition->identifier, $languageCode)->id,
                        'fieldDefinitionId' => $fieldDefinition->id,
                        'type' => $fieldDefinition->fieldTypeIdentifier,
                        'value' => $fieldType->toPersistenceValue($fieldValue),
                        'languageCode' => $languageCode,
                        'versionNo' => $versionInfo->versionNo,
                    ]
                );
            }
        }

        $spiContentUpdateStruct = new SPIContentUpdateStruct(
            [
                'name' => $this->nameSchemaService->resolveNameSchema(
                    $content,
                    $fieldValues,
                    $allLanguageCodes,
                    $contentType
                ),
                'creatorId' => $contentUpdateStruct->creatorId ?: $this->permissionResolver->getCurrentUserReference()->getUserId(),
                'fields' => $spiFields,
                'modificationDate' => time(),
                'initialLanguageId' => $this->persistenceHandler->contentLanguageHandler()->loadByLanguageCode(
                    $contentUpdateStruct->initialLanguageCode
                )->id,
            ]
        );
        $existingRelations = $this->internalLoadRelations($versionInfo);

        $this->repository->beginTransaction();
        try {
            $spiContent = $this->persistenceHandler->contentHandler()->updateContent(
                $versionInfo->getContentInfo()->id,
                $versionInfo->versionNo,
                $spiContentUpdateStruct
            );
            $this->relationProcessor->processFieldRelations(
                $inputRelations,
                $spiContent->versionInfo->contentInfo->id,
                $spiContent->versionInfo->versionNo,
                $contentType,
                $existingRelations
            );
            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }

        return $this->contentDomainMapper->buildContentDomainObject(
            $spiContent,
            $contentType
        );
    }

    /**
     * Publishes a content version.
     *
     * Publishes a content version and deletes archive versions if they overflow max archive versions.
     * Max archive versions are currently a configuration, but might be moved to be a param of ContentType in the future.
     *
     * @param \eZ\Publish\API\Repository\Values\Content\VersionInfo $versionInfo
     * @param string[] $translations
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Content
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException if the version is not a draft
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException
     */
    public function publishVersion(APIVersionInfo $versionInfo, array $translations = Language::ALL): APIContent
    {
        $content = $this->internalLoadContentById(
            $versionInfo->contentInfo->id,
            null,
            $versionInfo->versionNo
        );

        $targets = [];
        if (!empty($translations)) {
            $targets[] = (new Target\Builder\VersionBuilder())
                ->publishTranslations($translations)
                ->build();
        }

        if (!$this->permissionResolver->canUser(
            'content',
            'publish',
            $content,
            $targets
        )) {
            throw new UnauthorizedException(
                'content', 'publish', ['contentId' => $content->id]
            );
        }

        $this->repository->beginTransaction();
        try {
            $this->copyTranslationsFromPublishedVersion($content->versionInfo, $translations);
            $content = $this->internalPublishVersion($content->getVersionInfo(), null);
            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }

        return $content;
    }

    /**
     * @param \eZ\Publish\API\Repository\Values\Content\VersionInfo $versionInfo
     * @param array $translations
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException
     * @throws \eZ\Publish\API\Repository\Exceptions\ContentFieldValidationException
     * @throws \eZ\Publish\API\Repository\Exceptions\ContentValidationException
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException
     */
    protected function copyTranslationsFromPublishedVersion(APIVersionInfo $versionInfo, array $translations = []): void
    {
        $contendId = $versionInfo->contentInfo->id;

        $currentContent = $this->internalLoadContentById($contendId);
        $currentVersionInfo = $currentContent->versionInfo;

        // Copying occurs only if:
        // - There is published Version
        // - Published version is older than the currently published one unless specific translations are provided.
        if (!$currentVersionInfo->isPublished() ||
            ($versionInfo->versionNo >= $currentVersionInfo->versionNo && empty($translations))) {
            return;
        }

        if (empty($translations)) {
            $languagesToCopy = array_diff(
                $currentVersionInfo->languageCodes,
                $versionInfo->languageCodes
            );
        } else {
            $languagesToCopy = array_diff(
                $currentVersionInfo->languageCodes,
                $translations
            );
        }

        if (empty($languagesToCopy)) {
            return;
        }

        $contentType = $this->repository->getContentTypeService()->loadContentType(
            $currentVersionInfo->contentInfo->contentTypeId
        );

        // Find only translatable fields to update with selected languages
        $updateStruct = $this->newContentUpdateStruct();
        $updateStruct->initialLanguageCode = $versionInfo->initialLanguageCode;

        $contentToPublish = $this->internalLoadContentById($contendId, null, $versionInfo->versionNo);
        $fallbackUpdateStruct = $this->newContentUpdateStruct();

        foreach ($currentContent->getFields() as $field) {
            $fieldDefinition = $contentType->getFieldDefinition($field->fieldDefIdentifier);

            if (!$fieldDefinition->isTranslatable || !\in_array($field->languageCode, $languagesToCopy)) {
                continue;
            }

            $fieldType = $this->fieldTypeRegistry->getFieldType(
                $fieldDefinition->fieldTypeIdentifier
            );

            $newValue = $contentToPublish->getFieldValue(
                $fieldDefinition->identifier,
                $field->languageCode
            );

            $value = $field->value;
            if ($fieldDefinition->isRequired && $fieldType->isEmptyValue($value)) {
                if (!$fieldType->isEmptyValue($fieldDefinition->defaultValue)) {
                    $value = $fieldDefinition->defaultValue;
                } else {
                    $value = $contentToPublish->getFieldValue($field->fieldDefIdentifier, $versionInfo->initialLanguageCode);
                }
                $fallbackUpdateStruct->setField(
                    $field->fieldDefIdentifier,
                    $value,
                    $field->languageCode
                );
                continue;
            }

            if ($newValue !== null
                && $field->value !== null
                && $this->fieldValuesAreEqual($fieldType, $newValue, $field->value)
            ) {
                continue;
            }

            $updateStruct->setField($field->fieldDefIdentifier, $value, $field->languageCode);
        }

        // Nothing to copy, skip update
        if (empty($updateStruct->fields)) {
            return;
        }

        // Do fallback only if content needs to be updated
        foreach ($fallbackUpdateStruct->fields as $fallbackField) {
            $updateStruct->setField($fallbackField->fieldDefIdentifier, $fallbackField->value, $fallbackField->languageCode);
        }

        $this->internalUpdateContent($versionInfo, $updateStruct);
    }

    protected function fieldValuesAreEqual(FieldType $fieldType, Value $value1, Value $value2): bool
    {
        if ($fieldType instanceof Comparable) {
            return $fieldType->valuesEqual($value1, $value2);
        } else {
            @trigger_error(
                \sprintf(
                    'In eZ Platform 2.5 and 3.x %s should implement %s. ' .
                    'Since the 4.0 major release FieldType\Comparable contract will be a part of %s',
                    get_class($fieldType),
                    Comparable::class,
                    FieldType::class
                ),
                E_USER_DEPRECATED
            );

            return $fieldType->toHash($value1) === $fieldType->toHash($value2);
        }
    }

    /**
     * Publishes a content version.
     *
     * Publishes a content version and deletes archive versions if they overflow max archive versions.
     * Max archive versions are currently a configuration, but might be moved to be a param of ContentType in the future.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException if the version is not a draft
     *
     * @param \eZ\Publish\API\Repository\Values\Content\VersionInfo $versionInfo
     * @param int|null $publicationDate If null existing date is kept if there is one, otherwise current time is used.
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Content
     */
    protected function internalPublishVersion(APIVersionInfo $versionInfo, $publicationDate = null)
    {
        if (!$versionInfo->isDraft()) {
            throw new BadStateException('$versionInfo', 'Only versions in draft status can be published.');
        }

        $currentTime = $this->getUnixTimestamp();
        if ($publicationDate === null && $versionInfo->versionNo === 1) {
            $publicationDate = $currentTime;
        }

        $errors = $this->validate(
            $versionInfo, [
                'content' => $this->internalLoadContentById(
                    $versionInfo->getContentInfo()->id,
                    null,
                    $versionInfo->versionNo
                ),
            ]
        );

        if (!empty($errors)) {
            throw new ContentFieldValidationException($errors);
        }

        $contentInfo = $versionInfo->getContentInfo();
        $metadataUpdateStruct = new SPIMetadataUpdateStruct();
        $metadataUpdateStruct->publicationDate = $publicationDate;
        $metadataUpdateStruct->modificationDate = $currentTime;
        $metadataUpdateStruct->isHidden = $contentInfo->isHidden;

        $contentId = $contentInfo->id;
        $spiContent = $this->persistenceHandler->contentHandler()->publish(
            $contentId,
            $versionInfo->versionNo,
            $metadataUpdateStruct
        );

        $content = $this->contentDomainMapper->buildContentDomainObject(
            $spiContent,
            $this->repository->getContentTypeService()->loadContentType(
                $spiContent->versionInfo->contentInfo->contentTypeId
            )
        );

        $this->publishUrlAliasesForContent($content);

        if ($this->settings['remove_archived_versions_on_publish']) {
            $this->deleteArchivedVersionsOverLimit($contentId);
        }

        return $content;
    }

    protected function deleteArchivedVersionsOverLimit(int $contentId): void
    {
        // Delete version archive overflow if any, limit is 0-50 (however 0 will mean 1 if content is unpublished)
        $archiveList = $this->persistenceHandler->contentHandler()->listVersions(
            $contentId,
            APIVersionInfo::STATUS_ARCHIVED,
            100 // Limited to avoid publishing taking to long, besides SE limitations this is why limit is max 50
        );

        $maxVersionArchiveCount = max(0, min(50, $this->settings['default_version_archive_limit']));
        while (!empty($archiveList) && count($archiveList) > $maxVersionArchiveCount) {
            /** @var \eZ\Publish\SPI\Persistence\Content\VersionInfo $archiveVersion */
            $archiveVersion = array_shift($archiveList);
            $this->persistenceHandler->contentHandler()->deleteVersion(
                $contentId,
                $archiveVersion->versionNo
            );
        }
    }

    /**
     * @return int
     */
    protected function getUnixTimestamp(): int
    {
        return time();
    }

    /**
     * Removes the given version.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException if the version is in
     *         published state or is a last version of Content in non draft state
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed to remove this version
     *
     * @param \eZ\Publish\API\Repository\Values\Content\VersionInfo $versionInfo
     */
    public function deleteVersion(APIVersionInfo $versionInfo): void
    {
        $contentHandler = $this->persistenceHandler->contentHandler();

        if ($versionInfo->isPublished()) {
            throw new BadStateException(
                '$versionInfo',
                'The Version is published and cannot be removed'
            );
        }

        if (!$this->permissionResolver->canUser('content', 'versionremove', $versionInfo)) {
            throw new UnauthorizedException(
                'content',
                'versionremove',
                ['contentId' => $versionInfo->contentInfo->id, 'versionNo' => $versionInfo->versionNo]
            );
        }

        $versionList = $contentHandler->listVersions(
            $versionInfo->contentInfo->id,
            null,
            2
        );
        $versionsCount = count($versionList);

        if ($versionsCount === 1 && !$versionInfo->isDraft()) {
            throw new BadStateException(
                '$versionInfo',
                'The Version is the last version of the Content item and cannot be removed'
            );
        }

        $this->repository->beginTransaction();
        try {
            if ($versionsCount === 1) {
                $contentHandler->deleteContent($versionInfo->contentInfo->id);
            } else {
                $contentHandler->deleteVersion(
                    $versionInfo->getContentInfo()->id,
                    $versionInfo->versionNo
                );
            }

            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    /**
     * Loads all versions for the given content.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed to list versions
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException if the given status is invalid
     *
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo $contentInfo
     * @param int|null $status
     *
     * @return \eZ\Publish\API\Repository\Values\Content\VersionInfo[] Sorted by creation date
     */
    public function loadVersions(ContentInfo $contentInfo, ?int $status = null): iterable
    {
        if (!$this->permissionResolver->canUser('content', 'versionread', $contentInfo)) {
            throw new UnauthorizedException('content', 'versionread', ['contentId' => $contentInfo->id]);
        }

        if ($status !== null && !in_array((int)$status, [VersionInfo::STATUS_DRAFT, VersionInfo::STATUS_PUBLISHED, VersionInfo::STATUS_ARCHIVED], true)) {
            throw new InvalidArgumentException(
                'status',
                sprintf(
                    'available statuses are: %d (draft), %d (published), %d (archived), %d given',
                    VersionInfo::STATUS_DRAFT, VersionInfo::STATUS_PUBLISHED, VersionInfo::STATUS_ARCHIVED, $status
                ));
        }

        $spiVersionInfoList = $this->persistenceHandler->contentHandler()->listVersions($contentInfo->id, $status);

        $versions = [];
        foreach ($spiVersionInfoList as $spiVersionInfo) {
            $versionInfo = $this->contentDomainMapper->buildVersionInfoDomainObject($spiVersionInfo);
            if (!$this->permissionResolver->canUser('content', 'versionread', $versionInfo)) {
                throw new UnauthorizedException('content', 'versionread', ['versionId' => $versionInfo->id]);
            }

            $versions[] = $versionInfo;
        }

        return $versions;
    }

    /**
     * Copies the content to a new location. If no version is given,
     * all versions are copied, otherwise only the given version.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed to copy the content to the given location
     *
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo $contentInfo
     * @param \eZ\Publish\API\Repository\Values\Content\LocationCreateStruct $destinationLocationCreateStruct the target location where the content is copied to
     * @param \eZ\Publish\API\Repository\Values\Content\VersionInfo $versionInfo
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Content
     */
    public function copyContent(ContentInfo $contentInfo, LocationCreateStruct $destinationLocationCreateStruct, ?APIVersionInfo $versionInfo = null): APIContent
    {
        $destinationLocation = $this->repository->getLocationService()->loadLocation(
            $destinationLocationCreateStruct->parentLocationId
        );
        if (!$this->permissionResolver->canUser('content', 'create', $contentInfo, [$destinationLocation])) {
            throw new UnauthorizedException(
                'content',
                'create',
                [
                    'parentLocationId' => $destinationLocationCreateStruct->parentLocationId,
                    'sectionId' => $contentInfo->sectionId,
                ]
            );
        }
        if (!$this->permissionResolver->canUser('content', 'manage_locations', $contentInfo, [$destinationLocation])) {
            throw new UnauthorizedException('content', 'manage_locations', ['contentId' => $contentInfo->id]);
        }

        $defaultObjectStates = $this->getDefaultObjectStates();

        $this->repository->beginTransaction();
        try {
            $spiContent = $this->persistenceHandler->contentHandler()->copy(
                $contentInfo->id,
                $versionInfo ? $versionInfo->versionNo : null,
                $this->permissionResolver->getCurrentUserReference()->getUserId()
            );

            $objectStateHandler = $this->persistenceHandler->objectStateHandler();
            foreach ($defaultObjectStates as $objectStateGroupId => $objectState) {
                $objectStateHandler->setContentState(
                    $spiContent->versionInfo->contentInfo->id,
                    $objectStateGroupId,
                    $objectState->id
                );
            }

            $content = $this->internalPublishVersion(
                $this->contentDomainMapper->buildVersionInfoDomainObject($spiContent->versionInfo),
                $spiContent->versionInfo->creationDate
            );

            $this->repository->getLocationService()->createLocation(
                $content->getVersionInfo()->getContentInfo(),
                $destinationLocationCreateStruct
            );
            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }

        return $this->internalLoadContentById($content->id);
    }

    /**
     * Loads all outgoing relations for the given version.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed to read this version
     *
     * @param \eZ\Publish\API\Repository\Values\Content\VersionInfo $versionInfo
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Relation[]
     */
    public function loadRelations(APIVersionInfo $versionInfo): iterable
    {
        if ($versionInfo->isPublished()) {
            $function = 'read';
        } else {
            $function = 'versionread';
        }

        if (!$this->permissionResolver->canUser('content', $function, $versionInfo)) {
            throw new UnauthorizedException('content', $function);
        }

        return $this->internalLoadRelations($versionInfo);
    }

    /**
     * Loads all outgoing relations for the given version without checking the permissions.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Relation[]
     */
    protected function internalLoadRelations(APIVersionInfo $versionInfo): array
    {
        $contentInfo = $versionInfo->getContentInfo();
        $spiRelations = $this->persistenceHandler->contentHandler()->loadRelations(
            $contentInfo->id,
            $versionInfo->versionNo
        );

        /** @var $relations \eZ\Publish\API\Repository\Values\Content\Relation[] */
        $relations = [];
        foreach ($spiRelations as $spiRelation) {
            $destinationContentInfo = $this->internalLoadContentInfoById($spiRelation->destinationContentId);
            if (!$this->permissionResolver->canUser('content', 'read', $destinationContentInfo)) {
                continue;
            }

            $relations[] = $this->contentDomainMapper->buildRelationDomainObject(
                $spiRelation,
                $contentInfo,
                $destinationContentInfo
            );
        }

        return $relations;
    }

    /**
     * {@inheritdoc}
     */
    public function countReverseRelations(ContentInfo $contentInfo): int
    {
        if (!$this->permissionResolver->canUser('content', 'reverserelatedlist', $contentInfo)) {
            return 0;
        }

        return $this->persistenceHandler->contentHandler()->countReverseRelations(
            $contentInfo->id
        );
    }

    /**
     * Loads all incoming relations for a content object.
     *
     * The relations come only from published versions of the source content objects
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed to read this version
     *
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo $contentInfo
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Relation[]
     */
    public function loadReverseRelations(ContentInfo $contentInfo): iterable
    {
        if (!$this->permissionResolver->canUser('content', 'reverserelatedlist', $contentInfo)) {
            throw new UnauthorizedException('content', 'reverserelatedlist', ['contentId' => $contentInfo->id]);
        }

        $spiRelations = $this->persistenceHandler->contentHandler()->loadReverseRelations(
            $contentInfo->id
        );

        $returnArray = [];
        foreach ($spiRelations as $spiRelation) {
            $sourceContentInfo = $this->internalLoadContentInfoById($spiRelation->sourceContentId);
            if (!$this->permissionResolver->canUser('content', 'read', $sourceContentInfo)) {
                continue;
            }

            $returnArray[] = $this->contentDomainMapper->buildRelationDomainObject(
                $spiRelation,
                $sourceContentInfo,
                $contentInfo
            );
        }

        return $returnArray;
    }

    /**
     * {@inheritdoc}
     */
    public function loadReverseRelationList(ContentInfo $contentInfo, int $offset = 0, int $limit = -1): RelationList
    {
        $list = new RelationList();
        if (!$this->repository->getPermissionResolver()->canUser('content', 'reverserelatedlist', $contentInfo)) {
            return $list;
        }

        $list->totalCount = $this->persistenceHandler->contentHandler()->countReverseRelations(
            $contentInfo->id
        );
        if ($list->totalCount > 0) {
            $spiRelationList = $this->persistenceHandler->contentHandler()->loadReverseRelationList(
                $contentInfo->id,
                $offset,
                $limit
            );
            foreach ($spiRelationList as $spiRelation) {
                $sourceContentInfo = $this->internalLoadContentInfoById($spiRelation->sourceContentId);
                if ($this->repository->getPermissionResolver()->canUser('content', 'read', $sourceContentInfo)) {
                    $relation = $this->contentDomainMapper->buildRelationDomainObject(
                        $spiRelation,
                        $sourceContentInfo,
                        $contentInfo
                    );
                    $list->items[] = new RelationListItem($relation);
                } else {
                    $list->items[] = new UnauthorizedRelationListItem(
                        'content',
                        'read',
                        ['contentId' => $sourceContentInfo->id]
                    );
                }
            }
        }

        return $list;
    }

    /**
     * Adds a relation of type common.
     *
     * The source of the relation is the content and version
     * referenced by $versionInfo.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed to edit this version
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException if the version is not a draft
     *
     * @param \eZ\Publish\API\Repository\Values\Content\VersionInfo $sourceVersion
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo $destinationContent the destination of the relation
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Relation the newly created relation
     */
    public function addRelation(APIVersionInfo $sourceVersion, ContentInfo $destinationContent): APIRelation
    {
        $sourceVersion = $this->loadVersionInfoById(
            $sourceVersion->contentInfo->id,
            $sourceVersion->versionNo
        );

        if (!$sourceVersion->isDraft()) {
            throw new BadStateException(
                '$sourceVersion',
                'Relations of type common can only be added to draft versions'
            );
        }

        if (!$this->permissionResolver->canUser('content', 'edit', $sourceVersion)) {
            throw new UnauthorizedException('content', 'edit', ['contentId' => $sourceVersion->contentInfo->id]);
        }

        $sourceContentInfo = $sourceVersion->getContentInfo();

        $this->repository->beginTransaction();
        try {
            $spiRelation = $this->persistenceHandler->contentHandler()->addRelation(
                new SPIRelationCreateStruct(
                    [
                        'sourceContentId' => $sourceContentInfo->id,
                        'sourceContentVersionNo' => $sourceVersion->versionNo,
                        'sourceFieldDefinitionId' => null,
                        'destinationContentId' => $destinationContent->id,
                        'type' => APIRelation::COMMON,
                    ]
                )
            );
            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }

        return $this->contentDomainMapper->buildRelationDomainObject($spiRelation, $sourceContentInfo, $destinationContent);
    }

    /**
     * Removes a relation of type COMMON from a draft.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed edit this version
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException if the version is not a draft
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException if there is no relation of type COMMON for the given destination
     *
     * @param \eZ\Publish\API\Repository\Values\Content\VersionInfo $sourceVersion
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo $destinationContent
     */
    public function deleteRelation(APIVersionInfo $sourceVersion, ContentInfo $destinationContent): void
    {
        $sourceVersion = $this->loadVersionInfoById(
            $sourceVersion->contentInfo->id,
            $sourceVersion->versionNo
        );

        if (!$sourceVersion->isDraft()) {
            throw new BadStateException(
                '$sourceVersion',
                'Relations of type common can only be added to draft versions'
            );
        }

        if (!$this->permissionResolver->canUser('content', 'edit', $sourceVersion)) {
            throw new UnauthorizedException('content', 'edit', ['contentId' => $sourceVersion->contentInfo->id]);
        }

        $spiRelations = $this->persistenceHandler->contentHandler()->loadRelations(
            $sourceVersion->getContentInfo()->id,
            $sourceVersion->versionNo,
            APIRelation::COMMON
        );

        if (empty($spiRelations)) {
            throw new InvalidArgumentException(
                '$sourceVersion',
                'There are no Relations of type COMMON for the given destination'
            );
        }

        // there should be only one relation of type COMMON for each destination,
        // but in case there were ever more then one, we will remove them all
        // @todo: alternatively, throw BadStateException?
        $this->repository->beginTransaction();
        try {
            foreach ($spiRelations as $spiRelation) {
                if ($spiRelation->destinationContentId == $destinationContent->id) {
                    $this->persistenceHandler->contentHandler()->removeRelation(
                        $spiRelation->id,
                        APIRelation::COMMON
                    );
                }
            }
            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    /**
     * Delete Content item Translation from all Versions (including archived ones) of a Content Object.
     *
     * NOTE: this operation is risky and permanent, so user interface should provide a warning before performing it.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException if the specified Translation
     *         is the Main Translation of a Content Item.
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed
     *         to delete the content (in one of the locations of the given Content Item).
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException if languageCode argument
     *         is invalid for the given content.
     *
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo $contentInfo
     * @param string $languageCode
     *
     * @since 6.13
     */
    public function deleteTranslation(ContentInfo $contentInfo, string $languageCode): void
    {
        if ($contentInfo->mainLanguageCode === $languageCode) {
            throw new BadStateException(
                '$languageCode',
                'The provided translation is the main translation of the Content item'
            );
        }

        $translationWasFound = false;
        $this->repository->beginTransaction();
        try {
            $target = (new Target\Builder\VersionBuilder())->translateToAnyLanguageOf([$languageCode])->build();

            foreach ($this->loadVersions($contentInfo) as $versionInfo) {
                if (!$this->permissionResolver->canUser('content', 'remove', $versionInfo, [$target])) {
                    throw new UnauthorizedException(
                        'content',
                        'remove',
                        ['contentId' => $contentInfo->id, 'versionNo' => $versionInfo->versionNo, 'languageCode' => $languageCode]
                    );
                }

                if (!in_array($languageCode, $versionInfo->languageCodes)) {
                    continue;
                }

                $translationWasFound = true;

                // If the translation is the version's only one, delete the version
                if (count($versionInfo->languageCodes) < 2) {
                    $this->persistenceHandler->contentHandler()->deleteVersion(
                        $versionInfo->getContentInfo()->id,
                        $versionInfo->versionNo
                    );
                }
            }

            if (!$translationWasFound) {
                throw new InvalidArgumentException(
                    '$languageCode',
                    sprintf(
                        '%s does not exist in the Content item(id=%d)',
                        $languageCode,
                        $contentInfo->id
                    )
                );
            }

            $this->persistenceHandler->contentHandler()->deleteTranslationFromContent(
                $contentInfo->id,
                $languageCode
            );
            $locationIds = array_map(
                function (Location $location) {
                    return $location->id;
                },
                $this->repository->getLocationService()->loadLocations($contentInfo)
            );
            $this->persistenceHandler->urlAliasHandler()->translationRemoved(
                $locationIds,
                $languageCode
            );
            $this->repository->commit();
        } catch (InvalidArgumentException $e) {
            $this->repository->rollback();
            throw $e;
        } catch (BadStateException $e) {
            $this->repository->rollback();
            throw $e;
        } catch (UnauthorizedException $e) {
            $this->repository->rollback();
            throw $e;
        } catch (Exception $e) {
            $this->repository->rollback();
            // cover generic unexpected exception to fulfill API promise on @throws
            throw new BadStateException('$contentInfo', 'Translation removal failed', $e);
        }
    }

    /**
     * Delete specified Translation from a Content Draft.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException if the specified Translation
     *         is the only one the Content Draft has or it is the main Translation of a Content Object.
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the user is not allowed
     *         to edit the Content (in one of the locations of the given Content Object).
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException if languageCode argument
     *         is invalid for the given Draft.
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException if specified Version was not found
     *
     * @param \eZ\Publish\API\Repository\Values\Content\VersionInfo $versionInfo Content Version Draft
     * @param string $languageCode Language code of the Translation to be removed
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Content Content Draft w/o the specified Translation
     *
     * @since 6.12
     */
    public function deleteTranslationFromDraft(APIVersionInfo $versionInfo, string $languageCode): APIContent
    {
        if (!$versionInfo->isDraft()) {
            throw new BadStateException(
                '$versionInfo',
                'The version is not a draft, so translations cannot be modified. Create a draft before proceeding'
            );
        }

        if ($versionInfo->contentInfo->mainLanguageCode === $languageCode) {
            throw new BadStateException(
                '$languageCode',
                'the specified translation is the main translation of the Content item. Change it before proceeding.'
            );
        }

        if (!$this->permissionResolver->canUser('content', 'edit', $versionInfo->contentInfo)) {
            throw new UnauthorizedException(
                'content', 'edit', ['contentId' => $versionInfo->contentInfo->id]
            );
        }

        if (!in_array($languageCode, $versionInfo->languageCodes)) {
            throw new InvalidArgumentException(
                '$languageCode',
                sprintf(
                    'The version (ContentId=%d, VersionNo=%d) is not translated into %s',
                    $versionInfo->contentInfo->id,
                    $versionInfo->versionNo,
                    $languageCode
                )
            );
        }

        if (count($versionInfo->languageCodes) === 1) {
            throw new BadStateException(
                '$languageCode',
                'The provided translation is the only translation in this version'
            );
        }

        $this->repository->beginTransaction();
        try {
            $spiContent = $this->persistenceHandler->contentHandler()->deleteTranslationFromDraft(
                $versionInfo->contentInfo->id,
                $versionInfo->versionNo,
                $languageCode
            );
            $this->repository->commit();

            return $this->contentDomainMapper->buildContentDomainObject(
                $spiContent,
                $this->repository->getContentTypeService()->loadContentType(
                    $spiContent->versionInfo->contentInfo->contentTypeId
                )
            );
        } catch (APINotFoundException $e) {
            // avoid wrapping expected NotFoundException in BadStateException handled below
            $this->repository->rollback();
            throw $e;
        } catch (Exception $e) {
            $this->repository->rollback();
            // cover generic unexpected exception to fulfill API promise on @throws
            throw new BadStateException('$contentInfo', 'Could not remove the translation', $e);
        }
    }

    /**
     * Hides Content by making all the Locations appear hidden.
     * It does not persist hidden state on Location object itself.
     *
     * Content hidden by this API can be revealed by revealContent API.
     *
     * @see revealContent
     *
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo $contentInfo
     */
    public function hideContent(ContentInfo $contentInfo): void
    {
        if (!$this->permissionResolver->canUser('content', 'hide', $contentInfo)) {
            throw new UnauthorizedException('content', 'hide', ['contentId' => $contentInfo->id]);
        }

        $this->repository->beginTransaction();
        try {
            $this->persistenceHandler->contentHandler()->updateMetadata(
                $contentInfo->id,
                new SPIMetadataUpdateStruct([
                    'isHidden' => true,
                ])
            );
            $locationHandler = $this->persistenceHandler->locationHandler();
            $childLocations = $locationHandler->loadLocationsByContent($contentInfo->id);
            foreach ($childLocations as $childLocation) {
                $locationHandler->setInvisible($childLocation->id);
            }
            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    /**
     * Reveals Content hidden by hideContent API.
     * Locations which were hidden before hiding Content will remain hidden.
     *
     * @see hideContent
     *
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo $contentInfo
     */
    public function revealContent(ContentInfo $contentInfo): void
    {
        if (!$this->permissionResolver->canUser('content', 'hide', $contentInfo)) {
            throw new UnauthorizedException('content', 'hide', ['contentId' => $contentInfo->id]);
        }

        $this->repository->beginTransaction();
        try {
            $this->persistenceHandler->contentHandler()->updateMetadata(
                $contentInfo->id,
                new SPIMetadataUpdateStruct([
                    'isHidden' => false,
                ])
            );
            $locationHandler = $this->persistenceHandler->locationHandler();
            $childLocations = $locationHandler->loadLocationsByContent($contentInfo->id);
            foreach ($childLocations as $childLocation) {
                $locationHandler->setVisible($childLocation->id);
            }
            $this->repository->commit();
        } catch (Exception $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    /**
     * Instantiates a new content create struct object.
     *
     * alwaysAvailable is set to the ContentType's defaultAlwaysAvailable
     *
     * @param \eZ\Publish\API\Repository\Values\ContentType\ContentType $contentType
     * @param string $mainLanguageCode
     *
     * @return \eZ\Publish\API\Repository\Values\Content\ContentCreateStruct
     */
    public function newContentCreateStruct(ContentType $contentType, string $mainLanguageCode): APIContentCreateStruct
    {
        return new ContentCreateStruct(
            [
                'contentType' => $contentType,
                'mainLanguageCode' => $mainLanguageCode,
                'alwaysAvailable' => $contentType->defaultAlwaysAvailable,
            ]
        );
    }

    /**
     * Instantiates a new content meta data update struct.
     *
     * @return \eZ\Publish\API\Repository\Values\Content\ContentMetadataUpdateStruct
     */
    public function newContentMetadataUpdateStruct(): ContentMetadataUpdateStruct
    {
        return new ContentMetadataUpdateStruct();
    }

    /**
     * Instantiates a new content update struct.
     *
     * @return \eZ\Publish\API\Repository\Values\Content\ContentUpdateStruct
     */
    public function newContentUpdateStruct(): APIContentUpdateStruct
    {
        return new ContentUpdateStruct();
    }

    /**
     * @param \eZ\Publish\API\Repository\Values\User\User|null $user
     *
     * @return \eZ\Publish\API\Repository\Values\User\UserReference
     */
    private function resolveUser(?User $user): UserReference
    {
        if ($user === null) {
            $user = $this->permissionResolver->getCurrentUserReference();
        }

        return $user;
    }

    public function validate(
        ValueObject $object,
        array $context = [],
        ?array $fieldIdentifiersToValidate = null
    ): array {
        return $this->contentValidator->validate(
            $object,
            $context,
            $fieldIdentifiersToValidate
        );
    }

    public function find(Filter $filter, ?array $languages = null): ContentList
    {
        $filter = clone $filter;
        if (!empty($languages)) {
            $filter->andWithCriterion(new LanguageCode($languages));
        }

        $permissionCriterion = $this->permissionResolver->getQueryPermissionsCriterion();
        if ($permissionCriterion instanceof Criterion\MatchNone) {
            return new ContentList(0, []);
        }

        if (!$permissionCriterion instanceof Criterion\MatchAll) {
            if (!$permissionCriterion instanceof FilteringCriterion) {
                return new ContentList(0, []);
            }
            $filter->andWithCriterion($permissionCriterion);
        }

        $contentItems = [];
        $contentItemsIterator = $this->contentFilteringHandler->find($filter);
        foreach ($contentItemsIterator as $contentItem) {
            $contentItems[] = $this->contentDomainMapper->buildContentDomainObjectFromPersistence(
                $contentItem->content,
                $contentItem->type,
                $languages,
            );
        }

        return new ContentList($contentItemsIterator->getTotalCount(), $contentItems);
    }
}
