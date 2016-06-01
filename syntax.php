<?php
/**
 * Plugin iCalEvents: Renders an iCalendar file, e.g., as a table.
 *
 * Copyright (C) 2010-2012, 2015
 * Robert Rackl, Elan Ruusamäe, Jannes Drost-Tenfelde, Tim Ruffing
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
 * @version    3.0.0
 * @author     Robert Rackl <wiki@doogie.de>
 * @author     Elan Ruusamäe <glen@delfi.ee>
 * @author     Jannes Drost-Tenfelde <info@drost-tenfelde.de>
 * @author     Tim Ruffing <tim@timruffing.de>
 *
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC'))
    die();

if (!defined('DOKU_PLUGIN'))
    define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

require_once DOKU_PLUGIN . 'syntax.php';
require_once DOKU_PLUGIN . 'icalevents/externals/iCalcreator/iCalcreator.php';

/**
 * This plugin gets an iCalendar file via HTTP,
 * parses this file and renders it according to a template.
 *
 * Usage: {{iCalEvents>http://host/myCalendar.ics#from=today&to=+3 days}}
 *
 * You can filter the events that are shown with some parameters:
 * 1. 'from' a date from which on to show events.
 *           Can be any string that is accepted by strtotime(),
 *           e.g., "from=today".
 *           http://www.php.net/manual/en/function.strtotime.php
 *           If 'from' is omitted, then all events are shown.
 * 2. 'to'   a date until which to show events. Similar to 'from'
 *           Defaults to '+60 days'
 *           Note: The 'previewDays' parameter is deprecated.
 * 3. 'showEndDates' to show end date or not defaults to value set in plugin config
 * 4. 'showCurrentWeek' highlight events matching current week.
 *           currently assumes all-day events end at 12:00 local time, like in Google Calendar
 *
 * <code>from <= eventdate <= from+(previewDays*24*60*3600)</code>
 *
 * See also global configuration settings in plugins/iCalEvents/conf/default.php
 *
 * @see http://de.wikipedia.org/wiki/ICalendar
 */
class syntax_plugin_icalevents extends DokuWiki_Syntax_Plugin {
    function __construct() {
        // Unpredictable (not in a crypto sense) nonce to recognize our own
        // strings, e.g., <nowiki> tags that we have inserted
        $this->nonce = mt_rand();
    }

