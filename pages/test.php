<?php
namespace Stanford\BirthdayGreeting;
/** @var AnnualAlert $module */


echo "testing BirthdayGreeting";



$pid = 328;
$settings = array(
    'enable-greeting' => true,
    'template-title' => 'TEMPLATE_BirthdayGreeting',
'birthday-field' => 'birthday',
'birthday-field-event-name' => '2142',
'send-time' => 9,
    'sent-ts-field' => 'sent_timestamp',
'stop-logic-field' =>null
);

$module->sendGreeting($pid, $settings);


