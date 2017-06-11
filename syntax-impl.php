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

if (!defined('DOKU_INC'))
    die();

if (!defined('DOKU_PLUGIN'))
    define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

use Sabre\VObject;

require_once DOKU_PLUGIN . 'syntax.php';
require_once __DIR__ . '/vendor/autoload.php';

class syntax_plugin_icalevents extends syntax_plugin_icalevents_base {
    function __construct() {
        // Unpredictable (not in a crypto sense) nonce to recognize our own
        // strings, e.g., <nowiki> tags that we have inserted
        $this->nonce = mt_rand();
        $this->localTimezone = new DateTimeZone(date_default_timezone_get());
    }

    /**
     * Parse parameters from the {{iCalEvents>...}} tag.
     * @return array an array that will be passed to the render function
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        // strip {{iCalEvents> or {{iCalendar from start and strip }} from end
        $match = substr($match, strpos($match, '>') + 1, -2);

        list($source, $flagStr) = explode('#', $match, 2);

        // parse_str urldecodes valid percent-encoded byte, e.g., %dd.
        // This is problematic, because the tformat and dformat parameters
        // are intended to be parsed by strftime(), for which % is a
        // special char. That is, a string %dd would not be interpreted
        // as %d followed by a literal d.
        // We ignore that problem, because it does not seem likely to hit
        // a valid percecent encoding (% followed by two hex digits) in
        // practice. In that case, % must be encoded as %25.
        parse_str($flagStr, $params);

        // maxNumberOfEntries was called numberOfEntries earlier.
        // We support both versions for backwards compatibility.
        $maxNumberOfEntries = (int) ($params['maxNumberOfEntries'] ?: ($params['numberOfEntries'] ?: PHP_INT_MAX));

        $showEndDates = filter_var($params['showEndDates'], FILTER_VALIDATE_BOOLEAN);

        if ($params['showAs']) {
            $showAs = $params['showAs'];
        } else {
            // BackwardEvent compatibility of v1.3 or earlier
            if (filter_var($params['showAsList'], FILTER_VALIDATE_BOOLEAN)) {
                $showAs = 'list';
            } else {
                $showAs = 'default';
            }
        }

        // Get the template
        $template = $this->getConf('template:' . $showAs);
        if (!isset($template) || $template == '') {
            $template = $this->getConf('default');
        }

        // Sorting
        switch (mb_strtolower($params['sort'])) {
            case 'desc':
                $order = -1;
                break;
            case 'off':
                $order = 0;
                break;
            default:
                $order = 1;
        }

        $fromString = $params['from'];

        // Handle deprecated previewDays parameter
        if (isset($params['previewDays']) && !isset($params['to'])) {
            $toString = '+' . $params['previewDays'] . ' days';
        } else {
            $toString = $params['to'];
        }

        if ($toString) {
            $hasRelativeRange = static::isRelativeDateTimeString($fromString)
                 || static::isRelativeDateTimeString($toString);
        } else {
            $toString = $toString ?: '+30 days';
            $hasRelativeRange = true;
        }

        return array(
            $source,
            $fromString,
            $toString,
            $hasRelativeRange,
            $maxNumberOfEntries,
            $showEndDates,
            $template,
            $order,
            hsc($params['dformat']),
            hsc($params['tformat'])
        );
    }

    function render($mode, Doku_Renderer $renderer, $data) {
        list(
            $source,
            $fromString,
            $toString,
            $hasRelativeRange,
            $maxNumberOfEntries,
            $showEndDates,
            $template,
            $order,
            $dformat,
            $tformat
          ) = $data;

        // TODO error handling for invalid strings
        $from = new DateTime($fromString);
        $to = new DateTime($toString);

        try {
            $content = static::readSource($source);
        } catch (Exception $e) {
            $renderer->doc .= static::ERROR_PREFIX  . $e->getMessage() . ' ';
            return false;
        }

        // SECURITY
        // Disable caching for rendered local (media) files because
        // a user without read permission for the local file could read
        // the cached document.
        // Also disable caching if the rendered result depends on the
        // current time, i.e., if the time range to display is relative.
        if (static::isLocalFile($source) || $hasRelativeRange) {
            $renderer->info['cache'] = false;
        }

        try {
            $ical = VObject\Reader::read($content, VObject\Reader::OPTION_FORGIVING);
        } catch (Exception $e) {
            $renderer->doc .= static::ERROR_PREFIX . 'invalid iCalendar input. ';
            return false;
        }

        // Export mode
        if ($mode == 'icalevents') {
            $uid = rawurldecode($_GET['uid']);
            $recurrenceId = rawurldecode($_GET['recurrence-id']);

            // Make sure the sub-event is in the expanded calendar.
            // Also, there is no need to expand more.
            if ($dtRecurrence = DateTimeImmutable::createFromFormat('Ymd', $recurrenceId)) {
                try {
                    // +/- 1 day to avoid time zone weirdness
                    $ical = $ical->expand($dtRecurrence->modify('-1 day'), $dtRecurrence->modify('+1 day'));
                } catch (Exception $e) {
                    $renderer->doc .= static::ERROR_PREFIX . 'Unable to expand recurrent events for export.';
                    return false;
                }
            }

            $comp = array_shift(array_filter($ical->getByUid($uid),
                function($event) use ($recurrenceId) {
                    return ((string) $event->{'RECURRENCE-ID'}) === $recurrenceId;
                }
            ));
            if ($comp) {
                $renderer->doc .= $comp->serialize();
                $eid = $uid . ($recurrenceId ? '-' . $recurrenceId : '');
                $renderer->setEventId($eid);
            }
            return (bool) $comp;
        } else {
            // If no date/time format is requested, fall back to plugin
            // configuration ('dformat' and 'tformat'), and then to a
            // a value based on DokuWiki's defaults.
            // Note: We don't fall back to DokuWiki's global dformat, because it contains
            //       date AND time, and there is no global tformat.
            $dateFormat = $dformat ?: $this->getConf('dformat') ?: '%Y/%m/%d';
            $timeFormat = $tformat ?: $this->getConf('tformat') ?: '%H:%M';

            try {
                $vevent = $ical->expand($from, $to)->VEVENT;
                // We need an instance of ArrayIterator, which supports sorting.
            } catch (Exception $e) {
                $renderer->doc .= static::ERROR_PREFIX . 'unable to expand recurrent events. ';
                return false;
            }

            if ($vevent === null) {
                // No events, nothing to do.
                return true;
            }

            $events = $vevent->getIterator();

            // Sort if desired
            if ($order != 0) {
                $events->uasort(
                     function(&$e1, &$e2) use ($order) {
                        $diff = $e1->DTSTART->getDateTime($this->localTimezone)->getTimestamp()
                          - $e2->DTSTART->getDateTime($this->localTimezone)->getTimestamp();
                        return $order * $diff;
                    }
                );
            }

            // Loop over events and render template for each one.
            $dokuwikiOutput = '';
            $i = 0;
            foreach ($events as &$event) {
                if ($i++ >= $maxNumberOfEntries) {
                    break;
                }

                list($output, $summaryLinks[])
                    = $this->renderEvent($mode, $renderer, $event, $template, $dateFormat, $timeFormat);
                $dokuwikiOutput .= $output;
            }

            // Replace {summary_link}s by placeholders containing our nonce and
            // wrap them into <nowiki> to ensure that the DokuWiki renderer won't touch it.
            $summaryLinkToken= '{summary_link:' . $this->nonce . '}';
            $rep = $this->nowikiStart() . $summaryLinkToken . $this->nowikiEnd();
            $dokuwikiOutput = str_replace('{summary_link}', $rep, $dokuwikiOutput);

            // Translate DokuWiki code into instructions.
            $instructions = p_get_instructions($dokuwikiOutput);

            // Some <nowiki> tags introduced by us may not haven been parsed
            // because <nowiki> is ignored in certain syntax elements, e.g., headings.
            // Remove these remaining <nowiki> tags. We find them reliably because
            // they contain our nonce.
            static::str_remove_deep(array($this->nowikiStart(), $this->nowikiEnd(), $this->magicString()), $instructions);

            // Remove document_start and document_end instructions.
            // This avoids a reset of the TOC for example.
            array_shift($instructions);
            array_pop($instructions);

            foreach ($instructions as &$ins) {
                foreach ($ins[1] as &$text) {
                    $text = str_replace(array($this->nowikiStart(), $this->nowikiEnd(), $this->magicString()), '', $text);
                }
                // Execute the callback against the Renderer, i.e., render the instructions.
                if (method_exists($renderer, $ins[0])){
                    call_user_func_array(array(&$renderer, $ins[0]), $ins[1] ?: array());
                }
            }

            if ($mode == 'xhtml') {
                // Replace summary link placeholders with the entries of $summaryLinks.
                // We handle it here, because it is raw HTML generated by us, not DokuWiki syntax.
                $linksPerEvent = substr_count($template, '{summary_link}');
                $renderer->doc = static::str_replace_array($summaryLinkToken , $summaryLinks, $linksPerEvent, $renderer->doc);
            }
            return true;
        }
    }

    /**
     * Render a VEVENT
     *
     * @param string $mode rendering mode, e.g., 'xhtml'
     * @param Doku_Renderer $renderer
     * @param Sabre\VObject\Component\VEvent $event
     * @param string $template
     * @param string $dateFormat
     * @param string $timeFormat
     * @return string[] an array containing the modified template at first position
     *         and the formatted summary link at second position
     */
    function renderEvent($mode, Doku_Renderer $renderer, $event, $template, $dateFormat, $timeFormat) {
        // {description}
        $template = str_replace('{description}', $this->textAsWiki($event->DESCRIPTION), $template);

        // {summary}
        $summary = $this->textAsWiki($event->SUMMARY);
        $template = str_replace('{summary}', $summary, $template);

        // See if a location was set
        $location = $event->LOCATION;
        if ($location != '') {
            // {location}
            $template = str_replace('{location}', $location, $template);

            // {location_link}
            $locationUrl = $this->getLocationUrl($location);

            $locationLink = $locationUrl ? ('[[' . $locationUrl . '|' . $location . ']]') : $location;
            $template = str_replace('{location_link}', $locationLink, $template);
        } else {
            // {location}
            $template = str_replace('{location}', 'Unknown', $template);
            // {location_link}
            $template = str_replace('{location_link}', 'Unknown', $template);
        }

        $dt = $this->handleDatetime($event, $dateFormat, $timeFormat);

        $startString = $dt['start']['datestring'] . ' ' . $dt['start']['timestring'];
        $endString = '';
        if ($dt['end']['datestring'] != $dt['start']['datestring'] || $showEndDates) {
            $endString .= $dt['end']['datestring'] . ' ';
        }
        $endString .= $dt['end']['timestring'];
        // Add dash only if there is end date or time
        $whenString = $startString . ($endString ? ' - ' : '') . $endString;

        // {date}
        $template = str_replace('{date}', $whenString, $template);
        $template .= "\n";

        if ($mode == 'xhtml') {
            // Prepare summary link
            $link           = array();
            $link['class']  = 'mediafile plugin-icalevents-export';
            $link['pre']    = '';
            $link['suf']    = '';
            $link['more']   = 'rel="nofollow"';
            $link['target'] = '';
            $link['title']  = hsc($event->SUMMARY);
            $getParams = array(
                'uid' => rawurlencode($event->UID),
                'recurrence-id' => rawurlencode($event->{'RECURRENCE-ID'})
            );
            $link['url']    = exportlink($GLOBALS['ID'], 'icalevents', $getParams);
            $link['name']   = nl2br($link['title']);

            // We add a span to be able to "inherit" from it CSS
            $summaryLink = '<span>' . $renderer->_formatLink($link) . '</span>';
        } else {
            $template = str_replace('{summary_link}', $event->SUMMARY, $template);
            $summaryLink = '';
        }
        return array($template, $summaryLink);
    }

