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

        list($icsURL, $flagStr) = explode('#', $match, 2);
        parse_str($flagStr, $params);

        // Get the numberOfEntries parameter
        if ($params['numberOfEntries']) {
            $numberOfEntries = $params['numberOfEntries'];
        } else {
            $numberOfEntries = -1;
        }

        // Get the show end dates parameter
        if ($params['showEndDates'] == 1) {
            $showEndDates = true;
        } else {
            $showEndDates = false;
        }
        // Get the show as list parameter
        if ($params['showAs']) {
            $showAs = $params['showAs'];
        } else {
            $showAs = 'default';
        }

        // Get the showAs parameter (since v1.4)
        if ($params['showAs']) {
            $showAs = $params['showAs'];
        } else {
            // Backward compatibiltiy of v1.3 or earlier
            if ($params['showAsList'] == 1) {
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
            $icsURL,
            $params['from'],
            $toString,
            $numberOfEntries,
            $showEndDates,
            $template,
            $sortDescending
        );
    }

    /**
     * loads the ics file via HTTP, parses it and renders an HTML table.
     */
    function render($mode, &$renderer, $data) {
        global $ID;
        if ($mode != 'xhtml' && $mode != 'icalevents') {
            return false;
        }

        // TODO $numberOfEntries not implemented
        list($url, $fromString, $toString, $numberOfEntries, $showEndDates, $template, $sortDescending) = $data;
        $from = strtotime($fromString);
        $to = strtotime($toString ?: '+30 days');

        // parse the ICS file
        $http = new DokuHTTPClient();
        if (!$http->get($url)) {
            $renderer->doc .= 'Error in Plugin iCalEvents:';
            $renderer->doc .= 'Could not get ' . hsc($url);
            $renderer->doc .= ': ' . $http->status;
            return false;
        }
        $content = $http->resp_body;

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

                // sort events
                uasort($events,
                    function($a, $b) use ($sortDescending) {
                        return ($sortDescending ? -1 : 1) * ($a['datetime']['start']['timestamp'] - $b['datetime']['start']['timestamp']);
                    }
                );

                $ret = '';
                // loop over events and render template for each one
                foreach ($events as &$entry) {
                    $event = &$entry['event'];
                    $datetime = &$entry['datetime'];
                    if ($datetime === false) {
                        continue;
                    }

                    // Get a copy of the template for the events
                    $eventTemplate = $template;

                    // {description}
                    $eventTemplate = str_replace('{description}', $event->getProperty('description'), $eventTemplate);

                    // {summary}
                    $eventTemplate = str_replace('{summary}', $event->getProperty('summary'), $eventTemplate);

                    // See if a location was set
                    $location = $event->getProperty('location');
                    if ($location != '') {
                        // {location}
                        $eventTemplate = str_replace('{location}', $location, $eventTemplate);

                        // {location_link}
                        // TODO other providers
                        $location_link = 'http://maps.google.com/maps?q=' . str_replace(' ', '+', str_replace(',', ' ', $location));
                        $eventTemplate = str_replace('{location_link}', '[[' . $location_link . '|' . $location . ']]', $eventTemplate);
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

                    $ret .= $eventTemplate;

                    // prepare summary link here
                    $summary_link           = array();
                    $summary_link['class']  = 'mediafile';
                    $summary_link['style']  = 'background-image: url(lib/plugins/icalevents/ics.png);';
                    $summary_link['pre']    = '';
                    $summary_link['suf']    = '';
                    $summary_link['more']   = 'rel="nofollow"';
                    $summary_link['target'] = '';
                    $summary_link['title']  = $event->getProperty('summary');
                    $summary_link['url']    = exportlink($ID, 'icalevents', array('uid' => rawurlencode($event->getProperty('uid'))));
                    $summary_link['name']   = $event->getProperty('summary');

                    $summary_links[] = $renderer->_formatLink($summary_link);
                }
            }

            $html = p_render($mode, p_get_instructions($ret), $info);
            $html = str_replace('\\n', '<br />', $html);

            // Replace {summary_link}s with the entries of $summary_links and concatenate to output.
            // We handle it here, because it is raw HTML generated by us, not DokuWiki syntax.
            $html = explode('{summary_link}', $html);
            for ($i = 0; $i < count($html); $i++) {
                $renderer->doc .= $html[$i];
                $renderer->doc .= $summary_links[$i];
            }
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
    * computes various values on a datetime array returned by iCalcreator
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
    * determines if an iCalcreator event is an all-day event
    */
    static function isAllDayEvent($event) {
        return !isset($event->getProperty('dtstart')['hour']);
    }
}
