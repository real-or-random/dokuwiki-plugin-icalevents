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
     * parse parameters from the {{iCalEvents>...}} tag.
     * @return an array that will be passed to the renderer function
     */
    function handle($match, $state, $pos, &$handler) {
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

    function render($mode, &$renderer, $data) {
        global $ID;
        if ($mode != 'xhtml' && $mode != 'icalevents') {
            return false;
        }

        list($source, $fromString, $toString, $maxNumberOfEntries, $showEndDates, $template, $sortDescending) = $data;
        $from = strtotime($fromString);
        $to = strtotime($toString ?: '+30 days');

        try {
            $content = static::readSource($source);
        } catch (Exception $e) {
            $renderer->doc .= 'Error in Plugin iCalEvents: ' . $e->getMessage();
            return false;
        }

        // SECURITY
        // Disable caching for rendered local (media) files because
        // a user without read permission for the local file could read
        // the cached document
        if (static::isLocalFile($source)) {
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
                    $eventTemplate = str_replace('{description}', static::textPropertyOfEventAsWiki($event, 'description'), $eventTemplate);

                    // {summary}
                    $summary = static::textPropertyOfEventAsWiki($event, 'summary');
                    $eventTemplate = str_replace('{summary}', $summary, $eventTemplate);

                    // See if a location was set
                    $location = static::textPropertyOfEvent($event, 'location');
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
                    // add dash only if there is end date or time
                    $whenString = $startString . ($endString ? ' - ' : '') . $endString;

                    // {date}
                    $eventTemplate = str_replace('{date}', $whenString, $eventTemplate);
                    $eventTemplate .= "\n";

                    // prepare summary link here
                    $summary_link           = array();
                    $summary_link['class']  = 'mediafile';
                    $summary_link['style']  = 'background-image: url(lib/plugins/icalevents/ics.png);';
                    $summary_link['pre']    = '';
                    $summary_link['suf']    = '';
                    $summary_link['more']   = 'rel="nofollow"';
                    $summary_link['target'] = '';
                    $summary_link['title']  = $summary;
                    $uid = static::textPropertyOfEvent($event, 'uid');
                    $summary_link['url']    = exportlink($ID, 'icalevents', array('uid' => rawurlencode($uid)));
                    $summary_link['name']   = $summary;

                    $summary_links[] = $renderer->_formatLink($summary_link);

                    $dokuwikiOutput .= $eventTemplate;
                }
            }

            // Wrap {summary_link} into <nowiki> to ensure that the DokuWiki renderer won't touch it.
            $dokuwikiOutput = str_replace('{summary_link}', '<nowiki>{summary_link}</nowiki>', $dokuwikiOutput);

            // Pass output through the DokuWiki renderer.
            $html = p_render($mode, p_get_instructions($dokuwikiOutput), $info);

            // Some <nowiki> tags introduced by textPropertyOfEventAsWiki() may not haven been parsed,
            // because <nowiki> is ignored in certain syntax elements, e.g., headings.
            // Remove these remaining <nowiki> tags.
            $html = str_replace(array('&lt;nowiki&gt;','&lt;/nowiki&gt;'), '', $html);

            // Replace {summary_link}s with the entries of $summary_links and concatenate to output.
            // We handle it here, because it is raw HTML generated by us, not DokuWiki syntax.
            $linksPerEvent = substr_count($template, '{summary_link}');
            $html = static::str_replace_array('{summary_link}', $summary_links, $linksPerEvent, $html);
            $renderer->doc .= $html;
        } else {
            // In this case, we have $mode == 'icalevents'.
            // That implies that $renderer is an instance of renderer_plugin_icalevents.
            $uid = rawurldecode($_GET['uid']);
            if (!$renderer->hasSeenUID($uid)) {
                $comp = $ical->getComponent($uid);
                if (!$comp) {
                    http_status(404);
                    exit;
                }
                else {
                    // $dummy is necessary, because the argument is call-by-reference.
                    $renderer->doc .= $comp->createComponent($dummy = null);
                }
                $renderer->addSeenUID($uid);
            }
        }

        return true;
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
     * Replaces all occurrences of $needle in $haystack by the elements of $replace.
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
     * Wrapper for vevent::getProperty(), because that method does not unescape iCalendar TEXT
     * properties correctly.
     *
     * @see https://github.com/iCalcreator/iCalcreator/issues/16
     * @uses vevent::getProperty()
     * @param vevent  $event
     * @param string  $property
     * @return string
     */
    static function textPropertyOfEvent($event, $property) {
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
    static function textPropertyOfEventAsWiki($event, $property) {
        // First, remove existing </nowiki> end tags. (We display events that contain '</nowiki>'
        // incorrectly but this should not be a problem in practice.)
        // Second, replace line breaks by DokuWiki line breaks.
        $needle   = array('</nowiki>', '\n');
        $haystack = array('',          '</nowiki>\\\\ <nowiki>');
        return '<nowiki>' . str_ireplace($needle, $haystack, $event->getProperty($property)) . '</nowiki>';
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
