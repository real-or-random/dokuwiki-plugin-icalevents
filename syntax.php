<?php
/**
 * Plugin iCalEvents: Renders an iCal .ics file as an HTML table.
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @version 2.0.2
 * @author  Robert Rackl <wiki@doogie.de>
 * @author  Elan Ruusamäe <glen@delfi.ee>
 * @author  J. Drost-Tenfelde <info@drost-tenfelde.de>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC'))
    die();

if (!defined('DOKU_PLUGIN'))
    define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

require_once DOKU_PLUGIN . 'syntax.php';
require_once DOKU_INC . 'lib/plugins/icalevents/functions.php';

/**
 * This plugin gets an iCalendar file via HTTP and then
 * parses this file into an HTML table.
 *
 * Usage: {{iCalEvents>http://host/myCalendar.ics#from=today&previewDays=30}}
 *
 * You can filter the events that are shown with some parameters:
 * 1. 'from' a date from which on to show events. any text that strformat can accept
 *           for example "from=today".
 *           If 'from' is omitted, then all events are shown.
 *           http://www.php.net/manual/en/function.strtotime.php
 * 2. 'previewDays' amount of days to preview into the future.
 *           Default is 60 days.
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
        return 42;
    }
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('\{\{iCalEvents>.*?\}\}', $mode, 'plugin_icalevents');
    }

    /**
     * parse parameters from the {{iCalEvents>...}} tag.
     * @return an array that will be passed to the renderer function
     */
    function handle($match, $state, $pos, &$handler) {
        $match = substr($match, 13, -2); // strip {{iCalEvents> from start and }} from end
        list($icsURL, $flagStr) = explode('#', $match, 2);
        parse_str($flagStr, $params);

        // Get the from parameter
        if ($params['from'] == 'today') {
            $from = 'today';
        } elseif (preg_match('#(\d\d)/(\d\d)/(\d\d\d\d)#', $params['from'], $fromDate)) {
            // must be MM/dd/yyyy
            $from = mktime(0, 0, 0, $fromDate[1], $fromDate[2], $fromDate[3]);
        } elseif (preg_match('/\d+/', $params['from'])) {
            $from = $params['from'];
        }
        // Get the to parameter
        if ($params['to'] == 'today') {
            $to = 'today';

        } elseif (preg_match('#(\d\d)/(\d\d)/(\d\d\d\d)#', $params['to'], $toDate)) {
            // must be MM/dd/yyyy
            $to = mktime(0, 0, 0, $toDate[1], $toDate[2], $toDate[3]);
        } elseif (preg_match('/\d+/', $params['to'])) {
            $to = $params['to'];
        }

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
        $sort_descending = false;
        if ($params['sort'] == 'DESC') {
            $sort_descending = true;
        }

        // Get the previewDays parameter
        if ($params['previewDays']) {
            $previewDays = $params['previewDays'];
        } else {
            $previewDays = -1;
        }

        //echo "url=$icsURL from = $from    numberOfEntries = $numberOfEntries<br>";
        return array(
            $icsURL,
            $from,
            $to,
            $previewDays,
            $numberOfEntries,
            $showEndDates,
            $template,
            $sort_descending
        );
    }

    /**
     * loads the ics file via HTTP, parses it and renders an HTML table.
     */
    function render($mode, &$renderer, $data) {
        list($url, $from, $to, $previewDays, $numberOfEntries, $showEndDates, $template, $sort_descending) = $data;
        if ($from == 'today') {
            $from = time();
        }
        if ($to == 'today') {
            $to = mktime(24, 0, 0, date("m"), date("d"), date("Y"));
        }

        list($url, $from, $previewSec, $dateFormat, $showEndDates, $showCurrentWeek) = $data;
        $ret = '';
        if ($mode == 'xhtml') {
            // parse the ICS file
            $entries = $this->parseIcs($url, $from, $to, $previewDays, $numberOfEntries, $sort_descending);

            if ($this->error) {
                $renderer->doc .= "Error in Plugin iCalEvents: " . $this->error;
                return true;
            }

            //loop over entries and create a table row for each one.
            $rowCount = 0;

            foreach ($entries as $entry) {
                $rowCount++;

                // Get the html for the entries
                $entryTemplate = $template;

                // {description}
                $entryTemplate = str_replace('{description}', $entry['description'], $entryTemplate);

                // {summary}
                $entryTemplate = str_replace('{summary}', $entry['summary'], $entryTemplate);

                // {summary_link}
                $summary_link           = array();
                $summary_link['class']  = 'urlintern';
                $summary_link['style']  = 'background-image: url(lib/plugins/icalevents/ics.png); background-repeat:no-repeat; padding-left:16px; text-decoration: none;';
                $summary_link['pre']    = '';
                $summary_link['suf']    = '';
                $summary_link['more']   = 'rel="nofollow"';
                $summary_link['target'] = '';
                $summary_link['title']  = $entry['summary'];
                $summary_link['url']    = 'lib/plugins/icalevents/vevent.php?vevent=' . urlencode($entry['vevent']);
                $summary_link['name']   = $entry['summary'];
                // TODO render to raw html immediately to avoid requiring that "htmlok" is set
                $entryTemplate          = str_replace('{summary_link}', '<html>' . $renderer->_formatLink($summary_link) . '</html>', $entryTemplate);

                // See if a location was set
                $location = $entry['location'];
                if ($location != '') {
                    // {location}
                    $entryTemplate = str_replace('{location}', $location, $entryTemplate);

                    // {location_link}
                    // TODO other providers
                    $location_link = 'http://maps.google.com/maps?q=' . str_replace(' ', '+', str_replace(',', ' ', $location));
                    $entryTemplate = str_replace('{location_link}', '[[' . $location_link . '|' . $location . ']]', $entryTemplate);
                } else {
                    // {location}
                    $entryTemplate = str_replace('{location}', 'Unknown', $entryTemplate);
                    // {location_link}
                    $entryTemplate = str_replace('{location_link}', 'Unknown', $entryTemplate);
                }

                $dateString = "";

                // Get the start and end day
                $startDay = date("Ymd", $entry['startunixdate']);
                $endDay   = date("Ymd", $entry['endunixdate']);

                if ($endDay > $startDay) {
                    if ($entry['allday']) {
                        $dateString = $entry['startdate'] . '-' . $entry['enddate'];
                    } else {
                        $dateString = $entry['startdate'] . ' ' . $entry['starttime'] . '-' . $entry['enddate'] . ' ' . $entry['endtime'];
                    }
                } else {
                    if ($showEndDates) {
                        if ($entry['allday']) {
                            $dateString = $entry['startdate'];
                        } else {
                            $dateString = $entry['startdate'] . ' ' . $entry['starttime'] . '-' . $entry['endtime'];
                        }
                    } else {
                        $dateString = $entry['startdate'];
                    }
                }

                // {date}
                $entryTemplate = str_replace('{date}', $dateString, $entryTemplate);

                $ret .= $entryTemplate . '
';

            }
            // 			$renderer->doc .= $ret;
            $html = p_render($mode, p_get_instructions($ret), $info);
            $html = str_replace('\\n', '<br />', $html);
            $renderer->doc .= $html;

            return true;
        }
        return false;
    }

    /**
     * Load the iCalendar file from 'url' and parse all
     * events that are within the range
     * from <= eventdate <= from+previewSec
     *
     * @param  url HTTP URL of an *.ics file
     * @param  from unix timestamp in seconds (may be null)
     * @param  to unix timestamp in seconds (may be null)
     * @param  previewDays Limit the entries to 30 days in the future
     * @param  numberOfEntries Number of entries to display
     * @param  $sort_descending
     * @return an array of entries sorted by their startdate
     */
    protected function parseIcs($url, $from, $to, $previewDays, $numberOfEntries, $sort_descending) {
        global $conf;

        $http = new DokuHTTPClient();
        if (!$http->get($url)) {
            $this->error = "Could not get '$url': " . $http->status;
            return array();
        }
        $content = $http->resp_body;
        $entries = array();

        // If dateformat is set in plugin configuration ('dformat'), then use it.
        // Otherwise fall back to dokuwiki's default dformat from the global /conf/dokuwiki.php.
        $dateFormat = $this->getConf('dformat') ? $this->getConf('dformat') : $conf['dformat'];
        //$timeFormat = $this->getConf('tformat') ? $this->getConf('tformat') : $conf['tformat'];

        // regular expressions for items that we want to extract from the iCalendar file
        $regex_vevent = '/BEGIN:VEVENT(.*?)END:VEVENT/s';

        //split the whole content into VEVENTs
        preg_match_all($regex_vevent, $content, $matches, PREG_PATTERN_ORDER);

        if ($previewDays > 0) {
            $previewSec = $previewDays * 24 * 3600;
        } else {
            $previewSec = -1;
        }

        // loop over VEVENTs and parse out some items
        foreach ($matches[1] as $vevent) {
            $entry = parse_vevent($vevent, $dateFormat);

            // if entry is to old then filter it
            if ($from && $entry['endunixdate']) {
                if ($entry['endunixdate'] < $from) {
                    continue;
                }
                if (($previewSec > 0) && ($entry['startunixdate'] > time() + $previewSec)) {
                    continue;
                }
            }

            // if entry is to new then filter it
            if ($to && $entry['startunixdate']) {
                if ($entry['startunixdate'] > $to) {
                    continue;
                }
            }

            $entries[] = $entry;
        }

        if ($to && ($from == null)) {
            // sort entries by startunixdate
            usort($entries, 'compareByEndUnixDate');
        } elseif ($from) {
            // sort entries by startunixdate
            usort($entries, 'compareByStartUnixDate');
        } elseif ($sort_descending) {
            $entries = array_reverse($entries, true);
        }

        // See if a maximum number of entries was set
        if ($numberOfEntries > 0) {
            $entries = array_slice($entries, 0, $numberOfEntries);

            // Reverse array?
            if ($from && $sort_descending) {
                $entries = array_reverse($entries, true);
            } elseif ($to && !$from && (!$sort_descending)) {
                $entries = array_reverse($entries, true);
            }
        }

        return $entries;
    }
}

/**
 * compares two entries by their startunixdate value
 */
function compareByStartUnixDate($a, $b) {
    return strnatcmp($a['startunixdate'], $b['startunixdate']);
}

/**
 * compares two entries by their startunixdate value
 */
function compareByEndUnixDate($a, $b) {
    return strnatcmp($b['endunixdate'], $a['endunixdate']);
}
