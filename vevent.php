<?php

include_once( 'functions.php');

$vevent =  urldecode( $_GET['vevent'] );
$output  = "BEGIN:VCALENDAR\r\n";
$output .= "PRODID: -//Google Inc//Google Calendar 70.9054//EN"."\r\n";
$output .= "VERSION:2.0\r\n";
$output .= "METHOD:PUBLISH"."\r\n";
$output .= $vevent; 
$output .= "END:VCALENDAR\r\n";		

// Get the event parameters
$entry = parse_vevent( $vevent );

// Make a filename
$filename = 'iCalendar_';

if ( $entry['allday'] )
{
    if ( $entry['startdate'] != $entry['enddate'] )
    {
        $filename .= $entry['startdate'].'_'.$entry['enddate'];
    }
    else {
        $filename .= $entry['startdate'];
    }
}
else {
    if ( $entry['startdate'] != $entry['enddate'] )
    {
        $filename .= $entry['startdate'].$entry['starttime'].'_'.$entry['enddate'].$entry['endtime'];
    }
    else {
        $filename .= $entry['startdate'].'_'.$entry['starttime'].'_'.$entry['endtime'];
    }
}
$filename .= '.ics';
$filename = str_replace(":", "", $filename );

// Output the file
header('Content-Type: text/Calendar');
header('Content-Disposition: attachment; filename='.$filename);
echo $output;

?>