    /**
     * Read an iCalendar file from a remote server via HTTP(S) or from a local media file
     *
     * @param string $source URL or media id
     * @return string
     */
    static function readSource($source) {
        if (static::isLocalFile($source)) {
            $path = mediaFN($source);
            $contents = @file_get_contents($path);
            if ($contents === false) {
                $error = 'could not read media file ' . hsc($source) . '. ';
                throw new Exception($error);
            }
            return $contents;
        } else {
            $http = new DokuHTTPClient();
            if (!$http->get($source)) {
                $error = 'could not get ' . hsc($source) . ', HTTP status ' . $http->status . '. ';
                throw new Exception($error);
            }
            return $http->resp_body;
        }
    }

    /**
     * Determines whether a source is a local (media) file
     */
    static function isLocalFile($source) {
        // This does not work for protocol-relative URLs
        // but they are not supported by DokuHTTPClient anyway.
        return !preg_match('#^https?://#i', $source);
    }

    /**
     * Computes date and time string of an event returned by vobject/sabre
     */
    function handleDatetime($event, $dateFormat, $timeFormat) {
        foreach (array('start', 'end') as $which) {
            $dtSabre = $event->{'DT' . strtoupper($which)};
            $dtImmutable = $dtSabre->getDateTime($this->localTimezone);
            $dt = &$res[$which];
            // Correct end date for all-day events, which formally end
            // on 00:00 of the following day.
            if (!$dtSabre->hasTime() && $which == 'end') {
                $dtImmutable = $dtImmutable->modify('-1 day');
            }
            $dt['datestring'] = strftime($dateFormat, $dtImmutable->getTimestamp());
            $dt['timestring'] = $dtSabre->hasTime() ? strftime($timeFormat, $dtImmutable->getTimestamp()) : '';
        }
        return $res;
    }

