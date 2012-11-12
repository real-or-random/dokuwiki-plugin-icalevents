<?php
# Default configuration for iCalEvent Dokuwiki Plugin

# Date format that is used to display the from and to values
# If you leave this empty '', then the default dformat from /conf/dokuwiki.php will be used.

$meta['dayformat'] = array('string');
$meta['timeformat'] = array('string');

# locale setting for setlocale
$meta['locale'] = array('string');

# should the end dates for each event be shown?
$meta['showEndDates'] = array(onoff);

# do you wnat the description parsed as an acronym?
$meta['list_desc_as_acronym']   = array(onoff);

# do you want one table per month instead of a huge eventsstable?
$meta['list_split_months']      = array(onoff);
$meta['hour_offset']      = array('string');
$meta['event_to_next_day']      = array(onoff);
