<?php
# Default configuration for iCalEvent Dokuwiki Plugin

# Format string to format the date without the time of an event
$meta['dayformat'] = array('string');
# Format string to format the time part of an event
$meta['timeformat'] = array('string');
# locale setting for setlocale
$meta['locale'] = array('string');
# should the end dates for each event be shown?
$meta['showEndDates'] = array('onoff');
# do you wnat the description parsed as an acronym?
$meta['list_desc_as_acronym'] = array('onoff');
# do you want one table per month instead of a huge eventsstable?
$meta['list_split_months'] = array('onoff');
# give manual offset to add/remove from the events hour
$meta['hour_offset'] = array('string');
# fix for google calendar spanning a one day event to the next day
$meta['event_to_next_day'] = array('onoff');
