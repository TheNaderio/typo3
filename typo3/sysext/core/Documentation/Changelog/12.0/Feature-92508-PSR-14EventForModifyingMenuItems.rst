.. include:: ../../Includes.txt

=======================================================
Feature: #92508 - PSR-14 Event for modifying menu items
=======================================================

See :issue:`92508`

Description
===========

A new PSR-14 Event :php:`TYPO3\CMS\Frontend\Event\FilterMenuItemsEvent` has been
introduced which serves as a more powerful and flexible alternative
for the now removed hook
:php:`$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['cms/tslib/class.tslib_menu.php']['filterMenuPages']`.

The new PSR-14 Event has a variety of properties and getters, along with
:php:`->getFilteredMenuItems()` and :php:`->setFilteredMenuItems()`. Those
methods can be used to change the items of a menu, which has been generated
with :typoscript:`HMENU`.

Impact
======

The main advantage of the PSR-14 Event is that it is fired after TYPO3 has
filtered all menu items. The menu can then be adjusted by adding, removing
or modifing the menu items. Also changing the order is possible.

Additionally, more information about the currently rendered menu, such as the
menu items which were filtered out, is available in the PSR-14 Event.

.. index:: Frontend, ext:frontend
