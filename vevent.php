<?php
/**
 * Copyright (C) 2011 Jannes Drost-Tenfelde
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.html GPL2
 * @author     Jannes Drost-Tenfelde <info@drost-tenfelde.de>
 *
 */

include_once('functions.php');

$vevent = urldecode($_GET['vevent']);

$output  = "BEGIN:VCALENDAR\r\n";
// TODO wrong prodid
$output .= "PRODID: -//Google Inc//Google Calendar 70.9054//EN" . "\r\n";
$output .= "VERSION:2.0\r\n";
$output .= $vevent;
$output .= "END:VCALENDAR\r\n";

// Get the event parameters
$entry = parse_vevent($vevent);

// Make a filename
$filename = 'iCalendar_';

if ($entry['allday'] ) {
    if ($entry['startdate'] != $entry['enddate']) {
        $filename .= $entry['startdate'] . '_' . $entry['enddate'];
    } else {
        $filename .= $entry['startdate'];
    }
}
else {
    if ($entry['startdate'] != $entry['enddate']) {
        $filename .= $entry['startdate'] . $entry['starttime'] . '_' . $entry['enddate'] . $entry['endtime'];
    } else {
        $filename .= $entry['startdate'] . '_' . $entry['starttime'] . '_' . $entry['endtime'];
    }
}
$filename .= '.ics';
$filename = str_replace(':', '', $filename);

// Output the file
header('Content-Type: text/calendar');
header('Content-Disposition: attachment; filename=' . $filename);
echo $output;

