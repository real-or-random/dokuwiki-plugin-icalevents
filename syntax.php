<?php
/**
 * Plugin iCalEvents: Renders an iCalendar file, e.g., as a table.
 *
 * Copyright (C) 2010-2012, 2015-2016
 * Tim Ruffing, Robert Rackl, Elan Ruusamäe, Jannes Drost-Tenfelde
 *
 * This file is part of the DokuWiki iCalEvents plugin.
 *
 * The DokuWiki iCalEvents plugin program is free software:
 * you can redistribute it and/or modify it under the terms of the
 * GNU General Public License version 2 as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * version 2 along with the DokuWiki iCalEvents plugin program.  If
 * not, see <http://www.gnu.org/licenses/gpl-2.0.html>.
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.html GPL2
 * @author     Tim Ruffing <tim@timruffing.de>
 * @author     Robert Rackl <wiki@doogie.de>
 * @author     Elan Ruusamäe <glen@delfi.ee>
 * @author     Jannes Drost-Tenfelde <info@drost-tenfelde.de>
 *
 */

if (!defined('DOKU_PLUGIN'))
    define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

require_once DOKU_PLUGIN . 'syntax.php';

// We require at least PHP 5.5.
// The following base class implements just the basics and an error message for older PHP versions.
// Then we define the actual class by extending the base class depending on the PHP version.

class syntax_plugin_icalevents_base extends DokuWiki_Syntax_Plugin {
    const ERROR_PREFIX = '<br/ >Error in Plugin iCalEvents: ';

    function getType() {
        return 'substition';
    }

    function getPType() {
        return 'block';
    }

    function getSort() {
        // The iCalendar plugin (and older versions of iCalEvents) used 42 here.
        // So we need be stay below 42 to ensure an easy upgrade from iCalendar to iCalEvents.
        return 41;
    }

    function connectTo($mode) {
        // Subpatterns such as (iCalEvents|iCalendar) are not allowed
        // see https://www.dokuwiki.org/devel:parser#subpatterns_not_allowed
        $this->Lexer->addSpecialPattern('(?i:\{\{iCalEvents>.*?\}\})', $mode, 'plugin_icalevents');
        $this->Lexer->addSpecialPattern('(?i:\{\{iCalendar>.*?\}\})', $mode, 'plugin_icalevents');
    }

    function handle($match, $state, $pos, Doku_Handler $handler) {
    }

    function render($mode, Doku_Renderer $renderer, $data) {
        $renderer->doc .= static::ERROR_PREFIX . 'The plugin requires at least PHP 5.5.';
        return false;
    }
}


// An 'require' ensures that older PHP versions do not even try to parse the actual code.
if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
    require 'syntax-impl.php';
} else {
    class syntax_plugin_icalevents extends syntax_plugin_icalevents_base {
    }
}


