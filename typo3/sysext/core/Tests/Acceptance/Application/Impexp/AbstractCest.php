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

namespace TYPO3\CMS\Core\Tests\Acceptance\Application\Impexp;

use TYPO3\CMS\Core\Tests\Acceptance\Support\ApplicationTester;

/**
 * Common test helper functions
 */
abstract class AbstractCest
{
    protected string $contextMenuMore = 'li.list-group-item-submenu';
    protected string $contextMenuExport = '[data-callback-action=exportT3d]';
    protected string $contextMenuImport = '[data-callback-action=importT3d]';

    protected function selectInContextMenu(ApplicationTester $I, array $path): void
    {
        foreach ($path as $depth => $selector) {
            $contextMenuId = sprintf('#contentMenu%d', $depth);
            $I->waitForElementVisible($contextMenuId, 5);
            $I->click($selector, $contextMenuId);
        }
    }

    protected function waitForAjaxRequestToFinish(ApplicationTester $I): void
    {
        $I->waitForJS('return $.active == 0;', 10);
        // sometimes rendering is still slower that ajax being finished.
        $I->wait(0.5);
    }
}