    // implement necessary Dokuwiki_Syntax_Plugin methods
    function getType() {
        return 'substition';
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

    function getPType() {
        return 'block';
    }

    /**
     * Parse parameters from the {{iCalEvents>...}} tag.
     * @return an array that will be passed to the render function
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        // strip {{iCalEvents> or {{iCalendar from start and strip }} from end
        $match = substr($match, strpos($match, '>') + 1, -2);

        list($source, $flagStr) = explode('#', $match, 2);
        parse_str($flagStr, $params);

        // maxNumberOfEntries was called numberOfEntries earlier.
        // We support both versions for backwards compatibility.
        $maxNumberOfEntries = (int) ($params['maxNumberOfEntries'] ?: ($params['numberOfEntries'] ?: -1));

        $showEndDates = filter_var($params['showEndDates'], FILTER_VALIDATE_BOOLEAN);

        if ($params['showAs']) {
            $showAs = $params['showAs'];
        } else {
            // Backward compatibility of v1.3 or earlier
            if (filter_var($params['showAsList'], FILTER_VALIDATE_BOOLEAN)) {
                $showAs = 'list';
            } else {
                $showAs = 'default';
            }
        }

        // Get the appropriate template
        $template = $this->getConf($showAs);
        if (!isset($template) || $template == '') {
            $template = $this->getConf('default');
        }

        // Find out if the events should be sorted in reserve
        $sortDescending = (mb_strtolower($params['sort']) == 'desc');

        // handle deprecated previewDays parameter
        if (isset($params['previewDays']) && !isset($params['to'])) {
            $toString = '+' . $params['previewDays'] . ' days';
        } else {
            $toString = $params['to'];
        }

        return array(
            $source,
            $params['from'],
            $toString,
            $maxNumberOfEntries,
            $showEndDates,
            $template,
            $sortDescending
        );
    }

    function render($mode, Doku_Renderer $renderer, $data) {
        global $ID;

        list($source, $fromString, $toString, $maxNumberOfEntries, $showEndDates, $template, $sortDescending) = $data;
        if ($toString) {
            $hasRelativeRange = static::isRelativeDateTimeString($fromString)
                 || static::isRelativeDateTimeString($toString);
        } else {
            $toString = $toString ?: '+30 days';
        }
        // TODO error handling for invalid strings
        $from = strtotime($fromString);
        $to = strtotime($toString);

        try {
            $content = static::readSource($source);
        } catch (Exception $e) {
            $renderer->doc .= 'Error in Plugin iCalEvents: ' . $e->getMessage();
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

        $config = array('unique_id' => 'dokuwiki-plugin-icalevents');
        $ical = new vcalendar($config);
        $ical->parse($content);

        if ($mode == 'xhtml') {
            // If dateformat is set in plugin configuration ('dformat'), then use it.
            // Otherwise fall back to dokuwiki's default dformat from the global /conf/dokuwiki.php.
            $dateFormat = $this->getConf('dformat') ?: $conf['dformat'];
            $timeFormat = $this->getConf('tformat') ?: $conf['tformat'];

            $events = $ical->selectComponents(date('Y', $from), date('m', $from), date('d', $from), date('Y', $to), date('m', $to), date('d', $to), 'vevent', true);
            if ($events) {
                // compute timestamps etc. using handleDatetime, and add result to array
                $events = array_map(
                    function($comp) use ($dateFormat, $timeFormat) {
                        return array('event' => $comp, 'datetime' => static::handleDatetime($comp, $dateFormat, $timeFormat));
                    }, $events
                );

                // filter invalid events
                $events = array_filter($events,
                    function($comp) {
                        return $comp['datetime'] !== false;
                    }
                );

                // sort events
                uasort($events,
                    function($a, $b) use ($sortDescending) {
                        return ($sortDescending ? -1 : 1) * ($a['datetime']['start']['timestamp'] - $b['datetime']['start']['timestamp']);
                    }
                );

                if ($maxNumberOfEntries >= 0) {
                    $events = array_slice($events, 0, $maxNumberOfEntries);
                }

                $dokuwikiOutput = '';
                // loop over events and render template for each one
                foreach ($events as &$entry) {
                    $event = &$entry['event'];
                    $datetime = &$entry['datetime'];

                    // Get a copy of the template for the events
                    $eventTemplate = $template;

                    // {description}
                    $eventTemplate = str_replace('{description}', $this->textPropertyOfEventAsWiki($event, 'description'), $eventTemplate);

                    // {summary}
                    $summary = $this->textPropertyOfEventAsWiki($event, 'summary');
                    $eventTemplate = str_replace('{summary}', $summary, $eventTemplate);

                    // See if a location was set
                    $location = $this->textPropertyOfEvent($event, 'location');
                    if ($location != '') {
                        // {location}
                        $eventTemplate = str_replace('{location}', $location, $eventTemplate);

                        // {location_link}
                        $locationUrl = $this->getLocationUrl($location);
                        $locationLink = $locationUrl ? ('[[' . $locationUrl . '|' . $location . ']]') : $location;
                        $eventTemplate = str_replace('{location_link}', $locationLink, $eventTemplate);
                    } else {
                        // {location}
                        $eventTemplate = str_replace('{location}', 'Unknown', $eventTemplate);
                        // {location_link}
                        $eventTemplate = str_replace('{location_link}', 'Unknown', $eventTemplate);
                    }

                    $start = &$datetime['start'];
                    $end   = &$datetime['end'];

                    $startString = $start['datestring'] . ' ' . $start['timestring'];
                    $endString = '';
                    if ($end['date'] != $start['date'] || $showEndDates) {
                        $endString .= $end['datestring'] . ' ';
                    }
                    $endString .= $end['timestring'];
                    // Add dash only if there is end date or time
                    $whenString = $startString . ($endString ? ' - ' : '') . $endString;

                    // {date}
                    $eventTemplate = str_replace('{date}', $whenString, $eventTemplate);
                    $eventTemplate .= "\n";

                    if ($mode == 'xhtml') {
                        // Prepare summary link
                        $link           = array();
                        $link['class']  = 'mediafile';
                        $link['style']  = 'background-image: url(lib/plugins/icalevents/ics.png);';
                        $link['pre']    = '';
                        $link['suf']    = '';
                        $link['more']   = 'rel="nofollow"';
                        $link['target'] = '';
                        $link['title']  = hsc($this->textPropertyOfEvent($event, 'summary'));
                        $uid = $this->textPropertyOfEvent($event, 'uid');
                        $link['url']    = exportlink($ID, 'icalevents', array('uid' => rawurlencode($uid)));
                        $link['name']   = nl2br($link['title']);

                        $summaryLinks[] = $renderer->_formatLink($link);
                    }

                    $dokuwikiOutput .= $eventTemplate;
                }
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
            $instructions = static::str_remove_deep(array($this->nowikiStart(), $this->nowikiEnd(), $this->magicString()), $instructions);

            // Remove document_start and document_end instructions.
            // This avoids a reset of the TOC for example.
            array_pop(array_shift($instructions));

            foreach ($instructions as &$ins) {
                foreach ($ins[1] as &$text) {
                    $text = str_replace(array($this->nowikiStart(), $this->nowikiEnd(), $this->magicString()), '', $text);
                }
                // Execute the callback against the Renderer, i.e., render the instructions.
                if (method_exists($renderer, $ins[0])){
                    call_user_func_array(array(&$renderer, $ins[0]), $ins[1] ?: array());
                }
            }

            // Replace summary link placeholders with the entries of $summaryLinks.
            // We handle it here, because it is raw HTML generated by us, not DokuWiki syntax.
            $linksPerEvent = substr_count($template, '{summary_link}');
            $renderer->doc = static::str_replace_array($summaryLinkToken , $summaryLinks, $linksPerEvent, $renderer->doc);
            return true;
        } elseif ($mode == 'icalevents') {
            $uid = rawurldecode($_GET['uid']);
            if (!$renderer->hasSeenUID($uid)) {
                $comp = $ical->getComponent($uid);
                if (!$comp || !$uid) {
                    http_status(404);
                    exit;
                } else {
                    // $dummy is necessary, because the argument is call-by-reference.
                    $renderer->doc .= $comp->createComponent($dummy = null);
                }
                $renderer->addSeenUID($uid);
            }
            return true;
        } else {
            return false;
        }
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
     * Computes various values on a datetime array returned by iCalcreator
     */
    static function handleDatetime($event, $dateFormat, $timeFormat) {
        foreach (array('start', 'end') as $which) {
            $prop = $event->getProperty('dt' . $which, false, true);
            $datetime = $prop['value'];
            $tz = $prop['params']['TZID'];
            $dt = &$res[$which];

            $dt['date'] = sprintf(iCalUtilityFunctions::$fmt['Ymd'], $datetime['year'], $datetime['month'], $datetime['day']);
            $dt['time'] = sprintf(iCalUtilityFunctions::$fmt['His'], $datetime['hour'], $datetime['min'], $datetime['sec']);

            $full = $dt['date'] . 'T' . $dt['time'];

            $dto = null;
            try {
                if (iCalUtilityFunctions::_isOffset($tz)) {
                    $dto = new DateTime($full, new DateTimeZone('UTC'));
                    $dto->modify('+' . iCalUtilityFunctions::_tz2offset($tz) . ' seconds');
                } else {
                    $local = false;
                    if ($tz == '') {
                        // floating event
                        $local = true;
                    } else {
                        try {
                            $dtz = new DateTimeZone($tz);
                            $dto = new DateTime($full, $dtz);
                        } catch (Exception $eTz) {
                            // invalid timezone, fall back to local timezone.
                            $local = true;
                        }
                    }
                    if ($local) {
                        $dto = new DateTime($full);
                    }
                }
                $dt['timestamp'] = $dto->getTimestamp();
            } catch (Exception $eDate) {
                // invalid date or time
                $dt['timestamp'] = '';
                $dt['datestring'] = '';
                $dt['timestring'] = '';
                continue;
            }

            // from the iCalcreator docs:
            //  "Notice that an end date without a time is in effect midnight of the day before the date, so for timeless dates,
            //  use the date following the event date for it to be correct."
            // So we correct the end date in this case.
            if (static::isAllDayEvent($event) && $which == 'end') {
                $dto->modify('-1 day');
            }
            $dt['datestring'] = strftime($dateFormat, $dto->getTimestamp());
            $dt['timestring'] = !static::isAllDayEvent($event) ?  strftime($timeFormat, $dto->getTimestamp()) : '';
        }
        return $res;
    }

    /**
     * Determines if an iCalcreator event is an all-day event.
     */
    static function isAllDayEvent($event) {
        return !isset($event->getProperty('dtstart')['hour']);
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
     * Removes all occurrences of $needle in $haystack.
     *
     * If $haystack is an array, $needle is removed recursively all indices and values.
     * This function internally uses a JSON encoding for increased performance.
     * Special JSON characters (" ' [ ]) are not supported in $needle because they
     * could be harmful.
     *
     * @param string $needle    substring or array of substrings to remove
     * @param string $haystack string or array to searched
     * @return false if $needle contains JSON characters, and the result of the removal otherwise
     */
    static function str_remove_deep($needle, $haystack) {
        // Abort if $needle contains JSON characters or has wrong type
        $jsonChars = "[]'\"";
        if (is_string($needle)) {
            if (strpbrk($needle, $jsonChars) !== false) {
                return false;
            }
        } elseif (is_array($needle)) {
            foreach ($needle as $n) {
                if (!is_string($n) || strpbrk($n, $jsonChars) !== false) {
                    return false;
                }
            }
        } else {
            return false;
        }
        $json = json_encode($haystack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return json_decode(str_replace($needle, '', $json));
    }

    /**
     * Wrapper for vevent::getProperty(), because that method does not unescape iCalendar TEXT
     * properties correctly.
     *
     * @see https://github.com/iCalcreator/iCalcreator/issues/16
     * @uses vevent::getProperty()
     * @param vevent  $event
     * @param string  $property
     * @return string
     */
    function textPropertyOfEvent($event, $property) {
        return str_ireplace('\n', "\n", $event->getProperty($property));
    }

    /**
     * Wrapper for vevent::getProperty().
     * Line breaks are replaced by DokuWiki's \\ line breaks.
     *
     * @uses vevent::getProperty()
     * @param vevent  $event
     * @param string  $property
     * @return string
     */
    function textPropertyOfEventAsWiki($event, $property) {
        // First, remove existing </nowiki> end tags. (We display events that contain '</nowiki>'
        // incorrectly but this should not be a problem in practice.)
        // Second, replace line breaks by DokuWiki line breaks.
        $needle   = array('</nowiki>', '\n');
        $haystack = array('',          $this->nowikiEnd() . '\\\\ '. $this->nowikiStart());
        $propString = str_ireplace($needle, $haystack, $event->getProperty($property));
        return $this->nowikiStart() . $propString . $this->nowikiEnd();
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

        $customConf = $this->getConf('customLocationUrlPrefix');
        $prefix = false;

        // See the comment in conf/metadata.php to understand the customLocationUrlPrefix property.
        // DokuWiki encodes the property as comma-separated string. That is, if the string ' ' is
        // present at the beginning or the end of the property, we interpret the rest as custom prefix.
        if (strpos($customConf, ' ,') === 0) {
            $prefix = substr($customConf, 2);
        } elseif (strrpos($customConf, ', ') === strlen($customConf) - 2) {
            $prefix = substr($customConf, 0 , -2);
        }

        if (!$prefix) {
            $prefix = $this->getConf('locationUrlPrefix');
        }

        return $prefix ? ($prefix . $location) : false;
    }
}
