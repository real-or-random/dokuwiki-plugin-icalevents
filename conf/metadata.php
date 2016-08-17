<?php

$meta['dformat'] = array('string');
$meta['tformat'] = array('string');

$meta['locationUrlPrefix'] = array('multichoice',
    '_choices' => array(
        'https://maps.google.com/maps?q=',
        'https://www.openstreetmap.com/?query=',
        'https://www.bing.com/maps/?q=',
        '',
    )
);
$meta['customLocationUrlPrefix'] = array('string');

$meta['template:default'] = array('');
$meta['template:list'] = array('');
$meta['template:table'] = array('');
$meta['template:table_without_description'] = array('');
$meta['template:custom1'] = array('');
$meta['template:custom2'] = array('');
$meta['template:custom3'] = array('');
