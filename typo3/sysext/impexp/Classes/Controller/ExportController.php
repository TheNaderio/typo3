<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Impexp\Controller;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception as CoreException;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Fluid\View\BackendTemplateView;
use TYPO3\CMS\Impexp\Domain\Repository\PresetRepository;
use TYPO3\CMS\Impexp\Exception\InsufficientUserPermissionsException;
use TYPO3\CMS\Impexp\Exception\MalformedPresetException;
use TYPO3\CMS\Impexp\Exception\PresetNotFoundException;
use TYPO3\CMS\Impexp\Export;

/**
 * Export module controller
 *
 * @internal This class is not considered part of the public TYPO3 API.
 */
class ExportController
{
    protected array $defaultInputData = [
        'excludeDisabled' => 1,
        'preset' => [],
        'external_static' => [
            'tables' => [],
        ],
        'external_ref' => [
            'tables' => [],
        ],
        'pagetree' => [
            'tables' => [],
        ],
        'extension_dep' => [],
        'meta' => [
            'title' => '',
            'description' => '',
            'notes' => '',
        ],
        'record' => [],
        'list' => [],
    ];

    protected IconFactory $iconFactory;
    protected ModuleTemplateFactory $moduleTemplateFactory;
    protected ResponseFactoryInterface $responseFactory;
    protected PresetRepository $presetRepository;

    public function __construct(
        IconFactory $iconFactory,
        ModuleTemplateFactory $moduleTemplateFactory,
        ResponseFactoryInterface $responseFactory,
        PresetRepository $presetRepository
    ) {
        $this->iconFactory = $iconFactory;
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->responseFactory = $responseFactory;
        $this->presetRepository = $presetRepository;
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();

        $id = (int)($parsedBody['id'] ?? $queryParams['id'] ?? 0);
        $permsClause = $backendUser->getPagePermsClause(Permission::PAGE_SHOW);
        $pageInfo = BackendUtility::readPageAccess($id, $permsClause) ?: [];
        if ($pageInfo === []) {
            throw new \RuntimeException("You don't have access to this page.", 1604308206);
        }

        // @todo: Only small parts of tx_impexp can be hand over as GET, e.g. ['list'] and 'id', drop GET of everything else.
        //        Also, there's a clash with id: it can be ['list']'table:id', it can be 'id', it can be tx_impexp['id']. This
        //        should be de-messed somehow.
        $inputDataFromGetPost = $parsedBody['tx_impexp'] ?? $queryParams['tx_impexp'] ?? [];
        $inputData = $this->defaultInputData;
        ArrayUtility::mergeRecursiveWithOverrule($inputData, $inputDataFromGetPost);
        if ($inputData['resetExclude'] ?? false) {
            $inputData['exclude'] = [];
        }
        $inputData['preset']['public'] = (int)($inputData['preset']['public'] ?? 0);

        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $presetAction = $parsedBody['preset'] ?? [];
        $inputData = $this->processPresets($moduleTemplate, $presetAction, $inputData);

        $export = $this->configureExportFromFormData($inputData);
        $export->process();

        if ($inputData['download_export'] ?? false) {
            return $this->getDownload($export);
        }
        $saveFolder = $export->getOrCreateDefaultImportExportFolder();
        if (($inputData['save_export'] ?? false) && ($saveFolder instanceof Folder)) {
            $this->saveExportToFile($moduleTemplate, $export, $saveFolder);
        }
        $inputData['filename'] = $export->getExportFileName();

        $view = GeneralUtility::makeInstance(BackendTemplateView::class);
        $view->setTemplateRootPaths(['EXT:impexp/Resources/Private/Templates']);
        $view->setPartialRootPaths(['EXT:impexp/Resources/Private/Partials']);
        $view->assignMultiple([
            'id' => $id,
            'errors' => $export->getErrorLog(),
            'preview' => $export->renderPreview(),
            'tableSelectOptions' => $this->getTableSelectOptions(['pages']),
            'treeHTML' => $export->getTreeHTML(),
            'levelSelectOptions' => $this->getPageLevelSelectOptions($inputData),
            'records' => $this->getRecordSelectOptions($inputData),
            'tableList' => $this->getSelectableTableList($inputData),
            'externalReferenceTableSelectOptions' => $this->getTableSelectOptions(),
            'externalStaticTableSelectOptions' => $this->getTableSelectOptions(),
            'presetSelectOptions' => $this->presetRepository->getPresets($id),
            'fileName' => '',
            'filetypeSelectOptions' => $this->getFileSelectOptions($export),
            'saveFolder' => ($saveFolder instanceof Folder) ? $saveFolder->getPublicUrl() : '',
            'hasSaveFolder' => true,
            'extensions' => $this->getExtensionList(),
            'inData' => $inputData,
        ]);
        $moduleTemplate->setContent($view->render('Export.html'));
        $moduleTemplate->setModuleName('');
        $moduleTemplate->getDocHeaderComponent()->setMetaInformation($pageInfo);
        return new HtmlResponse($moduleTemplate->renderContent());
    }

