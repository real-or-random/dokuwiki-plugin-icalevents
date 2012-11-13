<?php
# Default configuration for iCalEvent Dokuwiki Plugin

# Format string to format the date without the time of an event
$conf['dayformat'] = "%d.%m.%y";
# Format string to format the time part of an event
$conf['timeformat'] = "%H:%M";
# locale definition for setlocale
$conf['locale'] = '';
# should the end dates for each event be shown?
$conf['showEndDates'] = 0;
# do you wnat the description parsed as an acronym?
$conf['list_desc_as_acronym']   = false;
# do you want one table per month instead of a huge eventsstable?
$conf['list_split_months']      = false;
# give manual offset to add/remove from the events hour
$conf['hour_offset'] = 0;
# fix for google calendar spanning a one day event to the next day
$meta['event_to_next_day'] = false;
