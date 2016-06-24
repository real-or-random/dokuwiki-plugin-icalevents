<?php
// Default configuration for iCalEvents plugin

// Date format used to display events.
$conf['dformat'] = '';
// Time format used to display events.
$conf['tformat'] = '';

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

//showAs = table_without_description
$conf['table_without_description'] = '| **{date}**  | {summary_link}  | {location_link}  |';

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