    protected function processPresets(ModuleTemplate $moduleTemplate, array $presetAction, array $inputData): array
    {
        if (empty($presetAction)) {
            return $inputData;
        }
        $presetUid = (int)$presetAction['select'];
        try {
            if (isset($presetAction['save'])) {
                if ($presetUid > 0) {
                    // Update existing
                    $this->presetRepository->updatePreset($presetUid, $inputData);
                    $moduleTemplate->addFlashMessage('Preset #' . $presetUid . ' saved!', 'Presets', AbstractMessage::INFO);
                } else {
                    // Insert new
                    $this->presetRepository->createPreset($inputData);
                    $moduleTemplate->addFlashMessage('New preset "' . $inputData['preset']['title'] . '" is created', 'Presets', AbstractMessage::INFO);
                }
            }
            if (isset($presetAction['delete'])) {
                if ($presetUid > 0) {
                    $this->presetRepository->deletePreset($presetUid);
                    $moduleTemplate->addFlashMessage('Preset #' . $presetUid . ' deleted!', 'Presets', AbstractMessage::INFO);
                } else {
                    $moduleTemplate->addFlashMessage('ERROR: No preset selected for deletion.', 'Presets', AbstractMessage::ERROR);
                }
            }
            if (isset($presetAction['load']) || isset($presetAction['merge'])) {
                if ($presetUid > 0) {
                    $presetData = $this->presetRepository->loadPreset($presetUid);
                    if (isset($presetAction['merge'])) {
                        // Merge records
                        if (is_array($presetData['record'] ?? null)) {
                            $inputData['record'] = array_merge((array)$inputData['record'], $presetData['record']);
                        }
                        // Merge lists
                        if (is_array($presetData['list'] ?? null)) {
                            $inputData['list'] = array_merge((array)$inputData['list'], $presetData['list']);
                        }
                        $moduleTemplate->addFlashMessage('Preset #' . $presetUid . ' merged!', 'Presets', AbstractMessage::INFO);
                    } else {
                        $inputData = $presetData;
                        $moduleTemplate->addFlashMessage('Preset #' . $presetUid . ' loaded!', 'Presets', AbstractMessage::INFO);
                    }
                } else {
                    $moduleTemplate->addFlashMessage('ERROR: No preset selected for loading.', 'Presets', AbstractMessage::ERROR);
                }
            }
        } catch (PresetNotFoundException|InsufficientUserPermissionsException|MalformedPresetException $e) {
            $moduleTemplate->addFlashMessage($e->getMessage(), 'Presets', AbstractMessage::ERROR);
        }
        return $inputData;
    }

    protected function configureExportFromFormData(array $inputData): Export
    {
        $export = GeneralUtility::makeInstance(Export::class);
        $export->setExcludeMap((array)($inputData['exclude'] ?? []));
        $export->setSoftrefCfg((array)($inputData['softrefCfg'] ?? []));
        $export->setExtensionDependencies((($inputData['extension_dep'] ?? '') === '') ? [] : (array)$inputData['extension_dep']);
        $export->setShowStaticRelations((bool)($inputData['showStaticRelations'] ?? false));
        $export->setIncludeExtFileResources(!($inputData['excludeHTMLfileResources'] ?? false));
        $export->setExcludeDisabledRecords((bool)($inputData['excludeDisabled'] ?? false));
        if (!empty($inputData['filetype'])) {
            $export->setExportFileType((string)$inputData['filetype']);
        }
        $export->setExportFileName($inputData['filename'] ?? '');
        $export->setRelStaticTables($inputData['external_static']['tables']);
        $export->setRelOnlyTables($inputData['external_ref']['tables']);
        if (isset($inputData['save_export'], $inputData['saveFilesOutsideExportFile']) && $inputData['saveFilesOutsideExportFile'] === '1') {
            $export->setSaveFilesOutsideExportFile(true);
        }
        $export->setTitle($inputData['meta']['title']);
        $export->setDescription($inputData['meta']['description']);
        $export->setNotes($inputData['meta']['notes']);
        $export->setRecord($inputData['record']);
        $export->setList($inputData['list']);
        if (MathUtility::canBeInterpretedAsInteger($inputData['pagetree']['id'] ?? null)) {
            $export->setPid((int)$inputData['pagetree']['id']);
        }
        if (MathUtility::canBeInterpretedAsInteger($inputData['pagetree']['levels'] ?? null)) {
            $export->setLevels((int)$inputData['pagetree']['levels']);
        }
        $export->setTables($inputData['pagetree']['tables']);
        return $export;
    }

    protected function getDownload(Export $export): ResponseInterface
    {
        $fileName = $export->getOrGenerateExportFileNameWithFileExtension();
        $fileContent = $export->render();
        $response = $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Length', (string)strlen($fileContent))
            ->withHeader('Content-Disposition', 'attachment; filename=' . PathUtility::basename($fileName));
        $response->getBody()->write($export->render());
        return $response;
    }

