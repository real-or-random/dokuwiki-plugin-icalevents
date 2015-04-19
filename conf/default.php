<?php
// Default configuration for iCalEvents plugin

/*
 * Date format that is used to display the from and to values.
 * If you leave this empty '', then the default dformat from /conf/dokuwiki.php will be used.
 */
$conf['dformat'] = '%d %B %Y';

/*
 * Time format that is used to display the from and to values.
 * If you leave this empty '', then the default tformat from /conf/dokuwiki.php will be used.
 */
$conf['tformat'] = '%H:%M';

// should the end dates for each event be shown?
$conf['showEndDates'] = 0;

// URL prefix for links to information about location (e.g., a map)
$conf['locationUrlPrefix'] = 'https://maps.google.com/maps?q=';
$conf['customLocationUrlPrefix'] = '';

// Templates

// showAs = default
$conf['default'] = '===== {date}: {summary} =====

**Location**: {location_link}\\\\
{description}';

// showAs = list
$conf['list'] = '====== {date}: {summary} ======
**<sup>Location: {location}</sup>**\\\\
{description}';

//showAs = table
$conf['table'] = '| **{date}**  | {summary_link}  | {location_link}  | (({description}))  |';

/*
 * You can add your own showAs= templates by adding a configuration parameter
 * Example:
 *
 * $conf['unsortedlist'] = '  * {date}: {summary} ';
 *
 * will allow you to use 'showAs=unsortedlist' in your iCalendar syntax.
 *
 * If you wish to configre the templates in your administration panel as well,
 * please update the metadata.php file with your new parameter as well.
 */