    /**
     * Determines whether a string as accepted by strtotime()
     * is relative to a base timestamp (second argument of
     * strtotime()).
     */
    static function isRelativeDateTimeString($str) {
        // $str is relative iff it yields the same timestamp
        // now and more than one year ago.
        // Reason: A year is the largest unit that is understood
        // by strtotime().
        $relNow = strtotime($str);
        $relTwoY = strtotime($str, time() - 2 * 365 * 24 * 3600);
        return $relNow != $relTwoY;
    }

    /**
     * Replaces all occurrences of $needle in $haystack by the elements of $replace.
     *
     * Each element of $replace is used $count times, i.e., the first $count occurrences of $needle in
     * $haystack are replaced by $replace[0], the next $count occurrences by $replace[1], and so on.
     * If $count is 0, then $haystack is returned without modification.
     *
     * @param string   $needle   substring to replace
     * @param string[] $replace  a numerically indexed array of substitutions for $needle
     * @param int      $count    number of $needles to be replaced by the same element of $replace
     * @param string   $haystack string to be searched
     * @return string  $haystack with the substitution applied
     */
    static function str_replace_array($needle, $replace, $count, $haystack) {
        if ($count <= 0) {
            return $haystack;
        }
        $haystackArray = explode($needle, $haystack);
        $res = '';
        foreach ($haystackArray as $i => $piece) {
            $res .= $piece;
            // "(int) ($i / $count)" simulates integer division.
            $replaceIndex = (int) ($i / $count);
            // Note that $replaceIndex will be out of bounds in $replace for the last loop iteration.
            // In that case, the array access yields NULL, which is interpreted as the empty string.
            // This is what we need, because there was no $needle after the last $piece.
            $res .= $replace[$replaceIndex];
        }
        return $res;
    }

