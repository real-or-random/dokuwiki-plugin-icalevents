<?php
# Default configuration for iCalEvent Dokuwiki Plugin

# Date format that is used to display the from and to values
# If you leave this empty '', then the default dformat from /conf/dokuwiki.php will be used.

$lang['dayformat'] = "Format string used for formatting the event days";
$lang['timeformat'] = "Format strung used for formatting the event times";

# locale setting for setlocale
$lang['locale'] = "Locale string for months names in the correct laguage";

# should the end dates for each event be shown?
$lang['showEndDates'] = "Should the end dates and times be shown?";

# do you wnat the description parsed as an acronym?
$lang['list_desc_as_acronym']   = "Should the event description be shown as acronym of the events summary? (Reduces width of table)";

# do you want one table per month instead of a huge eventsstable?
$lang['list_split_months']      = "Split the event list in months";
$lang['hour_offset']      = "Adds/subtracts the given ammount ob hours from events times (fix issues with timezones and/or DST)";
$lang['event_to_next_day']      = "One-day events on goggle calendar are longing into the next day. This option fixes this behaviour";
