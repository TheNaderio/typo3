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

namespace TYPO3\CMS\Scheduler\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\SysLog\Type as SystemLogType;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\BackendTemplateView;
use TYPO3\CMS\Fluid\ViewHelpers\Be\InfoboxViewHelper;
use TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface;
use TYPO3\CMS\Scheduler\CronCommand\NormalizeCommand;
use TYPO3\CMS\Scheduler\Exception\InvalidDateException;
use TYPO3\CMS\Scheduler\ProgressProviderInterface;
use TYPO3\CMS\Scheduler\Scheduler;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TYPO3\CMS\Scheduler\Task\Enumeration\Action;

/**
 * Scheduler backend module.
 *
 * @internal This class is a specific Backend controller implementation and is not considered part of the Public TYPO3 API.
 */
class SchedulerModuleController
{
    protected Action $currentAction;

    public function __construct(
        protected Scheduler $scheduler,
        protected IconFactory $iconFactory,
        protected UriBuilder $uriBuilder,
        protected ModuleTemplateFactory $moduleTemplateFactory,
        protected Context $context,
    ) {
    }

    /**
     * Entry dispatcher method.
     *
     * There are three arguments involved regarding main module routing:
     * * 'submodule': Third level module selection - "scheduler" (list, add, edit), "info", "check"
     * * 'action': Sub module "scheduler" only: add, edit, delete, toggleHidden, ...
     * * 'CMD': Sub module "scheduler" only. "save", "saveclose", "savenew" when adding / editing a task.
     *          A better naming would be "nextAction", but the split button ModuleTemplate and
     *          DocumentSaveActions.ts can not cope with a renaming here and need "CMD".
     */
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $queryParams = $request->getQueryParams();
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        // See if action from main module drop down is given, else fetch from user data and update if needed.
        $backendUser = $this->getBackendUser();
        $storedModuleData = $backendUser->getModuleData('scheduler');
        $requestedSubModule = $queryParams['subModule'] ?? $storedModuleData['subModule'] ?? 'scheduler';
        if (!empty($requestedSubModule) && !in_array($requestedSubModule, ['scheduler', 'info', 'check'], true)) {
            // Reset to 'scheduler list view' if stored moduleData or GET was invalid.
            $requestedSubModule = 'scheduler';
        }
        if (!isset($storedModuleData['subModule']) || $storedModuleData['subModule'] !== $requestedSubModule) {
            $storedModuleData['subModule'] = $requestedSubModule;
            $backendUser->pushModuleData('scheduler', $storedModuleData);
        }
        // Don't further fiddle with backend user module data from here on.
        unset($storedModuleData);

        // 'info' and 'check' submodules have no other action and can be rendered directly.
        if ($requestedSubModule === 'info') {
            return $this->renderInfoView($moduleTemplate);
        }
        if ($requestedSubModule === 'check') {
            return $this->renderCheckView($moduleTemplate);
        }

        // Simple actions from list view.
        if (!empty($parsedBody['action']['toggleHidden'])) {
            $this->toggleDisabledFlag($moduleTemplate, (int)$parsedBody['action']['toggleHidden']);
            return $this->renderListTasksView($moduleTemplate, $request);
        }
        if (!empty($queryParams['action']['delete'])) {
            // @todo: This should be POST only, but modals on button type="submit" don't trigger and buttons in doc header can't do that, either.
            //        Compare with 'toggleHidden' solution above which has no modal.
            $this->deleteTask($moduleTemplate, (int)$queryParams['action']['delete']);
            return $this->renderListTasksView($moduleTemplate, $request);
        }
        if (!empty($queryParams['action']['stop'])) {
            // @todo: Same as above.
            $this->stopTask($moduleTemplate, (int)$queryParams['action']['stop']);
            return $this->renderListTasksView($moduleTemplate, $request);
        }
        if (!empty($parsedBody['execute'])) {
            $this->executeTasks($moduleTemplate, (string)$parsedBody['execute']);
            return $this->renderListTasksView($moduleTemplate, $request);
        }
        if (!empty($parsedBody['scheduleCron'])) {
            $this->scheduleCrons($moduleTemplate, (string)$parsedBody['scheduleCron']);
            return $this->renderListTasksView($moduleTemplate, $request);
        }

        if (($parsedBody['action'] ?? '') === Action::ADD
            && in_array($parsedBody['CMD'], ['save', 'saveclose', 'savenew'], true)
        ) {
            // Received data for adding a new task - validate, persist, render requested 'next' action.
            $isTaskDataValid = $this->isSubmittedTaskDataValid($moduleTemplate, $request, true);
            if (!$isTaskDataValid) {
                return $this->renderAddTaskFormView($moduleTemplate, $request);
            }
            $newTaskUid = $this->createTask($moduleTemplate, $request);
            if ($parsedBody['CMD'] === 'savenew') {
                return $this->renderAddTaskFormView($moduleTemplate, $request);
            }
            if ($parsedBody['CMD'] === 'saveclose') {
                return $this->renderListTasksView($moduleTemplate, $request);
            }
            if ($parsedBody['CMD'] === 'save') {
                return $this->renderEditTaskFormView($moduleTemplate, $request, $newTaskUid);
            }
        }

        if (($parsedBody['action'] ?? '') === Action::EDIT
            && in_array($parsedBody['CMD'], ['save', 'saveclose', 'savenew'], true)
        ) {
            // Received data for updating existing task - validate, persist, render requested 'next' action.
            $isTaskDataValid = $this->isSubmittedTaskDataValid($moduleTemplate, $request, false);
            if (!$isTaskDataValid) {
                return $this->renderEditTaskFormView($moduleTemplate, $request);
            }
            $this->updateTask($moduleTemplate, $request);
            if ($parsedBody['CMD'] === 'savenew') {
                return $this->renderAddTaskFormView($moduleTemplate, $request);
            }
            if ($parsedBody['CMD'] === 'saveclose') {
                return $this->renderListTasksView($moduleTemplate, $request);
            }
            if ($parsedBody['CMD'] === 'save') {
                return $this->renderEditTaskFormView($moduleTemplate, $request);
            }
        }

