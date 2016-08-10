<?php
// Default configuration for iCalEvents plugin

// Date and time format
$conf['dformat'] = '';
$conf['tformat'] = '';

// Location URL prefix
$conf['locationUrlPrefix'] = 'https://maps.google.com/maps?q=';
$conf['customLocationUrlPrefix'] = '';

// Templates
$conf['template:default'] = '===== {date}: {summary} =====
**Location**: {location_link}\\\\
{description}';
$conf['template:list'] = '====== {date}: {summary} ======
**<sup>Location: {location}</sup>**\\\\
{description}';
$conf['template:table'] = '| **{date}**  | {summary_link}  | {location_link}  | (({description}))  |';
$conf['template:table_without_description'] = '| **{date}**  | {summary_link}  | {location_link}  |';