    /**
     * Removes all occurrences of $needle in all strings in the array $haystack recursively.
     *
     * @param string $needle   substring or array of substrings to remove
     * @param array  $haystack array to searched
     */
    static function str_remove_deep($needle, &$haystack) {
        array_walk_recursive($haystack,
          function (&$h, &$k) use ($needle) {
              $h = str_replace($needle, '', $h);
        });
    }

    /**
     * Replaces line breaks by DokuWiki's \\ line breaks and inserts
     * <nowiki> tags.
     *
     * @param string $text
     * @return string
     */
    function textAsWiki($text) {
        // First, remove existing </nowiki> end tags. (We display events that contain '</nowiki>'
        // incorrectly but this should not be a problem in practice.)
        // Second, replace line breaks by DokuWiki line breaks.
        $needle   = array('</nowiki>', "\n");
        $haystack = array('',          $this->nowikiEnd() . '\\\\ '. $this->nowikiStart());
        $text = str_ireplace($needle, $haystack, $text);
        return $this->nowikiStart() . $text . $this->nowikiEnd();
    }

    function magicString() {
        return '{' . $this->nonce .' magiccc}';
    }

    function nowikiStart() {
        return '<nowiki>' . $this->magicString();
    }

    function nowikiEnd() {
        return $this->magicString() . '</nowiki>';
    }

    function getLocationUrl($location) {
        // Some map providers don't like line break characters.
        $location = urlencode(str_replace("\n", ' ', $location));

        $prefix = $this->getConf('customLocationUrlPrefix') ?: $this->getConf('locationUrlPrefix');
        return ($prefix != '') ? ($prefix . $location) : false;
    }
}