        // Add new task form / edit existing task form.
        if (($queryParams['action'] ?? '') === Action::ADD) {
            return $this->renderAddTaskFormView($moduleTemplate, $request);
        }
        if (($queryParams['action'] ?? '') === Action::EDIT) {
            return $this->renderEditTaskFormView($moduleTemplate, $request);
        }

        // Render list if no other action kicked in.
        return $this->renderListTasksView($moduleTemplate, $request);
    }

    /**
     * This is (unfortunately) used by additional field providers to distinct between "create new task" and "edit task".
     */
    public function getCurrentAction(): Action
    {
        return $this->currentAction;
    }

    /**
     * Render 'Setup Check' view.
     */
    protected function renderCheckView(ModuleTemplate $moduleTemplate): ResponseInterface
    {
        $languageService = $this->getLanguageService();

        // Display information about last automated run, as stored in the system registry.
        $registry = GeneralUtility::makeInstance(Registry::class);
        $lastRun = $registry->get('tx_scheduler', 'lastRun');
        $lastRunMessageLabel = 'msg.noLastRun';
        $lastRunMessageLabelArguments = [];
        $lastRunSeverity = InfoboxViewHelper::STATE_WARNING;
        if (is_array($lastRun)) {
            if (empty($lastRun['end']) || empty($lastRun['start']) || empty($lastRun['type'])) {
                $lastRunMessageLabel = 'msg.incompleteLastRun';
                $lastRunSeverity = InfoboxViewHelper::STATE_WARNING;
            } else {
                $lastRunMessageLabelArguments = [
                    $lastRun['type'] === 'manual'
                        ? $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:label.manually')
                        : $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:label.automatically'),
                    date($GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'], $lastRun['start']),
                    date($GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'], $lastRun['start']),
                    date($GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'], $lastRun['end']),
                    date($GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'], $lastRun['end']),
                ];
                $lastRunMessageLabel = 'msg.lastRun';
                $lastRunSeverity = InfoboxViewHelper::STATE_INFO;
            }
        }

        // Information about cli script.
        $script = $this->determineExecutablePath();
        $isExecutableMessageLabel = 'msg.cliScriptNotExecutable';
        $isExecutableSeverity = InfoboxViewHelper::STATE_ERROR;
        $composerMode = !$script && Environment::isComposerMode();
        if (!$composerMode) {
            // Check if CLI script is executable or not. Skip this check if running Windows since executable detection
            // is not reliable on this platform, the script will always appear as *not* executable.
            $isExecutable = Environment::isWindows() ? true : ($script && is_executable($script));
            if ($isExecutable) {
                $isExecutableMessageLabel = 'msg.cliScriptExecutable';
                $isExecutableSeverity = InfoboxViewHelper::STATE_OK;
            }
        }

        $view = $this->initializeView();
        $view->assignMultiple([
            'composerMode' => $composerMode,
            'script' => $script,
            'lastRunMessageLabel' => $lastRunMessageLabel,
            'lastRunMessageLabelArguments' => $lastRunMessageLabelArguments,
            'lastRunSeverity' => $lastRunSeverity,
            'isExecutableMessageLabel' => $isExecutableMessageLabel,
            'isExecutableSeverity' => $isExecutableSeverity,
        ]);
        $moduleTemplate->setContent($view->render('CheckScreen'));
        $moduleTemplate->setTitle(
            $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'),
            $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.check')
        );
        $this->addDocHeaderModuleDropDown($moduleTemplate, 'check');
        $this->addDocHeaderHelpButton($moduleTemplate);
        $this->addDocHeaderShortcutButton($moduleTemplate, 'check', $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.check'));
        return new HtmlResponse($moduleTemplate->renderContent());
    }

    /**
     * Render information about available task classes.
     */
    protected function renderInfoView(ModuleTemplate $moduleTemplate): ResponseInterface
    {
        $languageService = $this->getLanguageService();
        $view = $this->initializeView();
        $view->assign('registeredClasses', $this->getRegisteredClasses());
        $moduleTemplate->setContent($view->render('InfoScreen'));
        $moduleTemplate->setTitle(
            $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'),
            $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.info')
        );
        $this->addDocHeaderModuleDropDown($moduleTemplate, 'info');
        $this->addDocHeaderHelpButton($moduleTemplate);
        $this->addDocHeaderShortcutButton($moduleTemplate, 'info', $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.info'));
        return new HtmlResponse($moduleTemplate->renderContent());
    }

    /**
     * Set a task to deleted.
     */
    protected function deleteTask(ModuleTemplate $moduleTemplate, int $taskUid): void
    {
        $languageService = $this->getLanguageService();
        $backendUser = $this->getBackendUser();
        if (!$taskUid > 0) {
            throw new \RuntimeException('Expecting a valid task uid', 1641670374);
        }
        try {
            // Try to fetch the task and delete it
            $task = $this->scheduler->fetchTask($taskUid);
            if ($task->isExecutionRunning()) {
                // If the task is currently running, it may not be deleted
                $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.canNotDeleteRunningTask'), AbstractMessage::ERROR);
            } else {
                if ($this->scheduler->removeTask($task)) {
                    $backendUser->writelog(
                        SystemLogType::EXTENSION,
                        SystemLogDatabaseAction::DELETE,
                        SystemLogErrorClassification::MESSAGE,
                        0,
                        'Scheduler task "%s" (UID: %s, Class: "%s") was deleted',
                        [$task->getTaskTitle(), $task->getTaskUid(), $task->getTaskClassName()]
                    );
                    $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.deleteSuccess'));
                } else {
                    $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.deleteError'));
                }
            }
        } catch (\UnexpectedValueException $e) {
            // The task could not be unserialized, simply update the database record setting it to deleted
            $result = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_scheduler_task')->update('tx_scheduler_task', ['deleted' => 1], ['uid' => $taskUid]);
            if ($result) {
                $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.deleteSuccess'));
            } else {
                $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.deleteError'), AbstractMessage::ERROR);
            }
        } catch (\OutOfBoundsException $e) {
            // The task was not found, for some reason
            $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskNotFound'), $taskUid), AbstractMessage::ERROR);
        }
    }

    /**
     * Clears the registered running executions from the task.
     * Note this doesn't actually stop the running script. It just unmark execution.
     * @todo find a way to really kill the running task.
     */
    protected function stopTask(ModuleTemplate $moduleTemplate, int $taskUid): void
    {
        $languageService = $this->getLanguageService();
        if (!$taskUid > 0) {
            throw new \RuntimeException('Expecting a valid task uid', 1641670375);
        }
        try {
            // Try to fetch the task and stop it
            $task = $this->scheduler->fetchTask($taskUid);
            if ($task->isExecutionRunning()) {
                // If the task is indeed currently running, clear marked executions
                $result = $task->unmarkAllExecutions();
                if ($result) {
                    $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.stopSuccess'));
                } else {
                    $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.stopError'), AbstractMessage::ERROR);
                }
            } else {
                // The task is not running, nothing to unmark
                $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.maynotStopNonRunningTask'), AbstractMessage::WARNING);
            }
        } catch (\OutOfBoundsException $e) {
            $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskNotFound'), $taskUid), AbstractMessage::ERROR);
        } catch (\UnexpectedValueException $e) {
            $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.stopTaskFailed'), $taskUid, $e->getMessage()), AbstractMessage::ERROR);
        }
    }

    /**
     * Toggle the disabled state of a task and register for next execution if task is of type "single execution".
     */
    protected function toggleDisabledFlag(ModuleTemplate $moduleTemplate, int $taskUid): void
    {
        $languageService = $this->getLanguageService();
        if (!$taskUid > 0) {
            throw new \RuntimeException('Expecting a valid task uid to toggle disabled state', 1641670373);
        }
        try {
            $task = $this->scheduler->fetchTask($taskUid);
            // If a disabled single task is enabled again, register it for a single execution at next scheduler run.
            $isTaskQueuedForExecution = $task->getType() === AbstractTask::TYPE_SINGLE;

            // Toggle task state and add a flash message
            $taskName = $this->getHumanReadableTaskName($task);
            $isTaskDisabled = $task->isDisabled();
            if ($isTaskDisabled && $isTaskQueuedForExecution) {
                $task->setDisabled(false);
                $task->registerSingleExecution($this->context->getAspect('date')->get('timestamp'));
                $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskEnabledAndQueuedForExecution'), $taskName, $taskUid));
            } elseif ($isTaskDisabled) {
                $task->setDisabled(false);
                $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskEnabled'), $taskName, $taskUid));
            } else {
                $task->setDisabled(true);
                $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskDisabled'), $taskName, $taskUid));
            }
            $task->save();
        } catch (\OutOfBoundsException $e) {
            $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskNotFound'), $taskUid), AbstractMessage::ERROR);
        } catch (\UnexpectedValueException $e) {
            $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.toggleDisableFailed'), $taskUid, $e->getMessage()), AbstractMessage::ERROR);
        }
    }

    /**
     * Render add task form.
     */
    protected function renderAddTaskFormView(ModuleTemplate $moduleTemplate, ServerRequestInterface $request): ResponseInterface
    {
        $languageService = $this->getLanguageService();
        $registeredClasses = $this->getRegisteredClasses();
        // Class selection can be GET - link and + button in info screen.
        $queryParams = $request->getQueryParams()['tx_scheduler'] ?? [];
        $parsedBody = $request->getParsedBody()['tx_scheduler'] ?? [];
        $currentData = [
            'class' => $parsedBody['class'] ?? $queryParams['class'] ?? key($registeredClasses),
            'disable' => (bool)($parsedBody['disable'] ?? false),
            'task_group' => (int)($parsedBody['task_group'] ?? 0),
            'type' => (int)($parsedBody['type'] ?? AbstractTask::TYPE_RECURRING),
            'start' => $parsedBody['start'] ?? $this->context->getAspect('date')->get('timestamp'),
            'end' => $parsedBody['start'] ?? 0,
            'frequency' => $parsedBody['frequency'] ?? '',
            'multiple' => (bool)($parsedBody['multiple'] ?? false),
            'description' => $parsedBody['description'] ?? '',
        ];

        // Group available tasks by extension name
        $groupedClasses = [];
        foreach ($registeredClasses as $class => $classInfo) {
            $groupedClasses[$classInfo['extension']][$class] = $classInfo;
        }
        ksort($groupedClasses);

        // Additional field provider access $this->getCurrentAction() - Init it for them
        $this->currentAction = new Action(Action::ADD);
        // Get the extra fields to display for each task that needs some.
        $additionalFields = [];
        foreach ($registeredClasses as $class => $registrationInfo) {
            if (!empty($registrationInfo['provider'])) {
                /** @var AdditionalFieldProviderInterface $providerObject */
                $providerObject = GeneralUtility::makeInstance($registrationInfo['provider']);
                if ($providerObject instanceof AdditionalFieldProviderInterface) {
                    // Additional field provider receive form data by reference. But they shouldn't pollute our array here.
                    $parseBodyForProvider = $request->getParsedBody()['tx_scheduler'] ?? [];
                    $fields = $providerObject->getAdditionalFields($parseBodyForProvider, null, $this);
                    if (is_array($fields)) {
                        $additionalFields = $this->addPreparedAdditionalFields($additionalFields, $fields, (string)$class);
                    }
                }
            }
        }

        $view = $this->initializeView();
        $view->assignMultiple([
            'currentData' => $currentData,
            'groupedClasses' => $groupedClasses,
            'registeredTaskGroups' => $this->getRegisteredTaskGroups(),
            'frequencyOptions' => (array)($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['frequencyOptions'] ?? []),
            'additionalFields' => $additionalFields,
            // Adding a group in edit view switches to formEngine. returnUrl is needed to go back to edit view on group record close.
            'returnUrl' => $request->getAttribute('normalizedParams')->getRequestUri(),
        ]);
        $moduleTemplate->setContent($view->render('AddTaskForm'));

        $this->addDocHeaderModuleDropDown($moduleTemplate, 'scheduler');
        $this->addDocHeaderCloseAndSaveButtons($moduleTemplate);
        $this->addDocHeaderHelpButton($moduleTemplate);
        $moduleTemplate->setTitle(
            $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'),
            $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.add')
        );
        $this->addDocHeaderShortcutButton($moduleTemplate, 'scheduler', $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.add'), 'add');
        return new HtmlResponse($moduleTemplate->renderContent());
    }

    /**
     * Render edit task form.
     */
    protected function renderEditTaskFormView(ModuleTemplate $moduleTemplate, ServerRequestInterface $request, ?int $taskUid = null): ResponseInterface
    {
        $languageService = $this->getLanguageService();
        $registeredClasses = $this->getRegisteredClasses();
        $parsedBody = $request->getParsedBody()['tx_scheduler'] ?? [];
        $taskUid = (int)($taskUid ?? $request->getQueryParams()['uid'] ?? $parsedBody['uid'] ?? 0);
        if (empty($taskUid)) {
            throw new \RuntimeException('No valid task uid given to edit task', 1641720929);
        }

        try {
            $taskRecord = $this->scheduler->fetchTaskRecord($taskUid);
        } catch (\OutOfBoundsException $e) {
            // Task not found - removed meanwhile?
            $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskNotFound'), $taskUid), AbstractMessage::ERROR);
            return $this->renderListTasksView($moduleTemplate, $request);
        }

        if (!empty($taskRecord['serialized_executions'])) {
            // If there's a registered execution, the task should not be edited. May happen if a cron started the task meanwhile.
            $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.maynotEditRunningTask'), AbstractMessage::ERROR);
            return $this->renderListTasksView($moduleTemplate, $request);
        }

        /** @var AbstractTask $task */
        $task = unserialize($taskRecord['serialized_task_object']);

        if (!isset($registeredClasses[get_class($task)]) || !$this->scheduler->isValidTaskObject($task)) {
            // The task object is not valid anymore. Add flash message and go back to list view.
            $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.invalidTaskClassEdit'), get_class($task)), AbstractMessage::ERROR);
            return $this->renderListTasksView($moduleTemplate, $request);
        }

        $taskExecution = $task->getExecution();
        $class = get_class($task);
        $taskName = $this->getHumanReadableTaskName($task);
        // If an interval or a cron command is defined, it's a recurring task
        $taskType = (int)($parsedBody['type'] ?? ((empty($taskExecution->getCronCmd()) && empty($taskExecution->getInterval())) ? AbstractTask::TYPE_SINGLE : AbstractTask::TYPE_RECURRING));

        $currentData = [
            'class' => $class,
            'taskName' => $taskName,
            'disable' => (bool)($parsedBody['disable'] ?? $task->isDisabled()),
            'task_group' => (int)($parsedBody['task_group'] ?? $task->getTaskGroup()),
            'type' => $taskType,
            'start' => $parsedBody['start'] ?? $taskExecution->getStart(),
            // End for single execution tasks is always 0
            'end' => $parsedBody['end'] ?? ($taskType === AbstractTask::TYPE_RECURRING ? $taskExecution->getEnd() : 0),
            // Find current frequency field value depending on task type and interval vs. cron command
            'frequency' => $parsedBody['frequency'] ?? ($taskType === AbstractTask::TYPE_RECURRING ? ($taskExecution->getInterval() ?: $taskExecution->getCronCmd()) : ''),
            'multiple' => (bool)($parsedBody['multiple'] ?? $taskExecution->getMultiple()),
            'description' => $parsedBody['description'] ?? $task->getDescription(),
        ];

        // Additional field provider access $this->getCurrentAction() - Init it for them
        $this->currentAction = new Action(Action::EDIT);
        $additionalFields = [];
        if (!empty($registeredClasses[$class]['provider'])) {
            $providerObject = GeneralUtility::makeInstance($registeredClasses[$class]['provider']);
            if ($providerObject instanceof AdditionalFieldProviderInterface) {
                // Additional field provider receive form data by reference. But they shouldn't pollute our array here.
                $parseBodyForProvider = $request->getParsedBody()['tx_scheduler'] ?? [];
                $fields = $providerObject->getAdditionalFields($parseBodyForProvider, $task, $this);
                if (is_array($fields)) {
                    $additionalFields = $this->addPreparedAdditionalFields($additionalFields, $fields, $class);
                }
            }
        }

        $view = $this->initializeView();
        $view->assignMultiple([
            'uid' => $taskUid,
            'action' => 'edit',
            'currentData' => $currentData,
            'registeredTaskGroups' => $this->getRegisteredTaskGroups(),
            'frequencyOptions' => (array)($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['frequencyOptions'] ?? []),
            'additionalFields' => $additionalFields,
            // Adding a group in edit view switches to formEngine. returnUrl is needed to go back to edit view on group record close.
            'returnUrl' => $request->getAttribute('normalizedParams')->getRequestUri(),
        ]);
        $moduleTemplate->setContent($view->render('EditTaskForm'));
        $this->addDocHeaderModuleDropDown($moduleTemplate, 'scheduler');
        $this->addDocHeaderCloseAndSaveButtons($moduleTemplate);
        $this->addDocHeaderHelpButton($moduleTemplate);
        $moduleTemplate->setTitle(
            $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'),
            sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.edit'), $taskName)
        );
        $this->addDocHeaderDeleteButton($moduleTemplate, $taskUid);
        $this->addDocHeaderShortcutButton(
            $moduleTemplate,
            'scheduler',
            sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.edit'), $taskName),
            'edit',
            $taskUid
        );
        return new HtmlResponse($moduleTemplate->renderContent());
    }

    /**
     * Execute a list of tasks.
     */
    protected function executeTasks(ModuleTemplate $moduleTemplate, string $taskUids): void
    {
        $taskUids = GeneralUtility::intExplode(',', $taskUids, true);
        if (empty($taskUids)) {
            throw new \RuntimeException('Expecting a list of task uids to execute', 1641715832);
        }
        // Loop selected tasks and execute.
        $languageService = $this->getLanguageService();
        foreach ($taskUids as $uid) {
            try {
                $task = $this->scheduler->fetchTask($uid);
                $name = $this->getHumanReadableTaskName($task);
                // Try to execute it and report result
                $result = $this->scheduler->executeTask($task);
                if ($result) {
                    $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.executed'), $name));
                } else {
                    $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.notExecuted'), $name), AbstractMessage::ERROR);
                }
                $this->scheduler->recordLastRun('manual');
            } catch (\OutOfBoundsException $e) {
                $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskNotFound'), $uid), AbstractMessage::ERROR);
            } catch (\Exception $e) {
                $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.executionFailed'), $uid, $e->getMessage()), AbstractMessage::ERROR);
            }
        }
    }

    /**
     * Schedule selected tasks to be executed on next cron run
     */
    protected function scheduleCrons(ModuleTemplate $moduleTemplate, string $taskUids): void
    {
        $taskUids = GeneralUtility::intExplode(',', $taskUids, true);
        if (empty($taskUids)) {
            throw new \RuntimeException('Expecting a list of task uids to schedule', 1641715833);
        }
        // Loop selected tasks and register for next cron run.
        $languageService = $this->getLanguageService();
        foreach ($taskUids as $uid) {
            try {
                $task = $this->scheduler->fetchTask($uid);
                $name = $this->getHumanReadableTaskName($task);
                $task->setRunOnNextCronJob(true);
                if ($task->isDisabled()) {
                    $task->setDisabled(false);
                    $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskEnabledAndQueuedForExecution'), $name, $uid));
                } else {
                    $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskQueuedForExecution'), $name, $uid));
                }
                $task->save();
            } catch (\OutOfBoundsException $e) {
                $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskNotFound'), $uid), AbstractMessage::ERROR);
            } catch (\UnexpectedValueException $e) {
                $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.schedulingFailed'), $uid, $e->getMessage()), AbstractMessage::ERROR);
            }
        }
    }

    /**
     * Assemble display of list of scheduled tasks
     */
    protected function renderListTasksView(ModuleTemplate $moduleTemplate, ServerRequestInterface $request): ResponseInterface
    {
        $languageService = $this->getLanguageService();
        $registeredClasses = $this->getRegisteredClasses();
        $schedulerModuleData = $this->getBackendUser()->getModuleData('scheduler') ?? [];

        // Get all registered tasks
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_scheduler_task');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('t.*')
            ->addSelect(
                'g.groupName AS taskGroupName',
                'g.description AS taskGroupDescription',
                'g.uid AS taskGroupId',
                'g.deleted AS isTaskGroupDeleted',
            )
            ->from('tx_scheduler_task', 't')
            ->leftJoin(
                't',
                'tx_scheduler_task_group',
                'g',
                $queryBuilder->expr()->eq('t.task_group', $queryBuilder->quoteIdentifier('g.uid'))
            )
            ->where(
                $queryBuilder->expr()->eq('t.deleted', 0)
            )
            ->orderBy('g.sorting')
            ->executeQuery();

        $taskGroupsWithTasks = [];
        $missingClasses = [];
        while ($row = $result->fetchAssociative()) {
            /** @var AbstractTask $taskObject */
            $taskObject = unserialize($row['serialized_task_object']);
            $taskClass = get_class($taskObject);
            $taskData = [];
            if ($taskClass === \__PHP_Incomplete_Class::class && preg_match('/^O:[0-9]+:"(?P<classname>.+?)"/', $row['serialized_task_object'], $matches) === 1) {
                $taskClass = $matches['classname'];
            }
            $taskData['uid'] = (int)$row['uid'];
            $taskData['class'] = $taskClass;
            $taskData['lastExecutionTime'] = (int)$row['lastexecution_time'];
            $taskData['lastExecutionContext'] = $row['lastexecution_context'];

            if (!isset($registeredClasses[$taskClass]) || !$this->scheduler->isValidTaskObject($taskObject)) {
                $missingClasses[] = $taskData;
                continue;
            }

            if ($taskObject instanceof ProgressProviderInterface) {
                $taskData['progress'] = round((float)$taskObject->getProgress(), 2);
            }
            $taskData['classTitle'] = $registeredClasses[$taskClass]['title'];
            $taskData['classExtension'] = $registeredClasses[$taskClass]['extension'];
            $taskData['additionalInformation'] = $taskObject->getAdditionalInformation();
            $taskData['disabled'] = (bool)$row['disable'];
            $taskData['isRunning'] = !empty($row['serialized_executions']);
            $taskData['nextExecution'] = (int)$row['nextexecution'];
            $taskData['type'] = 'single';
            $taskData['frequency'] = '';
            if ($taskObject->getType() === AbstractTask::TYPE_RECURRING) {
                $taskData['type'] = 'recurring';
                $taskData['frequency'] = $taskObject->getExecution()->getCronCmd() ?: $taskObject->getExecution()->getInterval();
            }
            $taskData['multiple'] = (bool)$taskObject->getExecution()->getMultiple();
            $taskData['lastExecutionFailure'] = false;
            if (!empty($row['lastexecution_failure'])) {
                $taskData['lastExecutionFailure'] = true;
                $exceptionArray = @unserialize($row['lastexecution_failure']);
                $taskData['lastExecutionFailureCode'] = '';
                $taskData['lastExecutionFailureMessage'] = '';
                if (is_array($exceptionArray)) {
                    $taskData['lastExecutionFailureCode'] = $exceptionArray['code'];
                    $taskData['lastExecutionFailureMessage'] = $exceptionArray['message'];
                }
            }

            if (!isset($taskGroupsWithTasks[(int)$row['task_group']])) {
                $taskGroupsWithTasks[(int)$row['task_group']] = [
                    'tasks' => [],
                    'groupName' => $row['taskGroupName'],
                    'groupDescription' => $row['taskGroupDescription'],
                    'taskGroupCollapsed' => (bool)($schedulerModuleData['task-group-' . $row['taskGroupId']] ?? false),
                ];
            }
            $taskGroupsWithTasks[(int)$row['task_group']]['tasks'][] = $taskData;
        }

        $view = $this->initializeView();
        $view->assignMultiple([
            'tasks' => $taskGroupsWithTasks,
            'now' => $this->context->getAspect('date')->get('timestamp'),
            'missingClasses' => $missingClasses,
            'missingClassesCollapsed' => (bool)($schedulerModuleData['task-group-missing'] ?? false),
        ]);
        $moduleTemplate->setContent($view->render('ListTasks'));
        $moduleTemplate->setTitle(
            $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'),
            $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.scheduler')
        );
        $this->addDocHeaderModuleDropDown($moduleTemplate, 'scheduler');
        $this->addDocHeaderHelpButton($moduleTemplate);
        $this->addDocHeaderReloadButton($moduleTemplate);
        if (!empty($registeredClasses)) {
            $this->addDocHeaderAddButton($moduleTemplate);
        }
        $this->addDocHeaderShortcutButton($moduleTemplate, 'scheduler', $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.scheduler'));
        return new HtmlResponse($moduleTemplate->renderContent());
    }

    protected function isSubmittedTaskDataValid(ModuleTemplate $moduleTemplate, ServerRequestInterface $request, bool $isNewTask): bool
    {
        $languageService = $this->getLanguageService();
        $parsedBody = $request->getParsedBody()['tx_scheduler'] ?? [];
        $type = (int)($parsedBody['type'] ?? 0);
        $startTime = $parsedBody['start'] ?? 0;
        $endTime = $parsedBody['end'] ?? 0;
        $result = true;
        $taskClass = '';
        if ($isNewTask) {
            $taskClass = $parsedBody['class'] ?? '';
            if (!class_exists($taskClass)) {
                $result = false;
                $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.noTaskClassFound'), AbstractMessage::ERROR);
            }
        } else {
            try {
                $taskUid = (int)($parsedBody['uid'] ?? 0);
                $task = $this->scheduler->fetchTask($taskUid);
                $taskClass = get_class($task);
            } catch (\OutOfBoundsException|\UnexpectedValueException $e) {
                $result = false;
                $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.taskNotFound'), $taskUid), AbstractMessage::ERROR);
            }
        }
        if ($type !== AbstractTask::TYPE_SINGLE && $type !== AbstractTask::TYPE_RECURRING) {
            $result = false;
            $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.invalidTaskType'), AbstractMessage::ERROR);
        }
        if (empty($startTime)) {
            $result = false;
            $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.noStartDate'), AbstractMessage::ERROR);
        } else {
            try {
                $startTime = $this->getTimestampFromDateString($startTime);
            } catch (InvalidDateException $e) {
                $result = false;
                $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.invalidStartDate'), AbstractMessage::ERROR);
            }
        }
        if ($type === AbstractTask::TYPE_RECURRING && !empty($endTime)) {
            try {
                $endTime = $this->getTimestampFromDateString($endTime);
            } catch (InvalidDateException $e) {
                $result = false;
                $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.invalidStartDate'), AbstractMessage::ERROR);
            }
        }
        if ($type === AbstractTask::TYPE_RECURRING && $endTime < $startTime) {
            $result = false;
            $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.endDateSmallerThanStartDate'), AbstractMessage::ERROR);
        }
        if ($type === AbstractTask::TYPE_RECURRING) {
            if (empty(trim($parsedBody['frequency']))) {
                $result = false;
                $this->addMessage($moduleTemplate, $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.noFrequency'), AbstractMessage::ERROR);
            } elseif (!is_numeric(trim($parsedBody['frequency']))) {
                try {
                    NormalizeCommand::normalize(trim($parsedBody['frequency']));
                } catch (\InvalidArgumentException $e) {
                    $result = false;
                    $this->addMessage($moduleTemplate, sprintf($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.frequencyError'), $e->getMessage(), $e->getCode()), AbstractMessage::ERROR);
                }
            }
        }
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][$taskClass]['additionalFields'])) {
            /** @var AdditionalFieldProviderInterface $provider */
            $provider = GeneralUtility::makeInstance($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][$taskClass]['additionalFields']);
            if ($provider instanceof AdditionalFieldProviderInterface) {
                // Providers should add messages for failed validations on their own.
                $result = $result && $provider->validateAdditionalFields($parsedBody, $this);
            }
        }
        return $result;
    }

    /**
     * Create a new task and persist. Return its new uid.
     */
    protected function createTask(ModuleTemplate $moduleTemplate, ServerRequestInterface $request): int
    {
        /** @var AbstractTask $task */
        $task = GeneralUtility::makeInstance($request->getParsedBody()['tx_scheduler']['class']);
        $task = $this->setTaskDataFromRequest($task, $request);
        if (!$this->scheduler->addTask($task)) {
            throw new \RuntimeException('Unable to add task. Possible database error', 1641720169);
        }
        $this->getBackendUser()->writelog(
            SystemLogType::EXTENSION,
            SystemLogDatabaseAction::INSERT,
            SystemLogErrorClassification::MESSAGE,
            0,
            'Scheduler task "%s" (UID: %s, Class: "%s") was added',
            [$task->getTaskTitle(), $task->getTaskUid(), $task->getTaskClassName()]
        );
        $this->addMessage($moduleTemplate, $this->getLanguageService()->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.addSuccess'));
        return $task->getTaskUid();
    }

    /**
     * Update data of an existing task.
     */
    protected function updateTask(ModuleTemplate $moduleTemplate, ServerRequestInterface $request): void
    {
        $task = $this->scheduler->fetchTask($request->getParsedBody()['tx_scheduler']['uid']);
        $task = $this->setTaskDataFromRequest($task, $request);
        $this->scheduler->saveTask($task);
        $this->getBackendUser()->writelog(
            SystemLogType::EXTENSION,
            SystemLogDatabaseAction::UPDATE,
            SystemLogErrorClassification::MESSAGE,
            0,
            'Scheduler task "%s" (UID: %s, Class: "%s") was updated',
            [$task->getTaskTitle(), $task->getTaskUid(), $task->getTaskClassName()]
        );
        $this->addMessage($moduleTemplate, $this->getLanguageService()->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.updateSuccess'));
    }

    protected function setTaskDataFromRequest(AbstractTask $task, ServerRequestInterface $request): AbstractTask
    {
        $parsedBody = $request->getParsedBody()['tx_scheduler'];
        if ((int)$parsedBody['type'] === AbstractTask::TYPE_SINGLE) {
            $task->registerSingleExecution($this->getTimestampFromDateString($parsedBody['start']));
        } else {
            $task->registerRecurringExecution(
                $this->getTimestampFromDateString($parsedBody['start']),
                is_numeric($parsedBody['frequency']) ? (int)$parsedBody['frequency'] : 0,
                !empty($parsedBody['end'] ?? '') ? $this->getTimestampFromDateString($parsedBody['end']) : 0,
                (bool)($parsedBody['multiple'] ?? false),
                !is_numeric($parsedBody['frequency']) ? $parsedBody['frequency'] : '',
            );
        }
        $task->setDisabled($parsedBody['disable'] ?? false);
        $task->setDescription($parsedBody['description'] ?? '');
        $task->setTaskGroup((int)($parsedBody['task_group'] ?? 0));
        $taskClass = get_class($task);
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][$taskClass]['additionalFields'])) {
            /** @var AdditionalFieldProviderInterface $provider */
            $provider = GeneralUtility::makeInstance($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][$taskClass]['additionalFields']);
            if ($provider instanceof AdditionalFieldProviderInterface) {
                $provider->saveAdditionalFields($parsedBody, $task);
            }
        }
        return $task;
    }

    /**
     * Convert input to DateTime and retrieve timestamp.
     *
     * @throws InvalidDateException
     */
    protected function getTimestampFromDateString(string $input): int
    {
        if (is_numeric($input)) {
            // Already looks like a timestamp
            return (int)$input;
        }
        try {
            // Convert from ISO 8601 dates
            $dateTime = new \DateTime($input);
            $value = $dateTime->getTimestamp();
            if ($value !== 0) {
                $value -= (int)date('Z', $value);
            }
        } catch (\Exception $e) {
            throw new InvalidDateException($e->getMessage(), 1641717510);
        }
        return $value;
    }

    /**
     * This method fetches a list of all classes that have been registered with the Scheduler
     * For each item the following information is provided, as an associative array:
     *
     * ['extension'] => Key of the extension which provides the class
     * ['filename'] => Path to the file containing the class
     * ['title'] => String (possibly localized) containing a human-readable name for the class
     * ['provider'] => Name of class that implements the interface for additional fields, if necessary
     *
     * The name of the class itself is used as the key of the list array
     */
    protected function getRegisteredClasses(): array
    {
        $languageService = $this->getLanguageService();
        $list = [];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'] ?? [] as $class => $registrationInformation) {
            $title = isset($registrationInformation['title']) ? $languageService->sL($registrationInformation['title']) : '';
            $description = isset($registrationInformation['description']) ? $languageService->sL($registrationInformation['description']) : '';
            $list[$class] = [
                'extension' => $registrationInformation['extension'],
                'title' => $title,
                'description' => $description,
                'provider' => $registrationInformation['additionalFields'] ?? '',
            ];
        }
        return $list;
    }

    /**
     * Fetch list of all task groups.
     */
    protected function getRegisteredTaskGroups(): array
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_scheduler_task_group')
            ->select('*')
            ->from('tx_scheduler_task_group')
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Prepared additional fields from field providers for rendering.
     */
    protected function addPreparedAdditionalFields(array $currentAdditionalFields, array $newAdditionalFields, string $class): array
    {
        foreach ($newAdditionalFields as $fieldID => $fieldInfo) {
            $currentAdditionalFields[] = [
                'class' => $class,
                'fieldID' => $fieldID,
                'htmlClassName' => strtolower(str_replace('\\', '-', $class)),
                'code' => $fieldInfo['code'] ?? '',
                'cshKey' => $fieldInfo['cshKey'] ?? '',
                'cshLabel' => $fieldInfo['cshLabel'] ?? '',
                'langLabel' => $fieldInfo['label'] ?? '',
                'browser' => $fieldInfo['browser'] ?? '',
                'pageTitle' => $fieldInfo['pageTitle'] ?? '',
            ];
        }
        return $currentAdditionalFields;
    }

    protected function addDocHeaderModuleDropDown(ModuleTemplate $moduleTemplate, string $activeEntry): void
    {
        $languageService = $this->getLanguageService();
        $menu = $moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('SchedulerJumpMenu');
        foreach (['scheduler', 'check', 'info'] as $entry) {
            $item = $menu->makeMenuItem()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute('system_txschedulerM1', ['subModule' => $entry]))
                ->setTitle($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.' . $entry));
            if ($entry === $activeEntry) {
                $item->setActive(true);
            }
            $menu->addMenuItem($item);
        }
        $moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);
    }

    protected function addDocHeaderHelpButton(ModuleTemplate $moduleTemplate): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $helpButton = $buttonBar->makeHelpButton()
            ->setModuleName('_MOD_system_txschedulerM1')
            ->setFieldName('');
        $buttonBar->addButton($helpButton);
    }

    protected function addDocHeaderReloadButton(ModuleTemplate $moduleTemplate): void
    {
        $languageService = $this->getLanguageService();
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $reloadButton = $buttonBar->makeLinkButton()
            ->setTitle($languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.reload'))
            ->setIcon($this->iconFactory->getIcon('actions-refresh', Icon::SIZE_SMALL))
            ->setHref($this->uriBuilder->buildUriFromRoute('system_txschedulerM1'));
        $buttonBar->addButton($reloadButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);
    }

    protected function addDocHeaderAddButton(ModuleTemplate $moduleTemplate): void
    {
        $languageService = $this->getLanguageService();
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $addButton = $buttonBar->makeLinkButton()
            ->setTitle($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:function.add'))
            ->setIcon($this->iconFactory->getIcon('actions-add', Icon::SIZE_SMALL))
            ->setHref($this->uriBuilder->buildUriFromRoute('system_txschedulerM1', ['action' => 'add']));
        $buttonBar->addButton($addButton, ButtonBar::BUTTON_POSITION_LEFT, 2);
    }

    protected function addDocHeaderCloseAndSaveButtons(ModuleTemplate $moduleTemplate): void
    {
        $languageService = $this->getLanguageService();
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $closeButton = $buttonBar->makeLinkButton()
            ->setTitle($languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:cancel'))
            ->setIcon($this->iconFactory->getIcon('actions-close', Icon::SIZE_SMALL))
            ->setHref($this->uriBuilder->buildUriFromRoute('system_txschedulerM1'));
        $buttonBar->addButton($closeButton, ButtonBar::BUTTON_POSITION_LEFT, 2);
        $saveButtonDropdown = $buttonBar->makeSplitButton();
        $saveButton = $buttonBar->makeInputButton()
            ->setName('CMD')
            ->setValue('save')
            ->setForm('tx_scheduler_form')
            ->setIcon($this->iconFactory->getIcon('actions-document-save', Icon::SIZE_SMALL))
            ->setTitle($languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:save'));
        $saveButtonDropdown->addItem($saveButton);
        $saveAndNewButton = $buttonBar->makeInputButton()
            ->setName('CMD')
            ->setValue('savenew')
            ->setForm('tx_scheduler_form')
            ->setIcon($this->iconFactory->getIcon('actions-document-save-new', Icon::SIZE_SMALL))
            ->setTitle($languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:label.saveAndCreateNewTask'));
        $saveButtonDropdown->addItem($saveAndNewButton);
        $saveAndCloseButton = $buttonBar->makeInputButton()
            ->setName('CMD')
            ->setValue('saveclose')
            ->setForm('tx_scheduler_form')
            ->setIcon($this->iconFactory->getIcon('actions-document-save-close', Icon::SIZE_SMALL))
            ->setTitle($languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:saveAndClose'));
        $saveButtonDropdown->addItem($saveAndCloseButton);
        $buttonBar->addButton($saveButtonDropdown, ButtonBar::BUTTON_POSITION_LEFT, 3);
    }

    protected function addDocHeaderDeleteButton(ModuleTemplate $moduleTemplate, int $taskUid): void
    {
        $languageService = $this->getLanguageService();
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $deleteButton = $buttonBar->makeLinkButton()
            ->setHref($this->uriBuilder->buildUriFromRoute('system_txschedulerM1', ['action' => ['delete' => $taskUid]]))
            ->setClasses('t3js-modal-trigger')
            ->setDataAttributes([
                'severity' => 'warning',
                'title' => $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:delete'),
                'button-close-text' => $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:cancel'),
                'bs-content' => $languageService->sL('LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:msg.delete'),
            ])
            ->setIcon($this->iconFactory->getIcon('actions-edit-delete', Icon::SIZE_SMALL))
            ->setTitle($languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:delete'));
        $buttonBar->addButton($deleteButton, ButtonBar::BUTTON_POSITION_LEFT, 4);
    }

    protected function addDocHeaderShortcutButton(ModuleTemplate $moduleTemplate, string $moduleMenuIdentifier, string $name, string $action = '', int $taskUid = 0): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $shortcutArguments = ['subModule' => $moduleMenuIdentifier];
        if ($action) {
            $shortcutArguments['action'] = $action;
        }
        if ($taskUid) {
            $shortcutArguments['uid'] = $taskUid;
        }
        $shortcutButton = $buttonBar->makeShortcutButton()
            ->setRouteIdentifier('system_txschedulerM1')
            ->setDisplayName($name)
            ->setArguments($shortcutArguments);
        $buttonBar->addButton($shortcutButton);
    }

    private function determineExecutablePath(): ?string
    {
        if (!Environment::isComposerMode()) {
            return GeneralUtility::getFileAbsFileName('EXT:core/bin/typo3');
        }
        $composerJsonFile = getenv('TYPO3_PATH_COMPOSER_ROOT') . '/composer.json';
        if (!file_exists($composerJsonFile) || !($jsonContent = file_get_contents($composerJsonFile))) {
            return null;
        }
        $jsonConfig = @json_decode($jsonContent, true);
        if (empty($jsonConfig) || !is_array($jsonConfig)) {
            return null;
        }
        $vendorDir = trim($jsonConfig['config']['vendor-dir'] ?? 'vendor', '/');
        $binDir = trim($jsonConfig['config']['bin-dir'] ?? $vendorDir . '/bin', '/');
        return sprintf('%s/%s/typo3', getenv('TYPO3_PATH_COMPOSER_ROOT'), $binDir);
    }

    protected function getHumanReadableTaskName(AbstractTask $task): string
    {
        $class = get_class($task);
        $registeredClasses = $this->getRegisteredClasses();
        if (!array_key_exists($class, $registeredClasses)) {
            throw new \RuntimeException('Class ' . $class . ' not found in list of registered task classes', 1641658569);
        }
        return $registeredClasses[$class]['title'] . ' (' . $registeredClasses[$class]['extension'] . ')';
    }

    /**
     * Add a flash message to the flash message queue of this module.
     */
    protected function addMessage(ModuleTemplate $moduleTemplate, string $message, int $severity = AbstractMessage::OK): void
    {
        $moduleTemplate->addFlashMessage($message, '', $severity);
    }

    protected function initializeView(): BackendTemplateView
    {
        $view = GeneralUtility::makeInstance(BackendTemplateView::class);
        $view->setPartialRootPaths(['EXT:scheduler/Resources/Private/Partials']);
        $view->setTemplateRootPaths(['EXT:scheduler/Resources/Private/Templates']);
        $view->assign('dateFormat', [
            'day' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? 'd-m-y',
            'time' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'] ?? 'H:i',
        ]);
        return $view;
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
