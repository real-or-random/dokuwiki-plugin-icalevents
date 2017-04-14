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

// We require at least PHP 5.5.
if (version_compare(PHP_VERSION, '5.5.0') < 0)
    return;

// must be run within Dokuwiki
if (!defined('DOKU_INC'))
    die();

require_once DOKU_INC . 'inc/parser/renderer.php';
require_once __DIR__ . '/vendor/autoload.php';

// This is a minimal renderer plugin. It ignores everything except
// invocations of our syntax plugin component. These invocations are
// handled to output VEVENTs.

class renderer_plugin_icalevents extends Doku_Renderer {
    public $info = array(
        // no caching, because the cache does not honor the UID parameter
        'cache' => false,
        // no table of contents
        'toc'   => false
    );

    // Already output UIDs.
    // This is necessary to avoid duplicate VEVENTs in the output.
    private $seenUids = array();

    function getFormat() {
        return 'icalevents';
    }

    function plugin($name, $data, $state = '', $match = '') {
        // filter syntax plugins that are not our own syntax plugin
        if ($name == 'icalevents') {
            return parent::plugin($name, $data, $state, $match);
        }
    }

    function document_start() {
        global $ID;

        $filename =  SafeFN::encode(strtr($ID, '/:', '--')) . '.ics';
        $headers = array(
            'Content-Type:' => 'text/calendar',
            'Content-Disposition:' => 'attachment; filename=' . $filename
        );
        p_set_metadata($ID, array('format' => array('icalevents' => $headers)));

        $this->doc = "BEGIN:VCALENDAR\r\n";
        $this->doc .= "PRODID: -//DokuWiki//NONSGML Plugin iCalEvents//EN" . "\r\n";
        $this->doc .= "VERSION:2.0\r\n";
    }

    function document_end() {
        $this->doc .= "END:VCALENDAR\r\n";
    }

    function hasSeenUid($uid) {
        return in_array($uid, $this->seenUids);
    }

    function addSeenUid($uid) {
        $this->seenUids[] = $uid;
    }

    function reset() {
        $this->seenUids = array();
    }

    // Instantiating the class several times is not necessary,
    // because we have implemented reset();
    function isSingleton() {
        return true;
    }
}

