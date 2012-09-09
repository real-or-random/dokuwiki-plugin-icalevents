<?php
/**
 * Plugin iCalEvents: Renders an iCal .ics file as an HTML table.
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @version    2.0.2
 * @author     Robert Rackl <wiki@doogie.de>
 * @author     Elan Ruusamäe <glen@delfi.ee>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * This plugin gets an iCalendar file via HTTP and then
 * parses this file into an HTML table.
 *
 * Usage: {{iCalEvents>http://host/myCalendar.ics#from=today&previewDays=30}}
 *
 * You can filter the events that are shown with some arameters:
 * 1. 'from' a date from which on to show events. any text that strformat can accept
 *           for example "from=today".
 *           If 'from' is omitted, then all events are shown.
 *           http://www.php.net/manual/en/function.strtotime.php
 * 2. 'previewDays' amount of days to preview into the future.
 *           Default ist 60 days.
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
class syntax_plugin_icalevents extends DokuWiki_Syntax_Plugin
{ 
    // implement necessary Dokuwiki_Syntax_Plugin methods
    function getType() { return 'substition'; }
    function getSort() { return 42; }
    function connectTo($mode) { $this->Lexer->addSpecialPattern('\{\{iCalEvents>.*?\}\}',$mode,'plugin_icalevents'); }

    /**
     * parse parameters from the {{iCalEvents>...}} tag.
     * @return an array that will be passed to the renderer function
     */
    function handle($match, $state, $pos, &$handler) {
      $match = substr($match, 13, -2); // strip {{iCalEvents> from start and }} from end
      list($icsURL, $flagStr) = explode('#', $match, 2);
      parse_str($flagStr, $params);

      $from = null;
      if (!empty($params['from'])) {
          // unix timestamp: handle specially for backward compatability
          if (preg_match('/^\d+$/', $params['from'])) {
            $from = (int )$params['from'];
          } else {
            // anything that strtotime can parse: 'today', '1 week ago', etc
            $from = strtotime($params['from']);
          }
	  }

      if (!empty($params['previewDays'])) {
        $previewSec = $params['previewDays']*24*3600;
      } else {
        $previewSec = 60*24*3600;  # two month
      }
      
      # Take dateformat from params, or
      # If dateformat is set in plugin configuration ('dformat'), then use it.
      # Otherwise fall back to dokuwiki's default dformat from the global /conf/dokuwiki.php.
      if (!empty($params['dformat'])) {
        $dateFormat = $params['dformat'];
      } else {
        global $conf;
        $dateFormat = $this->getConf('dformat') ? $this->getConf('dformat') : $conf['dformat'];
      }

      $showEndDates = !empty($params['showEndDates']);
      $showCurrentWeek = !empty($params['showCurrentWeek']);
      
      #echo "url=$icsURL flags=$flagStr; from = $from;    previewSec = $previewSec; dateFormat=$dateFormat; showCurrentWeek=$showCurrentWeek<br/>";
      
      return array($icsURL, $from, $previewSec, $dateFormat, $showEndDates, $showCurrentWeek);
    }

    /**
     * loads the ics file via HTTP, parses it and renders an HTML table.
     */
    function render($mode, &$renderer, $data) {
      list($url, $from, $previewSec, $dateFormat, $showEndDates, $showCurrentWeek) = $data;
      $ret = '';
      if($mode == 'xhtml'){
	      # parse the ICS file
          $entries = $this->_parseIcs($url, $from, $previewSec, $dateFormat);
          if ($this->error) {
            $renderer->doc .= "Error in Plugin iCalEvents: ".$this->error;
            return true;
          }
          #loop over entries and create a table row for each one.
          $rowCount = 0;
          $ret .= '<table class="inline"><tr>'.
                  '<th>'.$this->getLang('when').'</th>'.
                  '<th>'.$this->getLang('what').'</th>'.
                  '<th>'.$this->getLang('description').'</th>'.
                  '<th>'.$this->getLang('where').'</th>'.
                  '</tr>'.NL;
          $weekStart = strtotime("last monday 00:00");
          $weekEnd = strtotime("next monday 12:00");
          foreach ($entries as $entry) {
            $rowCount++;
            $ret .= '<tr';
            if ($showCurrentWeek && ($entry['startunixdate'] >= $weekStart && $entry['endunixdate'] <= $weekEnd)) {
                $ret .= ' style="background-color: red !important"';
            }
            $ret .= '>';
			if ($showEndDates || $this->getConf('showEndDates')) {
				$ret .= '<td>'.$entry['startdate'].' - '.$entry['enddate'].'</td>';
			} else {
				$ret .= '<td>'.$entry['startdate'];
			}
            $ret .= '<td>'.$entry['summary'].'</td>';
            $ret .= '<td>'.$entry['description'].'</td>';
            $ret .= '<td>'.$entry['location'].'</td>';
            $ret .= '</tr>'.NL;
          }
          $ret .= '</table>';
          $renderer->doc .= $ret;
          return true;
      }
      return false;
    }

    /**
     * Load the iCalendar file from 'url' and parse all
     * events that are within the range
     * from <= eventdate <= from+previewSec
     *
     * @param url HTTP URL of an *.ics file
     * @param from unix timestamp in seconds (may be null)
     * @param previewSec preview range also in seconds
     * @return an array of entries sorted by their startdate
     */
    function _parseIcs($url, $from, $previewSec, $dateFormat) {
        // must reset error in case we have multiple calendars on page
        $this->error = false;

        $http    = new DokuHTTPClient();
        if (!$http->get($url)) {
          $this->error = "Could not get '$url': ".$http->status;
          return array();
        }
        $content    = $http->resp_body;
        $entries    = array();

        # regular expressions for items that we want to extract from the iCalendar file
        $regex_vevent      = '/BEGIN:VEVENT(.*?)END:VEVENT/s';
        $regex_summary     = '/SUMMARY:(.*?)\n/';
		$regex_location    = '/LOCATION:(.*?)\n/';

        # descriptions may be continued with a space at the start of the next line
        # BUGFIX: OR descriptions can be the last item in the VEVENT string
        $regex_description = '/DESCRIPTION:(.*?)\n([^ ]|$)/s';

		#normal events with time
		$regex_dtstart     = '/DTSTART.*?:([0-9]{4})([0-9]{2})([0-9]{2})T([0-9]{2})([0-9]{2})([0-9]{2})/';
		$regex_dtend       = '/DTEND.*?:([0-9]{4})([0-9]{2})([0-9]{2})T([0-9]{2})([0-9]{2})([0-9]{2})/';

		#all day event
        $regex_alldaystart = '/DTSTART;VALUE=DATE:([0-9]{4})([0-9]{2})([0-9]{2})/';
		$regex_alldayend   = '/DTEND;VALUE=DATE:([0-9]{4})([0-9]{2})([0-9]{2})/';


        #split the whole content into VEVENTs
        preg_match_all($regex_vevent, $content, $matches, PREG_PATTERN_ORDER);

        #loop over VEVENTs and parse out some itmes
        foreach ($matches[1] as $vevent) {

          $entry = array();
          if (preg_match($regex_summary, $vevent, $summary)) {
            $entry['summary'] = str_replace('\,', ',', $summary[1]);
          }
          if (preg_match($regex_dtstart, $vevent, $dtstart)) {
		    #                                hour          minute       second       month        day          year
            $entry['startunixdate'] = mktime($dtstart[4], $dtstart[5], $dtstart[6], $dtstart[2], $dtstart[3], $dtstart[1]);
			$entry['startdate']     = strftime($dateFormat, $entry['startunixdate']);
			preg_match($regex_dtend, $vevent, $dtend);
			$entry['endunixdate']   = mktime($dtend[4], $dtend[5], $dtend[6], $dtend[2], $dtend[3], $dtend[1]);
			$entry['enddate']       = strftime($dateFormat, $entry['endunixdate']);
			$entry['allday']        = false;
          }
          if (preg_match($regex_alldaystart, $vevent, $alldaystart)) {
            $entry['startunixdate'] = mktime(12, 0, 0, $alldaystart[2], $alldaystart[3], $alldaystart[1]);
			$entry['startdate']     = strftime($dateFormat, $entry['startunixdate']);
            preg_match($regex_alldayend, $vevent, $alldayend);
			$entry['endunixdate']   = mktime(12, 0, 0, $alldayend[2], $alldayend[3], $alldayend[1]);
			$entry['enddate']       = strftime($dateFormat, $entry['endunixdate']);
            $entry['allday']        = true;
          }

          # if entry is to old then filter it
          if ($from && $entry['startunixdate']) {
            if ($entry['startunixdate'] < $from) { continue; }
            if ($previewSec && ($entry['startunixdate'] > time()+$previewSec)) { continue; }
          }
          # also filter PalmPilot internal stuff
          if (preg_match('/@@@/', $entry['description'])) { continue; }

          if (preg_match($regex_description, $vevent, $description)) {
            $entry['description'] = $this->_parseDesc($description[1]);
          }
          if (preg_match($regex_location, $vevent, $location)) {
            $entry['location'] = str_replace('\,', ',', $location[1]);
          }

          #msg('adding <pre>'.print_r($vevent, true)."\n\n as \n\n".print_r($entry,true).'</pre>');
          $entries[] = $entry;
        }

        #sort entries by startunixdate
        usort($entries, 'compareByStartUnixDate');

        #echo '<pre>';
        #print_r($matches);
        #echo '</pre>';

        return $entries;
    }

    /**

     * Clean description text and render HTML links.
     * In an ics file the description may span over multiple lines.
     * Subsequent lines are indented by one space.
     * And the comma character is escaped.
     * DokuWiki Links <code>[[http://www.domain.de|linktext]]</code> will be rendered to HTML links.
     */
    function _parseDesc($str) {
      $str = str_replace('\,', ',', $str);
      $str = preg_replace("/[\n\r] ?/","",$str);
      $str = preg_replace("/\[\[(.*?)\|(.*?)\]\]/", "<a href=\"$1\">$2</a>", $str);
      $str = preg_replace("/\[\[(.*?)\]\]/e", "html_wikilink('$1')", $str);
      return $str;
    }
}

/** compares two entries by their startunixdate value */
function compareByStartUnixDate($a, $b) {
  return strnatcmp($a['startunixdate'], $b['startunixdate']);
}
