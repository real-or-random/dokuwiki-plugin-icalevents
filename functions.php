<?php

/**
 * Returns the offset from the origin timezone to the remote timezone, in seconds.
 *
 * @param remote_tz
 * @param origin_tz If null the servers current timezone is used as the origin.
 *
 * @return int
 */
function get_timezone_offset($remote_tz, $timestamp, $origin_tz = null) {
    if ($origin_tz === null) {
        if (!is_string($origin_tz = date_default_timezone_get())) {
            return false; // A UTC timestamp was returned -- bail out!
        }
    }
    $origin_dtz = new DateTimeZone($origin_tz);
    $remote_dtz = new DateTimeZone($remote_tz);

    $origin_dt = new DateTime("@$timestamp", $origin_dtz);
    $remote_dt = new DateTime("@$timestamp", $remote_dtz);

    $offset = $origin_dtz->getOffset($origin_dt) - $remote_dtz->getOffset($remote_dt);
    return $offset;
}

/**
 * Parses a VEVENT into an array with fields.
 *
 * @param vevent VEVENT
 * @param dateFormat dateformat for displaying the start and end date
 *
 * @return array()
 */
function parse_vevent($vevent, $dateFormat = "%Y-%m-%d", $timeFormat = "%H:%M") {
    // Regex for the different fields
    $regex_summary  = '/SUMMARY:(.*?)\n/';
    $regex_location = '/LOCATION:(.*?)\n/';

    // descriptions may be continued with a space at the start of the next line
    // BUGFIX: OR descriptions can be the last item in the VEVENT string
    $regex_description = '/DESCRIPTION:(.*?)\n([^ ]|$)/s';

    // normal events with time
    $regex_dtstart = '/DTSTART.*?:([0-9]{4})([0-9]{2})([0-9]{2})T([0-9]{2})([0-9]{2})([0-9]{2})/';
    $regex_dtend   = '/DTEND.*?:([0-9]{4})([0-9]{2})([0-9]{2})T([0-9]{2})([0-9]{2})([0-9]{2})/';

    // Timezones can be passed for individual dtstart and dtend by the ics
    $regex_dtstart_timezone = '/DTSTART;TZID=(.*?):([0-9]{4})([0-9]{2})([0-9]{2})T([0-9]{2})([0-9]{2})([0-9]{2})/';
    $regex_dtend_timezone   = '/DTEND;TZID=(.*?):([0-9]{4})([0-9]{2})([0-9]{2})T([0-9]{2})([0-9]{2})([0-9]{2})/';

    // all day event
    $regex_alldaystart = '/DTSTART;VALUE=DATE:([0-9]{4})([0-9]{2})([0-9]{2})/';
    $regex_alldayend   = '/DTEND;VALUE=DATE:([0-9]{4})([0-9]{2})([0-9]{2})/';

    // Make the entry
    $entry = array();
    $entry['vevent'] = "BEGIN:VEVENT\r\n" . $vevent . "END:VEVENT\r\n";
    // Get the summary
    if (preg_match($regex_summary, $vevent, $summary)) {
        $entry['summary'] = str_replace('\,', ',', $summary[1]);
    }

    // Get the starting time timezone
    if (preg_match($regex_dtstart_timezone, $vevent, $timezone)) {
        $start_timezone = $timezone[1];
    } else {
        $start_timezone = 'UTC';
    }

    // Get the end time timezone
    if (preg_match($regex_dtend_timezone, $vevent, $timezone)) {
        $end_timezone = $timezone[1];
    } else {
        $end_timezone = 'UTC';
    }

    // Get the start and end times
    if (preg_match($regex_dtstart, $vevent, $dtstart)) {
        //                                hour          minute       second       month        day          year
        $entry['startunixdate'] = mktime($dtstart[4], $dtstart[5], $dtstart[6], $dtstart[2], $dtstart[3], $dtstart[1]);
        //Calculate the timezone offset

        $start_timeOffset = get_timezone_offset($start_timezone, $entry['startunixdate']);

        $entry['startunixdate'] = $entry['startunixdate'] + $start_timeOffset;
        $entry['startdate']     = strftime($dateFormat, $entry['startunixdate']);
        $entry['starttime']     = strftime($timeFormat, $entry['startunixdate']);

        preg_match($regex_dtend, $vevent, $dtend);
        // FIXME undefined variable $timeOffset
        $entry['endunixdate'] = mktime($dtend[4], $dtend[5], $dtend[6], $dtend[2], $dtend[3], $dtend[1]) + $timeOffset;

        $end_timeOffset = get_timezone_offset($end_timezone, $entry['endunixdate']);
        $entry['endunixdate'] = $entry['endunixdate'] + $end_timeOffset;

        $entry['enddate'] = strftime($dateFormat, $entry['endunixdate']);
        $entry['endtime'] = strftime($timeFormat, $entry['endunixdate']);
        $entry['allday']  = false;
    }
    if (preg_match($regex_alldaystart, $vevent, $alldaystart)) {
        $entry['startunixdate'] = mktime(0, 0, 0, $alldaystart[2], $alldaystart[3], $alldaystart[1]);
        // Calculate the timezone offset
        $start_timeOffset       = get_timezone_offset($start_timezone, $entry['startunixdate']);

        $entry['startunixdate'] = $entry['startunixdate'] + $start_timeOffset;
        $entry['startdate']     = strftime($dateFormat, $entry['startunixdate']);

        preg_match($regex_alldayend, $vevent, $alldayend);

        $entry['endunixdate'] = mktime(0, 0, 0, $alldayend[2], $alldayend[3], $alldayend[1]);
        $end_timeOffset       = get_timezone_offset($end_timezone, $entry['endunixdate']);
        $entry['endunixdate'] = $entry['endunixdate'] + $end_timeOffset - 1;
        $entry['enddate']     = strftime($dateFormat, $entry['endunixdate']);
        $entry['allday']      = true;
    }

    // also filter PalmPilot internal stuff
    // FIXME continue without loop?! $entry['description'] not defined
    if (preg_match('/@@@/', $entry['description'])) {
        continue;
    }

    if (preg_match($regex_description, $vevent, $description)) {
        $entry['description'] = $description[1];
        $entry['description'] = preg_replace("/[\r\n] ?/", '', $entry['description']);
        $entry['description'] = str_replace('\,', ',', $entry['description']);
    }
    if (preg_match($regex_location, $vevent, $location)) {
        $entry['location'] = str_replace('\,', ',', $location[1]);
    }
    return $entry;
}