    protected function saveExportToFile(ModuleTemplate $moduleTemplate, Export $export, Folder $saveFolder): void
    {
        $languageService = $this->getLanguageService();
        try {
            $saveFile = $export->saveToFile();
            $saveFileSize = $saveFile->getProperty('size');
            $moduleTemplate->addFlashMessage(
                sprintf($languageService->sL('LLL:EXT:impexp/Resources/Private/Language/locallang.xlf:exportdata_savedInSBytes'), $saveFile->getPublicUrl(), GeneralUtility::formatSize($saveFileSize)),
                $languageService->sL('LLL:EXT:impexp/Resources/Private/Language/locallang.xlf:exportdata_savedFile')
            );
        } catch (CoreException $e) {
            $moduleTemplate->addFlashMessage(
                sprintf($languageService->sL('LLL:EXT:impexp/Resources/Private/Language/locallang.xlf:exportdata_badPathS'), $saveFolder->getPublicUrl()),
                $languageService->sL('LLL:EXT:impexp/Resources/Private/Language/locallang.xlf:exportdata_problemsSavingFile'),
                AbstractMessage::ERROR
            );
        }
    }

    protected function getPageLevelSelectOptions(array $inputData): array
    {
        $languageService = $this->getLanguageService();
        $options = [];
        if (MathUtility::canBeInterpretedAsInteger($inputData['pagetree']['id'] ?? '')) {
            $options = [
                Export::LEVELS_RECORDS_ON_THIS_PAGE => $languageService->sL('LLL:EXT:impexp/Resources/Private/Language/locallang.xlf:makeconfig_tablesOnThisPage'),
                Export::LEVELS_EXPANDED_TREE => $languageService->sL('LLL:EXT:impexp/Resources/Private/Language/locallang.xlf:makeconfig_expandedTree'),
                0 => $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_0'),
                1 => $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_1'),
                2 => $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_2'),
                3 => $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_3'),
                4 => $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_4'),
                Export::LEVELS_INFINITE => $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_infi'),
            ];
        }
        return $options;
    }

    protected function getRecordSelectOptions(array $inputData): array
    {
        $records = [];
        foreach ($inputData['record'] as $tableNameColonUid) {
            [$tableName, $recordUid] = explode(':', $tableNameColonUid);
            if ($record = BackendUtility::getRecordWSOL((string)$tableName, (int)$recordUid)) {
                $records[] = [
                    'icon' => $this->iconFactory->getIconForRecord($tableName, $record, Icon::SIZE_SMALL)->render(),
                    'title' => BackendUtility::getRecordTitle($tableName, $record, true),
                    'tableName' => $tableName,
                    'recordUid' => $recordUid,
                ];
            }
        }
        return $records;
    }

    protected function getSelectableTableList(array $inputData): array
    {
        $backendUser = $this->getBackendUser();
        $languageService = $this->getLanguageService();
        $tableList = [];
        foreach ($inputData['list'] as $reference) {
            $referenceParts = explode(':', $reference);
            $tableName = $referenceParts[0];
            if ($backendUser->check('tables_select', $tableName)) {
                // If the page is actually the root, handle it differently.
                // NOTE: we don't compare integers, because the number comes from the split string above
                if ($referenceParts[1] === '0') {
                    $iconAndTitle = $this->iconFactory->getIcon('apps-pagetree-root', Icon::SIZE_SMALL)->render() . $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'];
                } else {
                    $record = BackendUtility::getRecordWSOL('pages', (int)$referenceParts[1]);
                    $iconAndTitle = $this->iconFactory->getIconForRecord('pages', $record, Icon::SIZE_SMALL)->render()
                        . BackendUtility::getRecordTitle('pages', $record, true);
                }
                $tableList[] = [
                    'iconAndTitle' => sprintf($languageService->sL('LLL:EXT:impexp/Resources/Private/Language/locallang.xlf:makeconfig_tableListEntry'), $tableName, $iconAndTitle),
                    'reference' => $reference,
                ];
            }
        }
        return $tableList;
    }

    protected function getExtensionList(): array
    {
        $loadedExtensions = ExtensionManagementUtility::getLoadedExtensionListArray();
        return array_combine($loadedExtensions, $loadedExtensions);
    }

    protected function getFileSelectOptions(Export $export): array
    {
        $languageService = $this->getLanguageService();
        $fileTypeOptions = [];
        foreach ($export->getSupportedFileTypes() as $supportedFileType) {
            $fileTypeOptions[$supportedFileType] = $languageService->sL('LLL:EXT:impexp/Resources/Private/Language/locallang.xlf:makesavefo_' . $supportedFileType);
        }
        return $fileTypeOptions;
    }

    /**
     * Get a list of all exportable tables - basically all TCA tables. Blacklist some if wanted.
     * Returned array keys are table names, values are "translations".
     */
    protected function getTableSelectOptions(array $excludeList = []): array
    {
        $languageService = $this->getLanguageService();
        $backendUser = $this->getBackendUser();
        $options = [
            '_ALL' => $languageService->sL('LLL:EXT:impexp/Resources/Private/Language/locallang.xlf:ALL_tables'),
        ];
        $availableTables = array_keys($GLOBALS['TCA']);
        foreach ($availableTables as $table) {
            if (!in_array($table, $excludeList, true) && $backendUser->check('tables_select', $table)) {
                $options[$table] = $table;
            }
        }
        natsort($options);
        return $options;
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
