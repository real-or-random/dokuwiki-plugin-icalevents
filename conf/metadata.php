<?php

$meta['dformat'] = array('string');
$meta['tformat'] = array('string');

$meta['locationUrlPrefix'] = array('multichoice',
    '_choices' => array(
        'https://maps.google.com/maps?q=',
        'http://open.mapquest.com/?q=',
        'https://www.bing.com/maps/?q=',
        '',
    )
);

// We abuse a multichoice field for following property.
// The checkbox is associated with the string ' ', and the user can additionally provide
// a string. If the checkbox is selected, we interpret the user-provided string as a custom
// prefix.
$meta['customLocationUrlPrefix'] = array('multicheckbox',
    '_choices' => array(' '),
);

$meta['default'] = array('');
$meta['list'] = array('');
$meta['table'] = array('');
$meta['table_without_description'] = array('');

/*
 * Add your own configuration for the showAs= syntax parameter
 *
 * Syntax:
 *   $meta['showAsLame'] = array('');
 *
 * Example:
 *   $meta['table'] = array('');
 *    will setup the configuration for showAs=table.
 *
 * You can set the default template for this parameter in default.php,
 * or set it up via the administration panel.
 */

